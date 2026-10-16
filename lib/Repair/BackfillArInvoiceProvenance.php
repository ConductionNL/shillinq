<?php

/**
 * Backfill AR Invoice Provenance
 *
 * ARInvoice 0.16.0 declares `recurringProfileId`, `billingPeriod`,
 * `customerReference` and `invoiceLines[].glAccount`. Before it OpenRegister
 * dropped all four, so invoices saved earlier lack them: the recurring
 * double-billing guard cannot see a generated invoice, the e-invoice has no
 * buyer reference (BT-10) and no line books to its account. This step fills
 * what can be derived (ArInvoiceProvenance): a generated invoice from the
 * recurring profile and its lines, a quick draft from its create audit entry.
 * It never overwrites a value and never guesses between two profiles, so a
 * rerun saves nothing.
 *
 * Runs post-migration, after InitializeSettings has imported ARInvoice 0.16.0.
 * Best-effort: a failure warns and never blocks the upgrade. Reads and writes
 * are unscoped, because the repair context has no user.
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
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ArInvoiceProvenance;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Back-fills the provenance ARInvoice dropped before 0.16.0.
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 */
class BackfillArInvoiceProvenance implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema this step writes.
	 *
	 * @var string
	 */
	private const SCHEMA = 'ARInvoice';

	/**
	 * Counts for the summary line.
	 *
	 * @var array{filled: int, ambiguous: int}
	 */
	private array $counts = ['filled' => 0, 'ambiguous' => 0];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register slug.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param ArInvoiceProvenance $provenance The derivation rules.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ArInvoiceProvenance $provenance = new ArInvoiceProvenance(),
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
	 */
	public function getName(): string {
		return 'Shillinq: give invoices saved before ARInvoice 0.16.0 the profile, period, reference and line accounts that can be derived';
	}//end getName()

	/**
	 * Fill every invoice that has a derivable gap.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/shillinq-invoice-quick-draft/spec.md (REQ-IQD-008)
	 */
	public function run(IOutput $output): void {
		$this->counts = ['filled' => 0, 'ambiguous' => 0];

		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$profiles = $this->profilesById(registerSlug: $registerSlug);
			$rows = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: self::SCHEMA);

			foreach ($rows as $row) {
				$uuid = ObjectIdentifier::resolve(saved: $row);
				if ($uuid === '') {
					continue;
				}

				$invoice = $this->withoutMetadata(row: $this->rowPayload(row: $row));
				$filled = $this->derive(invoice: $invoice, uuid: $uuid, profiles: $profiles);
				if ($filled === $invoice) {
					continue;
				}

				$this->objectService->saveObject(
					object: $filled,
					register: $registerSlug,
					schema: self::SCHEMA,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
				);
				$this->counts['filled']++;
			}//end foreach

			$output->info(
				sprintf(
					'Shillinq: %d invoice(s) given their derivable provenance, %d ambiguous left alone, %d unchanged.',
					$this->counts['filled'],
					$this->counts['ambiguous'],
					(count($rows) - $this->counts['filled'])
				)
			);
		} catch (Throwable $e) {
			$output->warning('Shillinq: the invoice provenance backfill failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: the invoice provenance backfill failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * The invoice with every derivable gap filled.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param string $uuid Its uuid.
	 * @param array<string, array<string, mixed>> $profiles The profiles by id.
	 *
	 * @return array<string, mixed> The invoice, unchanged when nothing could be derived.
	 */
	private function derive(array $invoice, string $uuid, array $profiles): array {
		if ($this->provenance->isQuickDraft(invoice: $invoice) === true) {
			if ($this->provenance->hasQuickDraftGap(invoice: $invoice) === false) {
				return $invoice;
			}

			return $this->provenance->fillFromAudit(invoice: $invoice, created: $this->createdFields(uuid: $uuid));
		}

		if ($this->provenance->hasRecurringGap(invoice: $invoice) === false) {
			return $invoice;
		}

		$profileId = $this->provenance->matchProfile(invoice: $invoice, profiles: $profiles);
		if ($profileId === ArInvoiceProvenance::AMBIGUOUS) {
			$this->counts['ambiguous']++;
			return $invoice;
		}

		if ($profileId === null) {
			return $invoice;
		}

		return $this->provenance->fillFromProfile(invoice: $invoice, profileId: $profileId, profile: $profiles[$profileId]);
	}//end derive()

	/**
	 * Every recurring profile, keyed by id.
	 *
	 * @param string $registerSlug The register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function profilesById(string $registerSlug): array {
		$profiles = [];
		foreach ($this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'RecurringInvoiceProfile') as $row) {
			$profileId = ObjectIdentifier::resolve(saved: $row);
			if ($profileId !== '') {
				$profiles[$profileId] = $this->rowPayload(row: $row);
			}
		}

		return $profiles;
	}//end profilesById()

	/**
	 * The changed fields of the invoice's create audit entry; empty when there
	 * is none or the trail cannot be read (that draft is then left alone).
	 *
	 * @param string $uuid The invoice uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function createdFields(string $uuid): array {
		try {
			$logs = $this->objectService->getLogs($uuid, [], false, false);
		} catch (Throwable $e) {
			$this->logger->info('Shillinq: no audit trail for quick draft ' . $uuid . ': ' . $e->getMessage());
			return [];
		}

		foreach ($logs as $log) {
			if (is_object($log) === true && method_exists($log, 'getAction') === true && $log->getAction() === 'create') {
				return (array)$log->getChanged();
			}

			if (is_array($log) === true && ($log['action'] ?? null) === 'create') {
				return (array)($log['changed'] ?? []);
			}
		}

		return [];
	}//end createdFields()

	/**
	 * A row without its id and OpenRegister metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function withoutMetadata(array $row): array {
		$data = [];
		foreach ($row as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		return $data;
	}//end withoutMetadata()
}//end class
