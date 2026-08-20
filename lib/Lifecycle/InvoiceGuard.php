<?php

/**
 * Invoice Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the Invoice register
 * (invoice-from-time-and-expense, T2). The bulk of the invoicing model is
 * declarative (Invoice/InvoiceLine schema metadata + x-openregister-lifecycle
 * + x-openregister-calculations / -aggregations). A small set of posting
 * preconditions require cross-schema lookups / cross-line aggregation that
 * OpenRegister's declarative `requires:` clause cannot yet express; those are
 * referenced from the Invoice schema lifecycle transitions and implemented
 * here:
 *
 *  - canPost():   before a draft invoice posts it MUST (a) carry at least one
 *                 InvoiceLine, (b) reference no time/expense source id already
 *                 used by another non-cancelled invoice — the double-invoicing
 *                 guard (REQ-ITE-007, design D9), (c) carry a mandatory
 *                 retainer_charge line for retainer/mixed models (design D3),
 *                 and (d) for the milestone model carry a milestone line whose
 *                 referenced milestone is complete (design D6).
 *  - canCancel(): only a draft invoice may be cancelled; a posted invoice is
 *                 corrected by a credit note, never cancelled (REQ-ITE-007).
 *
 * ADR-031 exception reason: cross-schema source-id deduplication, milestone
 * completion lookup and model-conditional line-presence checks are not yet
 * expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete
 * this file. ADR-022: object reads use the real OpenRegister ObjectService API
 * (setRegister/setSchema/findAll) only.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for Invoice post and cancel transitions.
 *
 * Referenced from the Invoice schema (register.d fragment)
 * x-openregister-lifecycle transitions.post.requires as
 * OCA\Shillinq\Lifecycle\InvoiceGuard::canPost and transitions.cancel.requires
 * as OCA\Shillinq\Lifecycle\InvoiceGuard::canCancel. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 */
class InvoiceGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the draft invoice may be posted to accounts receivable.
	 *
	 * REQ-ITE-005 / REQ-ITE-007 / design D3, D6, D9. Fail-closed: any exception
	 * or malformed input denies the post (CWE-863).
	 *
	 * @param string $invoiceId The Invoice.id being transitioned.
	 * @param array<string,mixed>|null $object The invoice object being transitioned.
	 *
	 * @return bool True when the invoice may post.
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
	 */
	public function canPost(string $invoiceId, ?array $object = null): bool {
		try {
			$invoice = $this->resolveObject(schema: 'Invoice', id: $invoiceId, object: $object);
			if ($invoice === null) {
				return false;
			}

			$lines = $this->resolveLines(invoiceId: $invoiceId, invoice: $invoice);
			if (count($lines) < 1) {
				return false;
			}

			$billingModel = (string)($invoice['billingModel'] ?? '');
			if ($billingModel === '') {
				return false;
			}

			if ($this->modelLinesAreConsistent(billingModel: $billingModel, lines: $lines) === false) {
				return false;
			}

			// Double-invoicing guard: no time/expense source id may already be
			// referenced by another non-cancelled invoice (REQ-ITE-007).
			return $this->sourceIdsAreUnique(invoice: $invoice);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InvoiceGuard: post check failed — denying post transition (fail-closed)',
				['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canPost()

	/**
	 * Returns true iff the line set satisfies the model-specific mandatory-line
	 * preconditions: retainer / mixed need a retainer_charge line (design D3);
	 * milestone needs a completed-milestone line (design D6); other models have
	 * no mandatory synthetic line.
	 *
	 * @param string $billingModel The invoice billing model.
	 * @param array<int,mixed> $lines The invoice lines.
	 *
	 * @return bool True when the lines are consistent with the model.
	 */
	private function modelLinesAreConsistent(string $billingModel, array $lines): bool {
		if ($billingModel === 'retainer' || $billingModel === 'mixed') {
			return $this->hasLineOfType(lines: $lines, sourceType: 'retainer_charge');
		}

		if ($billingModel === 'milestone') {
			return $this->hasCompletedMilestoneLine(lines: $lines);
		}

		return true;
	}//end modelLinesAreConsistent()

	/**
	 * Returns true iff the invoice is an un-posted draft and may be cancelled.
	 *
	 * REQ-ITE-007: posted invoices are corrected by a credit note (T3+), never
	 * cancelled. Fail-closed on any exception (CWE-863).
	 *
	 * @param string $invoiceId The Invoice.id being transitioned.
	 * @param array<string,mixed>|null $object The invoice object being transitioned.
	 *
	 * @return bool True when the invoice may be cancelled.
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
	 */
	public function canCancel(string $invoiceId, ?array $object = null): bool {
		try {
			$invoice = $this->resolveObject(schema: 'Invoice', id: $invoiceId, object: $object);
			if ($invoice === null) {
				return false;
			}

			return ($invoice['state'] ?? '') === 'draft';
		} catch (\Throwable $e) {
			$this->logger->error(
				'InvoiceGuard: cancel check failed — denying cancel transition (fail-closed)',
				['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canCancel()

	/**
	 * Returns true iff none of this invoice's time/expense source ids are
	 * already referenced by another non-cancelled invoice.
	 *
	 * REQ-ITE-007 / design D9. Compares the invoice's timeEntryIds + expenseIds
	 * against every other Invoice that is not in state `cancelled`. A match on
	 * any source id (and a different invoice id) denies the post.
	 *
	 * @param array<string,mixed> $invoice The invoice being posted.
	 *
	 * @return bool True when all source ids are unique to this invoice.
	 */
	private function sourceIdsAreUnique(array $invoice): bool {
		$ownSources = $this->collectSourceIds(invoice: $invoice);
		if ($ownSources === []) {
			// Nothing to deduplicate (e.g. a pure fixed-fee / milestone invoice).
			return true;
		}

		$ownId = (string)($invoice['id'] ?? '');

		$register = $this->resolveRegister();

		$others = $this->objectService
			->setRegister($register)
			->setSchema('Invoice')
			->findAll([]);

		foreach ($others as $other) {
			if (is_array($other) === false || ($other['state'] ?? '') === 'cancelled') {
				continue;
			}

			$otherId = (string)($other['id'] ?? '');
			if ($otherId !== '' && $otherId === $ownId) {
				continue;
			}

			$otherSources = $this->collectSourceIds(invoice: $other);
			if (array_intersect($ownSources, $otherSources) !== []) {
				$this->logger->warning(
					'InvoiceGuard: double-invoicing denied — overlapping source ids',
					['invoiceId' => $ownId, 'conflictingInvoiceId' => $otherId]
				);
				return false;
			}
		}//end foreach

		return true;
	}//end sourceIdsAreUnique()

	/**
	 * Collect the deduplicated set of time + expense source ids on an invoice.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return array<int,string> Unique non-empty source ids.
	 */
	private function collectSourceIds(array $invoice): array {
		$time = ($invoice['timeEntryIds'] ?? []);
		$expense = ($invoice['expenseIds'] ?? []);
		if (is_array($time) === false) {
			$time = [];
		}

		if (is_array($expense) === false) {
			$expense = [];
		}

		$ids = [];
		foreach (array_merge($time, $expense) as $id) {
			$id = (string)$id;
			if ($id !== '') {
				$ids[$id] = true;
			}
		}

		return array_keys($ids);
	}//end collectSourceIds()

	/**
	 * Returns true iff at least one line carries the given sourceType.
	 *
	 * @param array<int,mixed> $lines The invoice lines.
	 * @param string $sourceType The required source type.
	 *
	 * @return bool True when a matching line is present.
	 */
	private function hasLineOfType(array $lines, string $sourceType): bool {
		foreach ($lines as $line) {
			if (is_array($line) === true && ($line['sourceType'] ?? '') === $sourceType) {
				return true;
			}
		}

		return false;
	}//end hasLineOfType()

	/**
	 * Returns true iff a milestone line is present and its milestone is complete.
	 *
	 * Design D6: a milestone invoice may only post once the referenced milestone
	 * is marked complete. The completion fact lives in the line's
	 * modelSpecificFields.milestoneCompletedAt (snapshot at draft time).
	 *
	 * @param array<int,mixed> $lines The invoice lines.
	 *
	 * @return bool True when a completed-milestone line is present.
	 */
	private function hasCompletedMilestoneLine(array $lines): bool {
		foreach ($lines as $line) {
			if (is_array($line) === false || ($line['sourceType'] ?? '') !== 'milestone') {
				continue;
			}

			$fields = ($line['modelSpecificFields'] ?? []);
			if (is_array($fields) === true && ($fields['milestoneCompletedAt'] ?? '') !== '') {
				return true;
			}
		}

		return false;
	}//end hasCompletedMilestoneLine()

	/**
	 * Resolve the invoice's InvoiceLine children, preferring an embedded
	 * `lines` preview on the object and falling back to an ObjectService lookup
	 * by invoiceId.
	 *
	 * @param string $invoiceId The Invoice.id.
	 * @param array<string,mixed> $invoice The invoice object.
	 *
	 * @return array<int,mixed> The line rows (possibly empty).
	 */
	private function resolveLines(string $invoiceId, array $invoice): array {
		if (isset($invoice['lines']) === true && is_array($invoice['lines']) === true && $invoice['lines'] !== []) {
			return array_values($invoice['lines']);
		}

		if ($invoiceId === '') {
			return [];
		}

		$register = $this->resolveRegister();

		$lines = $this->objectService
			->setRegister($register)
			->setSchema('InvoiceLine')
			->findAll(['filters' => ['invoiceId' => $invoiceId]]);

		return array_values($lines);
	}//end resolveLines()

	/**
	 * Resolve an object by id, preferring the supplied in-flight object and
	 * falling back to an ObjectService lookup.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return array<string,mixed>|null The resolved object, or null when not found.
	 */
	private function resolveObject(string $schema, string $id, ?array $object): ?array {
		if ($object !== null && $object !== []) {
			return $object;
		}

		if ($id === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$rows = $this->objectService
			->setRegister($register)
			->setSchema($schema)
			->findAll(['filters' => ['id' => $id]]);

		$first = array_shift($rows);
		if (is_array($first) === true) {
			return $first;
		}

		return null;
	}//end resolveObject()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
