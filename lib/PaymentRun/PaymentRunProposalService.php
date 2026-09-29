<?php

/**
 * Payment Run Proposal Service
 *
 * Drafts a PaymentRun from the supplier invoices that are due, so nobody
 * types payment lines by hand. It writes a draft only: the four-eyes approval
 * and the export guard stay the controls (banking-payment-run design.md D2).
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-payment-run/specs/payment-run-sepa-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Selects the payable invoices due by a date into one draft PaymentRun.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
class PaymentRunProposalService {

	public const REASON_ALREADY_ON_RUN = 'already-on-run';
	public const REASON_NO_IBAN = 'no-iban';
	public const REASON_CURRENCY = 'not-euro';

	/**
	 * Every line goes out on the run's execution date.
	 *
	 * @var string
	 */
	public const DATES_RUN = 'run';

	/**
	 * Each line goes out on the later of its due date and the run's date.
	 *
	 * @var string
	 */
	public const DATES_DUE = 'due';

	/**
	 * Invoice states a run may pay.
	 *
	 * @var list<string>
	 */
	private const PAYABLE_STATES = ['issued', 'overdue', 'partially-paid'];

	/**
	 * Run states that hold an invoice (the duplicate control's list).
	 *
	 * @var list<string>
	 */
	private const OCCUPYING_STATES = ['draft', 'approved', 'exported'];

	/**
	 * Run states whose lines left the bank.
	 *
	 * @var list<string>
	 */
	private const PAID_OUT_STATES = ['exported', 'reconciled'];

	/**
	 * The most invoices or runs read for one administration.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      The register slug.
	 * @param PaymentBlockChecker    $blockChecker  The shared block check.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly PaymentBlockChecker $blockChecker,
	) {

	}//end __construct()

	/**
	 * Draft a run of every payable invoice due on or before a date.
	 *
	 * @param string $administrationId  The administration.
	 * @param string $dueOnOrBefore     The last due date to include (Y-m-d).
	 * @param string $debtorAccountIban The account the money leaves from.
	 * @param string $executionDate     The run's execution date (Y-m-d).
	 * @param string $lineDates         DATES_RUN, or DATES_DUE to give each line the later of its due date and the run date.
	 *
	 * @return array{paymentRun: array<string, mixed>|null, skipped: list<array<string, string>>} Draft run or null, and what was left out.
	 *
	 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.1
	 */
	public function propose(
		string $administrationId,
		string $dueOnOrBefore,
		string $debtorAccountIban,
		string $executionDate,
		string $lineDates=self::DATES_RUN
	): array {
		$runs = $this->read(schema: 'PaymentRun', administrationId: $administrationId);
		$occupied = $this->refsInRuns(runs: $runs, states: self::OCCUPYING_STATES, exceptPaidOut: true);
		$paidOut = $this->amountsInRuns(runs: $runs);

		$lines = [];
		$skipped = [];
		foreach ($this->dueInvoices(administrationId: $administrationId, dueOnOrBefore: $dueOnOrBefore) as $invoice) {
			$outcome = $this->lineFor(invoice: $invoice, occupied: $occupied, paidOut: $paidOut);
			if (isset($outcome['reason']) === true) {
				$skipped[] = $outcome;
				continue;
			}

			if ($lineDates === self::DATES_DUE) {
				$outcome['requestedExecutionDate'] = max((string)($invoice['dueDate'] ?? ''), $executionDate);
			}

			$lines[] = $outcome;
		}

		if ($lines === []) {
			return ['paymentRun' => null, 'skipped' => $skipped];
		}

		$run = [
			'runNumber' => $this->nextRunNumber(runs: $runs, executionDate: $executionDate),
			'administrationId' => $administrationId,
			'executionDate' => $executionDate,
			'debtorAccountIban' => $debtorAccountIban,
			'status' => 'draft',
			'lifecycleState' => 'draft',
			'totalAmount' => round(array_sum(array_column($lines, 'amount')), 2),
			'currency' => 'EUR',
			'paymentLines' => $lines,
		];

		$saved = $this->scoped(schema: 'PaymentRun')->saveObject(object: $run);
		$record = ObjectIdentifier::recordWithId(candidate: $saved);
		if ($record === null) {
			$record = $run;
		}

		return ['paymentRun' => $record, 'skipped' => $skipped];

	}//end propose()

	/**
	 * The payment line for one invoice, or the reason it is left out.
	 *
	 * @param array<string, mixed> $invoice  The APTransaction.
	 * @param array<string, true>  $occupied Invoice refs already on a live run.
	 * @param array<string, float> $paidOut  Amount per invoice ref already paid out by a run.
	 *
	 * @return array<string, mixed>
	 */
	private function lineFor(array $invoice, array $occupied, array $paidOut): array {
		$ref = (string)($invoice['id'] ?? '');
		$number = (string)($invoice['invoiceNumber'] ?? $ref);
		$skip = ['apTransactionRef' => $ref, 'invoiceNumber' => $number, 'detail' => ''];

		$payee = $this->blockChecker->payee(payeeId: trim((string)($invoice['vendorId'] ?? '')));
		$block = $this->blockChecker->reasonFor(invoice: $invoice, payee: $payee);
		if ($block !== null) {
			return $block + $skip;
		}

		if (isset($occupied[$ref]) === true) {
			return ['reason' => self::REASON_ALREADY_ON_RUN] + $skip;
		}

		if (strtoupper((string)($invoice['currency'] ?? 'EUR')) !== 'EUR') {
			return ['reason' => self::REASON_CURRENCY] + $skip;
		}

		$iban = trim((string)($payee['bankAccount']['iban'] ?? ''));
		if ($iban === '') {
			return ['reason' => self::REASON_NO_IBAN] + $skip;
		}

		$amount = round((float)($invoice['totalAmount'] ?? 0) - ($paidOut[$ref] ?? 0.0), 2);

		return [
			'payeeId' => (string)($payee['id'] ?? ($invoice['vendorId'] ?? '')),
			'payeeName' => (string)($payee['name'] ?? ''),
			'creditorIban' => $iban,
			'amount' => $amount,
			'remittanceInfo' => $number,
			'apTransactionRef' => $ref,
		];

	}//end lineFor()

	/**
	 * Payable and disputed invoices due on or before the date, oldest first.
	 *
	 * Disputed ones are read so the proposal can say why it left them out.
	 *
	 * @param string $administrationId The administration.
	 * @param string $dueOnOrBefore    The last due date.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function dueInvoices(string $administrationId, string $dueOnOrBefore): array {
		$due = [];
		foreach ($this->read(schema: 'APTransaction', administrationId: $administrationId) as $invoice) {
			$state = (string)($invoice['state'] ?? '');
			$dueDate = (string)($invoice['dueDate'] ?? '');
			if ($dueDate === '' || $dueDate > $dueOnOrBefore) {
				continue;
			}

			if (in_array($state, self::PAYABLE_STATES, true) === true || $state === 'disputed') {
				$due[] = $invoice;
			}
		}

		usort($due, static fn (array $a, array $b): int => strcmp((string)$a['dueDate'], (string)$b['dueDate']));

		return $due;

	}//end dueInvoices()

	/**
	 * Invoice refs on a run in one of the given states.
	 *
	 * @param list<array<string, mixed>> $runs          The administration's runs.
	 * @param list<string>               $states        The run states that count.
	 * @param bool                       $exceptPaidOut Leave out runs whose money left, as their invoices may have a rest to pay.
	 *
	 * @return array<string, true>
	 */
	private function refsInRuns(array $runs, array $states, bool $exceptPaidOut): array {
		$refs = [];
		foreach ($runs as $run) {
			$state = (string)($run['lifecycleState'] ?? ($run['status'] ?? ''));
			if (in_array($state, $states, true) === false) {
				continue;
			}

			if ($exceptPaidOut === true && in_array($state, self::PAID_OUT_STATES, true) === true) {
				continue;
			}

			foreach ((array)($run['paymentLines'] ?? []) as $line) {
				$ref = trim((string)($line['apTransactionRef'] ?? ''));
				if ($ref !== '') {
					$refs[$ref] = true;
				}
			}
		}

		return $refs;

	}//end refsInRuns()

	/**
	 * Amount per invoice ref already paid out by exported or reconciled runs.
	 *
	 * @param list<array<string, mixed>> $runs The administration's runs.
	 *
	 * @return array<string, float>
	 */
	private function amountsInRuns(array $runs): array {
		$amounts = [];
		foreach ($runs as $run) {
			$state = (string)($run['lifecycleState'] ?? ($run['status'] ?? ''));
			if (in_array($state, self::PAID_OUT_STATES, true) === false) {
				continue;
			}

			foreach ((array)($run['paymentLines'] ?? []) as $line) {
				$ref = trim((string)($line['apTransactionRef'] ?? ''));
				$amounts[$ref] = (($amounts[$ref] ?? 0.0) + (float)($line['amount'] ?? 0));
			}
		}

		return $amounts;

	}//end amountsInRuns()

	/**
	 * The next run number of the execution year: PR-<year>-<nnn>.
	 *
	 * @param list<array<string, mixed>> $runs          The administration's runs.
	 * @param string                     $executionDate The run date.
	 *
	 * @return string
	 */
	private function nextRunNumber(array $runs, string $executionDate): string {
		$prefix = 'PR-' . substr($executionDate, 0, 4) . '-';
		$highest = 0;
		foreach ($runs as $run) {
			$number = (string)($run['runNumber'] ?? '');
			if (str_starts_with($number, $prefix) === true) {
				$highest = max($highest, (int)substr($number, strlen($prefix)));
			}
		}

		return $prefix . sprintf('%03d', ($highest + 1));

	}//end nextRunNumber()

	/**
	 * Every record of a schema for the administration, with its id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(string $schema, string $administrationId): array {
		$rows = $this->scoped(schema: $schema)->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::READ_LIMIT]);
		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end read()

	/**
	 * The object service scoped to one schema of the shillinq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
