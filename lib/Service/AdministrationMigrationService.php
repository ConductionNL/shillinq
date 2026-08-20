<?php

/**
 * Administration Migration Service
 *
 * Pure-logic helper for the AdministrationMigration audit-trail dual-post
 * flow (REQ-MA-006 / Task 20). A migration captures an asset / contract /
 * employee transfer between two administraties; the bookkeeping side is a
 * pair of journal entries (source-side disposal + destination-side
 * activation) that MUST post atomically — either both post or both roll
 * back. This service ships the side-effect-free toolkit the dual-post
 * controller wires the live ObjectService into:
 *
 *  - lifecycle status transition validation (`isTransitionAllowed`);
 *  - paired-journal-entry draft construction (`buildSourceJournalDraft` /
 *    `buildDestinationJournalDraft`) with explicit integer-cent arithmetic
 *    to avoid float drift on the boekwaarde / marktwaarde / resultaat
 *    breakdown;
 *  - status resolution after each side posts
 *    (`statusAfterSidePosted` / `statusAfterReversal`);
 *  - reversal payload construction (`buildReversalEntries`) that swaps
 *    debit/credit of both sides at once so a single transaction rolls
 *    back the dual posting atomically (REQ-MA-006).
 *
 * The atomic posting itself happens at the data layer — this service is
 * the truth source for the *what* (what the journals must look like, what
 * status to land in next); the controller / engine glues it to the real
 * ObjectService API in one DB transaction per ADR-022 / ADR-031.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure dual-post logic for AdministrationMigration (REQ-MA-006).
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class AdministrationMigrationService {
	/**
	 * Allowed AdministrationMigration.status transitions, mirroring the declared
	 * x-openregister-lifecycle on the AdministrationMigration schema (REQ-MA-006).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TRANSITIONS = [
		'voorbereid' => ['uitgevoerd', 'teruggedraaid'],
		'uitgevoerd' => ['geboekt_beide', 'teruggedraaid'],
		'geboekt_beide' => ['teruggedraaid'],
		'teruggedraaid' => [],
	];

	/**
	 * Convert a decimal amount to integer cents (round half-up).
	 *
	 * @param float|int|string $amount The amount in major currency units.
	 *
	 * @return int Amount in cents.
	 */
	public function toCents(float|int|string $amount): int {
		return (int)round(((float)$amount) * 100);
	}//end toCents()

	/**
	 * Whether a status transition is permitted (REQ-MA-006).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested target status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		if ($from === $to) {
			return true;
		}

		return in_array(needle: $to, haystack: (self::TRANSITIONS[$from] ?? []), strict: true);
	}//end isTransitionAllowed()

	/**
	 * Compute the next status after one paired side posts (REQ-MA-006).
	 *
	 * Once both sides are posted, status moves to `geboekt_beide`; one side
	 * alone advances to `uitgevoerd`. If neither is posted the migration
	 * stays at `voorbereid`.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record.
	 *
	 * @return string The status to write back.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function statusAfterSidePosted(array $migration): string {
		$sourcePosted = ((string)($migration['sourceJournalEntryId'] ?? '')) !== '';
		$destinationPosted = ((string)($migration['destinationJournalEntryId'] ?? '')) !== '';

		if ($sourcePosted === true && $destinationPosted === true) {
			return 'geboekt_beide';
		}

		if ($sourcePosted === true || $destinationPosted === true) {
			return 'uitgevoerd';
		}

		return 'voorbereid';
	}//end statusAfterSidePosted()

	/**
	 * Status after a reversal — terminal `teruggedraaid` from any non-terminal state.
	 *
	 * @param string $currentStatus The current status.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function statusAfterReversal(string $currentStatus): string {
		if ($currentStatus === 'teruggedraaid') {
			return 'teruggedraaid';
		}

		if ($this->isTransitionAllowed(from: $currentStatus, to: 'teruggedraaid') === true) {
			return 'teruggedraaid';
		}

		return $currentStatus;
	}//end statusAfterReversal()

	/**
	 * Resolve the booking value that posts on the source side (REQ-MA-006).
	 *
	 * Source administration disposes of the object: book value leaves the
	 * source ledger; market vs book value differential moves to result.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record.
	 *
	 * @return array{bookCents:int,marketCents:int,resultCents:int}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function computeTransferAmounts(array $migration): array {
		$bookCents = $this->toCents(amount: ($migration['bookValueTransferred'] ?? 0));
		$marketCents = $this->toCents(amount: ($migration['marketValueTransferred'] ?? 0));

		if (array_key_exists('resultImpact', $migration) === true && $migration['resultImpact'] !== null) {
			$resultCents = $this->toCents(amount: $migration['resultImpact']);
		} else {
			// Default: result = market - book (no realisatie when equal).
			$resultCents = ($marketCents - $bookCents);
		}

		return [
			'bookCents' => $bookCents,
			'marketCents' => $marketCents,
			'resultCents' => $resultCents,
		];

	}//end computeTransferAmounts()

	/**
	 * Build the source-side journal entry draft (REQ-MA-006).
	 *
	 * The source administration disposes of the asset/contract/employee
	 * at boekwaarde (debit removal) and recognises the result differential
	 * to P&L. The returned shape is the draft GLTransaction payload the
	 * controller persists in the source administration.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function buildSourceJournalDraft(array $migration): array {
		$amounts = $this->computeTransferAmounts(migration: $migration);

		return [
			'administrationId' => (string)($migration['sourceAdministrationId'] ?? ''),
			'date' => (string)($migration['date'] ?? ''),
			'description' => sprintf(
				'Migratie %s — afvoer (bron)',
				(string)($migration['migrationNumber'] ?? '')
			),
			'kind' => 'migration_source',
			'migrationNumber' => (string)($migration['migrationNumber'] ?? ''),
			'bookValueCents' => $amounts['bookCents'],
			'marketValueCents' => $amounts['marketCents'],
			'resultCents' => $amounts['resultCents'],
			'fiscalTreatment' => (string)($migration['fiscalTreatment'] ?? 'with_actuals'),
			'legalBasis' => (string)($migration['legalBasis'] ?? ''),
		];

	}//end buildSourceJournalDraft()

	/**
	 * Build the destination-side journal entry draft (REQ-MA-006).
	 *
	 * The destination administration activates the asset/contract at
	 * marktwaarde (the overdrachtswaarde — debit addition); any
	 * stille-reserve recognition stays on the source side per RJ
	 * (`met_realisatie`). For `geruisloze_doorschuiving` the destination
	 * inherits the book value instead — the controller chooses the
	 * appropriate draft per fiscale_behandeling.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function buildDestinationJournalDraft(array $migration): array {
		$amounts = $this->computeTransferAmounts(migration: $migration);
		$fiscalTreatment = (string)($migration['fiscalTreatment'] ?? 'with_actuals');

		// Geruisloze doorschuiving: destination inherits the source's book value.
		if ($fiscalTreatment === 'geruisloze_doorschuiving') {
			$activationCents = $amounts['bookCents'];
		} else {
			$activationCents = $amounts['marketCents'];
		}

		return [
			'administrationId' => (string)($migration['destinationAdministrationId'] ?? ''),
			'date' => (string)($migration['date'] ?? ''),
			'description' => sprintf(
				'Migratie %s — aanvoer (doel)',
				(string)($migration['migrationNumber'] ?? '')
			),
			'kind' => 'migration_destination',
			'migrationNumber' => (string)($migration['migrationNumber'] ?? ''),
			'activationCents' => $activationCents,
			'fiscalTreatment' => $fiscalTreatment,
			'legalBasis' => (string)($migration['legalBasis'] ?? ''),
		];

	}//end buildDestinationJournalDraft()

	/**
	 * Build the dual-side draft pair atomically.
	 *
	 * Convenience grouping of `buildSourceJournalDraft` +
	 * `buildDestinationJournalDraft` — the controller persists both in one
	 * DB transaction so the migration is either fully drafted on both
	 * sides or fully rejected. No partial state ever lands in OR.
	 *
	 * The build also enforces the editable-lock — a migration in
	 * `geboekt_beide` or `teruggedraaid` rejects any redraft attempt by
	 * marking both drafts `locked=true`, so the controller can refuse the
	 * write without consulting the service twice.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record.
	 *
	 * @return array{source:array<string,mixed>,destination:array<string,mixed>}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function buildJournalDrafts(array $migration): array {
		$status = (string)($migration['status'] ?? 'voorbereid');
		$locked = ($this->isEditable(status: $status) === false);

		$source = $this->buildSourceJournalDraft(migration: $migration);
		$destination = $this->buildDestinationJournalDraft(migration: $migration);

		$source['locked'] = $locked;
		$destination['locked'] = $locked;

		return [
			'source' => $source,
			'destination' => $destination,
		];

	}//end buildJournalDrafts()

	/**
	 * Build the paired reversal entries (REQ-MA-006).
	 *
	 * Reverses both source and destination journal entries with opposite
	 * debit/credit perspective — the controller persists them in a single
	 * DB transaction so the migration unwinds atomically.
	 *
	 * @param array<string,mixed> $migration The AdministrationMigration record at `geboekt_beide`.
	 *
	 * @return array{source:array<string,mixed>,destination:array<string,mixed>}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function buildReversalEntries(array $migration): array {
		$drafts = $this->buildJournalDrafts(migration: $migration);

		// Invert: reversal swaps sign on every cent amount.
		foreach (['bookValueCents', 'marketValueCents', 'resultCents'] as $key) {
			if (array_key_exists($key, $drafts['source']) === true) {
				$drafts['source'][$key] = (-1 * (int)$drafts['source'][$key]);
			}
		}

		if (array_key_exists('activationCents', $drafts['destination']) === true) {
			$drafts['destination']['activationCents'] = (-1 * (int)$drafts['destination']['activationCents']);
		}

		$drafts['source']['kind'] = 'migration_source_reversal';
		$drafts['destination']['kind'] = 'migration_destination_reversal';
		$drafts['source']['description'] .= ' (teruggedraaid)';
		$drafts['destination']['description'] .= ' (teruggedraaid)';

		return $drafts;
	}//end buildReversalEntries()

	/**
	 * Whether the migration can be edited at its current status.
	 *
	 * Locked at `geboekt_beide` (atomic dual-post completed) and
	 * `teruggedraaid` (terminal). Editable in `voorbereid` and
	 * `uitgevoerd` (still partial; controller may roll back the partial
	 * post on the next mutation).
	 *
	 * @param string $status The current status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-20
	 */
	public function isEditable(string $status): bool {
		return in_array(needle: $status, haystack: ['voorbereid', 'uitgevoerd'], strict: true);
	}//end isEditable()
}//end class
