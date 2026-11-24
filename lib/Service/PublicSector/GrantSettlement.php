<?php

/**
 * Grant Settlement
 *
 * Settles a grant when it is determined, and stores its accountability
 * report. Determining compares the determined amount with what was paid out,
 * in cents: what was paid out above the determined amount is reclaimed. For
 * a grant given (direction outgoing) the reclaim is a draft receivable
 * invoice to the beneficiary; for a grant received (incoming) it is a draft
 * payable to the granting body. Both name the grant. Nothing is booked: the
 * draft is issued through its own lifecycle (REQ-SUBV-011).
 *
 * The report runs SubsidieVerantwoordingService's builders over the grant,
 * stores the SubsidieVerantwoording once per grant and, when the grant's
 * amount reaches the auditor threshold, a pending AuditorStatement
 * (REQ-SUBV-012).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeImmutable;
use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;
use OCA\Shillinq\Service\SubsidieVerantwoordingService;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Override;

/**
 * Settles a determined grant and stores its accountability report.
 *
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 */
class GrantSettlement implements GrantSettlementInterface {

	/**
	 * Days between the reclaim's date and its due date.
	 */
	public const PAYMENT_TERM_DAYS = 30;

	/**
	 * The auditor a pending statement is assigned to until someone picks it up.
	 */
	public const UNASSIGNED_AUDITOR = 'unassigned';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                    $appConfig      App config for the register slug.
	 * @param ObjectServiceInterface        $objectService  OpenRegister's object service.
	 * @param SubsidieVerantwoordingService $verantwoording Builds the report and decides on the auditor statement.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SubsidieVerantwoordingService $verantwoording,
	) {
	}//end __construct()

	/**
	 * Settle a grant being determined.
	 *
	 * @param array<string,mixed> $grant The Subsidie as the determine transition leaves it.
	 *
	 * @return array<string,mixed> The grant with reclaimedAmount and the reclaim document id set.
	 *
	 * @throws DomainException When the grant has no determined amount, or a reclaim has no counterparty.
	 *
	 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-determining-a-grant-settles-the-difference-req-subv-011
	 */
	#[Override]
	public function settle(array $grant): array {
		if (is_numeric($grant['determinedAmount'] ?? null) === false) {
			throw new DomainException('Enter the determined amount before determining the grant.');
		}

		$reclaimCents = (self::cents(value: $grant['paidOutAmount'] ?? 0) - self::cents(value: $grant['determinedAmount']));
		if ($reclaimCents <= 0) {
			$grant['reclaimedAmount'] = 0.0;
			return $grant;
		}

		$grant['reclaimedAmount'] = self::euros(cents: $reclaimCents);
		if ((string)($grant['reclaimInvoiceId'] ?? '') !== '' || (string)($grant['reclaimPayableId'] ?? '') !== '') {
			return $grant;
		}

		if (trim((string)($grant['counterpartyId'] ?? '')) === '') {
			throw new DomainException('Link the grant to the counterparty\'s contact before determining it: the reclaim needs someone to address.');
		}

		if ((string)($grant['direction'] ?? '') === 'incoming') {
			$grant['reclaimPayableId'] = $this->create(schema: 'APTransaction', object: $this->payable(grant: $grant, cents: $reclaimCents));
			return $grant;
		}

		$grant['reclaimInvoiceId'] = $this->create(schema: 'ARInvoice', object: $this->invoice(grant: $grant, cents: $reclaimCents));
		return $grant;

	}//end settle()

	/**
	 * Store the accountability report of a grant.
	 *
	 * @param array<string,mixed> $grant The Subsidie, with its id.
	 *
	 * @return array{verantwoordingId: string, auditorStatementRequired: bool, auditorStatementId: string|null}
	 *
	 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-the-accountability-report-is-generated-from-the-grant-req-subv-012
	 */
	#[Override]
	public function report(array $grant): array {
		if ((string)($grant['subsidyNumber'] ?? '') === '') {
			throw new DomainException('The grant has no grant number, so its report cannot be named.');
		}

		$report = $this->verantwoording->buildVerantwoordingForGrant(grant: self::withAward(grant: $grant));
		$existing = $this->first(schema: 'SubsidieVerantwoording', filters: ['accountabilityId' => $report['accountabilityId']]);
		$reportId = (string)($existing['id'] ?? '');
		if ($reportId === '') {
			$reportId = $this->create(schema: 'SubsidieVerantwoording', object: $report);
		}

		$required = $this->verantwoording->requiresAuditorStatement(awardedAmount: (float)$report['awardedAmount']);
		$statementId = null;
		if ($required === true) {
			$statement = $this->verantwoording->buildAuditorStatementForVerantwoording(accountability: $report, auditorUserId: self::UNASSIGNED_AUDITOR);
			$found = $this->first(schema: 'AuditorStatement', filters: ['statementId' => (string)($statement['statementId'] ?? '')]);
			$statementId = (string)($found['id'] ?? '');
			if ($statementId === '' && $statement !== null) {
				$statementId = $this->create(schema: 'AuditorStatement', object: $statement);
			}
		}

		return ['verantwoordingId' => $reportId, 'auditorStatementRequired' => $required, 'auditorStatementId' => $statementId];

	}//end report()

	/**
	 * The grant with its awarded amount where the report reads it: the award, else the granted amount.
	 *
	 * @param array<string,mixed> $grant The Subsidie.
	 *
	 * @return array<string,mixed>
	 */
	private static function withAward(array $grant): array {
		if (is_numeric($grant['awardAmount'] ?? null) === false && is_numeric($grant['grantedAmount'] ?? null) === true) {
			$grant['awardAmount'] = (float)$grant['grantedAmount'];
		}

		if ((string)($grant['awardDate'] ?? '') === '' && (string)($grant['decisionDate'] ?? '') !== '') {
			$grant['awardDate'] = (string)$grant['decisionDate'];
		}

		return $grant;

	}//end withAward()

	/**
	 * The draft reclaim invoice to the beneficiary of a grant given.
	 *
	 * @param array<string,mixed> $grant The Subsidie.
	 * @param int                 $cents The amount to reclaim, in cents.
	 *
	 * @return array<string,mixed> The ARInvoice payload.
	 */
	private function invoice(array $grant, int $cents): array {
		$dates = self::dates(grant: $grant);
		$amount = self::euros(cents: $cents);

		return [
			'invoiceNumber'    => 'TV-' . (string)$grant['subsidyNumber'],
			'customerId'       => trim((string)$grant['counterpartyId']),
			'administrationId' => (string)($grant['administrationId'] ?? ''),
			'invoiceDate'      => $dates['invoice'],
			'dueDate'          => $dates['due'],
			'periodId'         => substr($dates['invoice'], 0, 7),
			'currency'         => (string)($grant['currency'] ?? 'EUR'),
			'netAmount'        => $amount,
			'vatAmount'        => 0.0,
			'grossAmount'      => $amount,
			'lifecycleState'   => 'draft',
			'invoiceLines'     => [
				[
					'lineId'      => '1',
					'quantity'    => 1,
					'unitCode'    => 'C62',
					'itemName'    => self::description(grant: $grant),
					'netPrice'    => $amount,
					'netAmount'   => $amount,
					'vatCategory' => 'O',
				],
			],
			'linkedObjects'    => [
				[
					'app'      => 'shillinq',
					'type'     => 'grant',
					'register' => $this->register(),
					'schema'   => 'Subsidie',
					'id'       => (string)($grant['id'] ?? ''),
					'label'    => (string)$grant['subsidyNumber'],
				],
			],
		];

	}//end invoice()

	/**
	 * The draft payable to the granting body of a grant received.
	 *
	 * @param array<string,mixed> $grant The Subsidie.
	 * @param int                 $cents The amount to pay back, in cents.
	 *
	 * @return array<string,mixed> The APTransaction payload.
	 */
	private static function payable(array $grant, int $cents): array {
		$dates = self::dates(grant: $grant);
		$amount = self::euros(cents: $cents);

		return [
			'invoiceNumber'    => 'TV-' . (string)$grant['subsidyNumber'],
			'vendorId'         => trim((string)$grant['counterpartyId']),
			'invoiceDate'      => $dates['invoice'],
			'dueDate'          => $dates['due'],
			'currency'         => (string)($grant['currency'] ?? 'EUR'),
			'totalAmount'      => $amount,
			'taxAmount'        => 0.0,
			'lines'            => [
				['description' => self::description(grant: $grant), 'accountNumber' => '', 'amount' => $amount],
			],
			'state'            => 'draft',
			'administrationId' => (string)($grant['administrationId'] ?? ''),
		];

	}//end payable()

	/**
	 * The words that name the grant on its reclaim.
	 *
	 * @param array<string,mixed> $grant The Subsidie.
	 *
	 * @return string
	 */
	private static function description(array $grant): string {
		$scheme = trim((string)($grant['schemeName'] ?? ''));
		$text = 'Terugvordering subsidie ' . (string)$grant['subsidyNumber'];
		if ($scheme !== '') {
			$text .= ' (' . $scheme . ')';
		}

		return $text;

	}//end description()

	/**
	 * The reclaim's date (the determination date, else today) and its due date.
	 *
	 * @param array<string,mixed> $grant The Subsidie.
	 *
	 * @return array{invoice: string, due: string}
	 */
	private static function dates(array $grant): array {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($grant['determinationDate'] ?? ''), 0, 10));
		if ($date === false) {
			$date = new DateTimeImmutable('today');
		}

		return [
			'invoice' => $date->format('Y-m-d'),
			'due'     => $date->modify('+' . self::PAYMENT_TERM_DAYS . ' days')->format('Y-m-d'),
		];

	}//end dates()

	/**
	 * The first row of a schema matching property filters, as an array.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters, 'limit' => 1]);
		$row = ($found[0] ?? null);
		if ($row === null) {
			return null;
		}

		if (is_array($row) === false) {
			$row = $row->jsonSerialize();
		}

		return $row;

	}//end first()

	/**
	 * Create a record and return its id.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return string
	 *
	 * @throws DomainException When the store returns no id.
	 */
	private function create(string $schema, array $object): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $schema))
		);
		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new DomainException('The ' . $schema . ' for the grant could not be written.');
		}

		return $id;

	}//end create()

	/**
	 * Euros to integer cents.
	 *
	 * @param mixed $value The amount in euros.
	 *
	 * @return int
	 */
	private static function cents(mixed $value): int {
		return (int)round(((float)$value) * 100);

	}//end cents()

	/**
	 * Integer cents to euros.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return float
	 */
	private static function euros(int $cents): float {
		return round(((float)$cents / 100), 2);

	}//end euros()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		return RegisterSlug::of(config: $this->appConfig);

	}//end register()
}//end class
