<?php

/**
 * Shillinq StampJournalTypeOnGlTransactions Repair Step
 *
 * Idempotent repair step that stamps the new `journalType` discriminator
 * (and the folded closing/intercompany field groups) onto existing
 * `GLTransaction` records, completing the GLTransaction-family
 * abstraction merge.
 *
 * The former dedicated `ClosingEntry` and `IntercompanyTransaction`
 * schemas are folded into `GLTransaction` via the
 * `abstract-gltransaction-types` register fragment. This repair step
 * reconciles the legacy data so each GLTransaction carries the correct
 * `journalType`:
 *
 *   1. Every `ClosingEntry` carrying a `glTransactionId` resolves the
 *      target GLTransaction and stamps `journalType='closing'` plus the
 *      folded `closingEntryType` (from `entryType`), `fiscalYearId`, and
 *      `approvalStatus`.
 *   2. Every `IntercompanyTransaction` carrying a `sourceJournalEntryId`
 *      resolves the target GLTransaction and stamps
 *      `journalType='intercompany'` plus the folded
 *      `sourceAdministrationId`, `counterpartyEntityId`,
 *      `detectionMethod`, `detectionConfidence`, `isMatched`, and
 *      `matchId`.
 *   3. Any GLTransaction not linked from either source is left
 *      untouched (it keeps the schema default `journalType='manual'`).
 *
 * The source `ClosingEntry` / `IntercompanyTransaction` records are
 * NEVER deleted — this step only enriches the GLTransaction side.
 *
 * Idempotent — a GLTransaction already carrying the target
 * `journalType` is skipped, so re-runs of `occ maintenance:repair` (or a
 * fresh `occ app:enable shillinq`) never re-stamp or duplicate state.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that stamps `journalType` (and the folded closing /
 * intercompany field groups) onto existing GLTransaction records from
 * their legacy ClosingEntry / IntercompanyTransaction sources.
 */
class StampJournalTypeOnGlTransactions implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ContainerInterface $container The DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 */
	public function getName(): string {
		return 'Shillinq: stamp journalType (closing/intercompany) onto GLTransaction records';
	}//end getName()

	/**
	 * Run the stamp. Idempotent — a GLTransaction already carrying the
	 * target journalType is skipped.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses every write for 'Anonymous'. Without it this stamp updates
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $this->resolveObjectServiceForIdentity(),
			work: function () use ($output): void {
				$this->runInner(output: $output);
			}
		);
	}//end run()

	/**
	 * OpenRegister's ObjectService, or null when it cannot be resolved.
	 *
	 * Null is not fatal: {@see withSystemIdentity()} then runs the work anyway,
	 * exactly as it ran before this wrapper existed.
	 *
	 * @return object|null The service.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function resolveObjectServiceForIdentity(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveObjectServiceForIdentity()

	/**
	 * The stamp itself.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();

			$closingStamped = $this->stampClosing(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);
			$intercompanyStamped = $this->stampIntercompany(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info(
				'Shillinq: journalType stamp complete — '
				. $closingStamped . ' closing, '
				. $intercompanyStamped . ' intercompany GLTransaction(s) stamped.'
			);
		} catch (\Throwable $e) {
			// Stamp is best-effort: failing it must NOT block the app
			// upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: journalType stamp failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: journalType stamp failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end runInner()

	/**
	 * Stamp journalType='closing' (+ folded closing fields) onto every
	 * GLTransaction linked from a ClosingEntry via `glTransactionId`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return int The number of GLTransaction records stamped.
	 */
	private function stampClosing(
		object $objectService,
		string $registerSlug,
		IOutput $output,
	): int {
		$closingEntries = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'ClosingEntry');

		if ($closingEntries === []) {
			return 0;
		}

		$stamped = 0;
		foreach ($closingEntries as $entry) {
			try {
				$arr = $this->rowPayload(row: $entry);
				$glTransactionId = (string)($arr['glTransactionId'] ?? '');
				if ($glTransactionId === '') {
					continue;
				}

				$transaction = $this->findGlTransaction(
					objectService: $objectService,
					registerSlug: $registerSlug,
					id: $glTransactionId
				);
				if ($transaction === null) {
					continue;
				}

				// Idempotent: already stamped → skip.
				if (((string)($transaction['journalType'] ?? '')) === 'closing') {
					continue;
				}

				$transaction['journalType'] = 'closing';

				$entryType = (string)($arr['entryType'] ?? '');
				if ($entryType !== '') {
					$transaction['closingEntryType'] = $entryType;
				}

				$fiscalYearId = (string)($arr['fiscalYearId'] ?? '');
				if ($fiscalYearId !== '') {
					$transaction['fiscalYearId'] = $fiscalYearId;
				}

				$approvalStatus = (string)($arr['approvalStatus'] ?? '');
				if ($approvalStatus !== '') {
					$transaction['approvalStatus'] = $approvalStatus;
				}

				$objectService->saveObject(
					object: $transaction,
					register: $registerSlug,
					schema: 'GLTransaction',
					_rbac: false,
					_multitenancy: false,
				);
				$stamped++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: failed to stamp closing GLTransaction: ' . $e->getMessage()
				);
			}//end try
		}//end foreach

		return $stamped;
	}//end stampClosing()

	/**
	 * Stamp journalType='intercompany' (+ folded intercompany fields)
	 * onto every GLTransaction linked from an IntercompanyTransaction via
	 * `sourceJournalEntryId`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return int The number of GLTransaction records stamped.
	 */
	private function stampIntercompany(
		object $objectService,
		string $registerSlug,
		IOutput $output,
	): int {
		$intercompany = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'IntercompanyTransaction');

		if ($intercompany === []) {
			return 0;
		}

		$stamped = 0;
		foreach ($intercompany as $ic) {
			try {
				$arr = $this->rowPayload(row: $ic);
				$glTransactionId = (string)($arr['sourceJournalEntryId'] ?? '');
				if ($glTransactionId === '') {
					continue;
				}

				$transaction = $this->findGlTransaction(
					objectService: $objectService,
					registerSlug: $registerSlug,
					id: $glTransactionId
				);
				if ($transaction === null) {
					continue;
				}

				// Idempotent: already stamped → skip.
				if (((string)($transaction['journalType'] ?? '')) === 'intercompany') {
					continue;
				}

				$transaction['journalType'] = 'intercompany';

				$sourceAdministrationId = (string)($arr['sourceAdministrationId'] ?? '');
				if ($sourceAdministrationId !== '') {
					$transaction['sourceAdministrationId'] = $sourceAdministrationId;
				}

				$counterpartyEntityId = (string)($arr['counterpartyEntityId'] ?? '');
				if ($counterpartyEntityId !== '') {
					$transaction['counterpartyEntityId'] = $counterpartyEntityId;
				}

				$detectionMethod = (string)($arr['detectionMethod'] ?? '');
				if ($detectionMethod !== '') {
					$transaction['detectionMethod'] = $detectionMethod;
				}

				$detectionConfidence = (string)($arr['detectionConfidence'] ?? '');
				if ($detectionConfidence !== '') {
					$transaction['detectionConfidence'] = $detectionConfidence;
				}

				if (array_key_exists('isMatched', $arr) === true) {
					$transaction['isMatched'] = (bool)$arr['isMatched'];
				}

				$matchId = (string)($arr['matchId'] ?? '');
				if ($matchId !== '') {
					$transaction['matchId'] = $matchId;
				}

				$objectService->saveObject(
					object: $transaction,
					register: $registerSlug,
					schema: 'GLTransaction',
					_rbac: false,
					_multitenancy: false,
				);
				$stamped++;
			} catch (\Throwable $e) {
				$output->warning(
					'Shillinq: failed to stamp intercompany GLTransaction: ' . $e->getMessage()
				);
			}//end try
		}//end foreach

		return $stamped;
	}//end stampIntercompany()

	/**
	 * Resolve a single GLTransaction record by its OpenRegister id/uuid,
	 * returned as the plain object-data array (carrying its `id`, so a
	 * subsequent saveObject is an UPDATE not a CREATE). Null when not
	 * found.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $id The GLTransaction id/uuid.
	 *
	 * @return array<string,mixed>|null The GLTransaction object data, or null.
	 */
	private function findGlTransaction(
		object $objectService,
		string $registerSlug,
		string $id,
	): ?array {
		try {
			$found = $objectService
				->setRegister($registerSlug)
				->setSchema('GLTransaction')
				->find(
					id: $id,
					_rbac: false,
					_multitenancy: false
				);
		} catch (\Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		// GetObject() returns the clean object data with `id` prepended,
		// which makes the later saveObject resolve as an UPDATE.
		$data = $found->getObject();
		if (is_array($data) === false || $data === []) {
			return null;
		}

		return $data;
	}//end findGlTransaction()
}//end class
