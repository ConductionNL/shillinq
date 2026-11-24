<?php

/**
 * Ib47BatchService
 *
 * Prepares the annual IB47 batch of an administration from its books.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service\Ib47
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ib47;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\Contracts\Ib47BatchServiceInterface;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * The annual IB47 batch, filled from the payments to third parties.
 *
 * WHAT COUNTS AS PAID. A supplier invoice leaves the books through a payment
 * run, and a run is paid once the bank statement confirmed it, which is the
 * run's `reconciled` state. Each run line names the payee and the amount, and
 * the day it left the bank is the line's requested execution date or else the
 * run's. So the year a payment belongs to is that day's year, and an approved
 * or exported run that the bank has not confirmed yet counts for nothing.
 *
 * ONE RECORD PER PAYEE AND YEAR. A record names the payee it was prepared
 * from (`payeeId`), and a second prepare of the same year finds it and writes
 * the new total into it. A payee whose payments dropped to nothing keeps its
 * record at zero rather than vanishing, so the batch says what changed.
 *
 * NOTHING IS MADE UP. The statement names the recipient's BSN, address and
 * payment type code. A recipient without one of them is left out and named in
 * `incomplete` with what is missing; writing a record with an empty BSN or a
 * code nobody chose would put a claim on the statement that nobody made.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ib47
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class Ib47BatchService implements Ib47BatchServiceInterface {

	/**
	 * The schema of one IB47 record.
	 */
	public const RECORD_SCHEMA = 'IB47Record';

	/**
	 * The schema of a payee.
	 */
	public const PAYEE_SCHEMA = 'Payee';

	/**
	 * The schema of a payment run.
	 */
	public const RUN_SCHEMA = 'PaymentRun';

	/**
	 * The run state that means the bank confirmed the payments.
	 */
	public const PAID_RUN_STATE = 'reconciled';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083 rule 1).
	 * @param IAppConfig             $appConfig     App config, for the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Prepare the IB47 batch of one administration and tax year.
	 *
	 * @param string $administrationId The administration that made the payments.
	 * @param int    $taxYear          The tax year.
	 *
	 * @return array<string, mixed> `taxYear`, `administrationId`, `prepared` (per payee: payeeId,
	 *                              recipientName, paymentsTotal, recordId, created) and `incomplete`
	 *                              (per payee: payeeId, name, missing fields).
	 *
	 * @throws InvalidArgumentException When the administration is empty or the year is out of range.
	 *
	 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#requirement-the-annual-ib47-batch-is-prepared-from-the-payments-to-third-parties-req-dpa-021
	 */
	public function prepare(string $administrationId, int $taxYear): array {
		$administrationId = trim($administrationId);
		if ($administrationId === '') {
			throw new InvalidArgumentException('An IB47 batch needs the administration that made the payments.');
		}

		if ($taxYear < 2000 || $taxYear > 2100) {
			throw new InvalidArgumentException('An IB47 batch needs a tax year between 2000 and 2100.');
		}

		$paid     = $this->paidCentsPerPayee(administrationId: $administrationId, taxYear: $taxYear);
		$existing = $this->recordsByPayee(administrationId: $administrationId, taxYear: $taxYear);

		$prepared   = [];
		$incomplete = [];
		foreach ($this->recipients(administrationId: $administrationId) as $payee) {
			$payeeId = (string)($payee['id'] ?? '');
			$cents   = $this->centsFor(payee: $payee, paid: $paid);
			$record  = ($existing[$payeeId] ?? null);
			if ($cents === 0 && $record === null) {
				continue;
			}

			$missing = $this->missing(payee: $payee);
			if ($missing !== []) {
				$incomplete[] = ['payeeId' => $payeeId, 'name' => (string)($payee['name'] ?? ''), 'missing' => $missing];
				continue;
			}

			$prepared[] = $this->write(
				payee: $payee,
				record: $record,
				administrationId: $administrationId,
				taxYear: $taxYear,
				cents: $cents
			);
		}//end foreach

		return [
			'taxYear' => $taxYear,
			'administrationId' => $administrationId,
			'prepared' => $prepared,
			'incomplete' => $incomplete,
		];
	}//end prepare()

	/**
	 * What each payee was paid in the year, in cents, keyed by the payee
	 * reference the run lines carry.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $taxYear          The tax year.
	 *
	 * @return array<string, int> Cents per payee reference.
	 */
	private function paidCentsPerPayee(string $administrationId, int $taxYear): array {
		$runs = $this->find(
			schema: self::RUN_SCHEMA,
			filters: ['administrationId' => $administrationId, 'lifecycleState' => self::PAID_RUN_STATE]
		);

		$year = (string)$taxYear;
		$paid = [];
		foreach ($runs as $run) {
			if ((string)($run['lifecycleState'] ?? '') !== self::PAID_RUN_STATE
				|| (string)($run['administrationId'] ?? '') !== $administrationId
			) {
				continue;
			}

			$lines = $run['paymentLines'] ?? [];
			if (is_array($lines) === false) {
				continue;
			}

			foreach ($lines as $line) {
				if (is_array($line) === false) {
					continue;
				}

				$payeeRef = trim((string)($line['payeeId'] ?? ''));
				$day      = (string)($line['requestedExecutionDate'] ?? '');
				if ($day === '') {
					$day = (string)($run['executionDate'] ?? '');
				}

				if ($payeeRef === '' || substr($day, 0, 4) !== $year) {
					continue;
				}

				$paid[$payeeRef] = (($paid[$payeeRef] ?? 0) + (int)round(((float)($line['amount'] ?? 0)) * 100));
			}
		}//end foreach

		return $paid;
	}//end paidCentsPerPayee()

	/**
	 * The year's final (not dry-run) records, keyed by the payee they name.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $taxYear          The tax year.
	 *
	 * @return array<string, array<string, mixed>> Record per payee id.
	 */
	private function recordsByPayee(string $administrationId, int $taxYear): array {
		$records = $this->find(
			schema: self::RECORD_SCHEMA,
			filters: ['administrationId' => $administrationId, 'taxYear' => $taxYear]
		);

		$byPayee = [];
		foreach ($records as $record) {
			$payeeId = (string)($record['payeeId'] ?? '');
			if ($payeeId === ''
				|| ($record['isDryRun'] ?? false) === true
				|| (int)($record['taxYear'] ?? 0) !== $taxYear
				|| (string)($record['administrationId'] ?? '') !== $administrationId
			) {
				continue;
			}

			$byPayee[$payeeId] = $record;
		}

		return $byPayee;
	}//end recordsByPayee()

	/**
	 * The administration's payees marked as IB47 recipients.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>> The payees, each with its `id`.
	 */
	private function recipients(string $administrationId): array {
		$payees = $this->find(
			schema: self::PAYEE_SCHEMA,
			filters: ['administrationId' => $administrationId, 'ib47Recipient' => true]
		);

		return array_values(
			array_filter(
				$payees,
				static fn (array $payee): bool => ($payee['ib47Recipient'] ?? false) === true
					&& (string)($payee['administrationId'] ?? '') === $administrationId
					&& (string)($payee['id'] ?? '') !== ''
			)
		);
	}//end recipients()

	/**
	 * What one payee was paid: a run line names a payee by its uuid or its slug.
	 *
	 * @param array<string, mixed> $payee The payee.
	 * @param array<string, int>   $paid  Cents per payee reference.
	 *
	 * @return int Cents.
	 */
	private function centsFor(array $payee, array $paid): int {
		$refs = array_unique(
			array_filter(
				[
					(string)($payee['id'] ?? ''),
					(string)($payee['@self']['slug'] ?? ''),
					(string)($payee['slug'] ?? ''),
				],
				static fn (string $ref): bool => $ref !== ''
			)
		);

		$cents = 0;
		foreach ($refs as $ref) {
			$cents += ($paid[$ref] ?? 0);
		}

		return $cents;
	}//end centsFor()

	/**
	 * What the statement needs of a payee and the payee does not have.
	 *
	 * @param array<string, mixed> $payee The payee.
	 *
	 * @return list<string> The missing fields, empty when complete.
	 */
	private function missing(array $payee): array {
		$missing = [];
		if (trim((string)($payee['name'] ?? '')) === '') {
			$missing[] = 'name';
		}

		if (preg_match('/^[0-9]{9}$/', (string)($payee['bsn'] ?? '')) !== 1) {
			$missing[] = 'bsn';
		}

		if ($this->address(payee: $payee) === '') {
			$missing[] = 'address';
		}

		if (in_array((string)($payee['ib47PaymentTypeCode'] ?? ''), ['1', '2', '3', '4', '5', '6', '7', '8', '9'], true) === false) {
			$missing[] = 'ib47PaymentTypeCode';
		}

		return $missing;
	}//end missing()

	/**
	 * The payee's postal address as one line, or '' without street and city.
	 *
	 * @param array<string, mixed> $payee The payee.
	 *
	 * @return string Such as "Voorbeeldstraat 1, 1234AB Amsterdam".
	 */
	private function address(array $payee): string {
		$address = $payee['address'] ?? null;
		if (is_array($address) === false) {
			return '';
		}

		$street = trim(trim((string)($address['street'] ?? '')) . ' ' . trim((string)($address['houseNumber'] ?? '')));
		$city   = trim(trim((string)($address['postcode'] ?? '')) . ' ' . trim((string)($address['city'] ?? '')));
		if ($street === '' || trim((string)($address['city'] ?? '')) === '') {
			return '';
		}

		return $street . ', ' . $city;
	}//end address()

	/**
	 * Write one payee's record: the existing one updated, or a new one.
	 *
	 * @param array<string, mixed>      $payee            The payee.
	 * @param array<string, mixed>|null $record           The year's existing record of this payee.
	 * @param string                    $administrationId The administration.
	 * @param int                       $taxYear          The tax year.
	 * @param int                       $cents            What was paid, in cents.
	 *
	 * @return array{payeeId: string, recipientName: string, paymentsTotal: float, recordId: string, created: bool}
	 */
	private function write(array $payee, ?array $record, string $administrationId, int $taxYear, int $cents): array {
		$payeeId = (string)$payee['id'];
		$total   = round(($cents / 100.0), 2);
		$object  = array_merge(
			($record ?? []),
			[
				'taxYear' => $taxYear,
				'clientId' => $administrationId,
				'administrationId' => $administrationId,
				'payeeId' => $payeeId,
				'recipientName' => trim((string)$payee['name']),
				'recipientBSN' => (string)$payee['bsn'],
				'recipientAddress' => $this->address(payee: $payee),
				'paymentsTotal' => $total,
				'paymentTypeCode' => (string)$payee['ib47PaymentTypeCode'],
				'isDryRun' => false,
			]
		);
		unset($object['@self']);

		$uuid = null;
		if ($record !== null && (string)($record['id'] ?? '') !== '') {
			$uuid = (string)$record['id'];
		}

		$saved = $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $this->register(), schema: self::RECORD_SCHEMA),
				uuid: $uuid
			)
		);

		return [
			'payeeId' => $payeeId,
			'recipientName' => trim((string)$payee['name']),
			'paymentsTotal' => $total,
			'recordId' => ($uuid ?? ObjectIdentifier::resolve(saved: $saved)),
			'created' => ($uuid === null),
		];
	}//end write()

	/**
	 * The objects of one schema matching the filters, each with its `id`.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<array<string, mixed>> The records.
	 */
	private function find(string $schema, array $filters): array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$records = [];
		foreach ($found as $candidate) {
			$record = ObjectIdentifier::recordWithId(candidate: $candidate);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end find()

	/**
	 * The configured register slug.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		return RegisterSlug::of(config: $this->appConfig);
	}//end register()
}//end class
