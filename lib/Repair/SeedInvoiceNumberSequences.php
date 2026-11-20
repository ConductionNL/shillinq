<?php

/**
 * Seed Invoice Number Sequences
 *
 * Numbers are taken from a locked series at issue (sales-invoice-issue-controls,
 * design Migration Plan). Before that, AR invoice numbers were typed and
 * time-and-expense invoices were numbered BIL-<year>-<count+1>. This step
 * starts each administration's current-year series after the highest counter
 * already in use, so the first number the series hands out is not one an
 * invoice already carries: sales from the issued AR invoices whose number
 * follows the series pattern, billable from every time-and-expense invoice.
 * Re-running it changes nothing: a series is never lowered.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\InvoiceNumberAuditService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\InvoiceNumberPattern;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts every administration's invoice number series after the numbers in use.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
 */
class SeedInvoiceNumberSequences implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Records read per page.
	 *
	 * @var int
	 */
	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param InvoiceNumberSequenceService $sequences        The series.
	 * @param ObjectServiceInterface       $objectService    OpenRegister's object service.
	 * @param RegisterLocation             $registerLocation The register this app's objects live in.
	 * @param LoggerInterface              $logger           Logger.
	 * @param DateTimeImmutable|null       $today            The day the step runs; null for now.
	 */
	public function __construct(
		private readonly InvoiceNumberSequenceService $sequences,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
		private readonly ?DateTimeImmutable $today = null,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
	 */
	public function getName(): string {
		return 'Shillinq: start the invoice number series after the numbers already in use';
	}//end getName()

	/**
	 * Seed the current year's sales and billable series of every administration.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
	 */
	public function run(IOutput $output): void {
		$this->withSystemIdentity(objectService: $this->objectService, work: fn () => $this->seedAll(output: $output));
	}//end run()

	/**
	 * Seed every administration's series.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 */
	private function seedAll(IOutput $output): void {
		$year = (int)($this->today ?? new DateTimeImmutable())->format('Y');
		try {
			$seeded = 0;
			$administrations = 0;
			foreach ($this->records(schema: 'Administration', filters: []) as $administration) {
				$administrationId = (string)($administration['id'] ?? '');
				if ($administrationId === '') {
					continue;
				}

				$administrations++;
				$seeded += $this->seedKind(
					administrationId: $administrationId,
					kind: InvoiceNumberSequenceService::KIND_SALES,
					year: $year,
					numbers: $this->numbers(schema: 'ARInvoice', administrationId: $administrationId, states: InvoiceNumberAuditService::NUMBERED_STATES)
				);
				$seeded += $this->seedKind(
					administrationId: $administrationId,
					kind: InvoiceNumberSequenceService::KIND_BILLABLE,
					year: $year,
					numbers: $this->numbers(schema: 'BillableInvoice', administrationId: $administrationId, states: null)
				);
			}

			$output->info(sprintf('Shillinq: %d invoice number series started or raised for %d administration(s).', $seeded, $administrations));
		} catch (Throwable $e) {
			$output->warning('Shillinq: starting the invoice number series failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: starting the invoice number series failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end seedAll()

	/**
	 * Seed one series from the numbers in use.
	 *
	 * @param string             $administrationId The administration.
	 * @param string             $kind             The kind.
	 * @param int                $year             The fiscal year.
	 * @param array<int, string> $numbers          The numbers in use.
	 *
	 * @return int 1 when the series was created or raised, else 0.
	 */
	private function seedKind(string $administrationId, string $kind, int $year, array $numbers): int {
		$pattern = new InvoiceNumberPattern(pattern: $this->sequences->patternOf(administrationId: $administrationId, kind: $kind));
		$highest = 0;
		foreach ($numbers as $number) {
			$highest = max($highest, ($pattern->sequenceOf(number: $number, year: $year) ?? 0));
		}

		$this->logger->info(
			'Shillinq: invoice number series seeded from the numbers in use',
			['administrationId' => $administrationId, 'kind' => $kind, 'year' => $year, 'numbers' => count($numbers), 'lastValue' => $highest]
		);

		return (int)$this->sequences->seed(administrationId: $administrationId, kind: $kind, year: $year, lastValue: $highest);
	}//end seedKind()

	/**
	 * The invoice numbers of one administration in one schema.
	 *
	 * @param string                  $schema           `ARInvoice` or `BillableInvoice`.
	 * @param string                  $administrationId The administration.
	 * @param array<int, string>|null $states           The lifecycle states that count, null for all.
	 *
	 * @return array<int, string>
	 */
	private function numbers(string $schema, string $administrationId, ?array $states): array {
		$numbers = [];
		foreach ($this->records(schema: $schema, filters: ['administrationId' => $administrationId]) as $invoice) {
			if ((string)($invoice['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			if ($states !== null && in_array((string)($invoice['lifecycleState'] ?? ''), $states, true) === false) {
				continue;
			}

			$number = trim((string)($invoice['invoiceNumber'] ?? ''));
			if ($number !== '') {
				$numbers[] = $number;
			}
		}

		return $numbers;
	}//end numbers()

	/**
	 * Every record of a schema matching the filters, page by page.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(string $schema, array $filters): array {
		$scoped = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
		$records = [];
		$offset = 0;
		do {
			$rows = $scoped->findAll(['filters' => $filters, 'limit' => self::PAGE, 'offset' => $offset]);
			foreach ($rows as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null) {
					$records[] = $record;
				}
			}

			$offset += self::PAGE;
			$full = (count($rows) === self::PAGE);
		} while ($full === true);

		return $records;
	}//end records()
}//end class
