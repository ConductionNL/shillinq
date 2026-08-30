<?php

/**
 * Shillinq DelegateSigningMigrationRepair Repair Step
 *
 * Idempotent migration that backfills the delegation consumer fields on
 * finance objects that carry a legacy PKI signatureFingerprint (REQ-SIGN-009).
 *
 * For each ACMReport whose signatureFingerprint is non-null and whose
 * signingRequestRef is null, this step:
 *
 *   1. Reads the existing signatureFingerprint value.
 *
 *   2. Writes signingRequestRef = "legacy-local:<fingerprint>" to signal
 *      pre-delegation provenance (REQ-SIGN-009).
 *
 *   3. Writes signingStatus = "signed" (the pre-delegation record is
 *      considered signed; docudesk was not the signer).
 *
 *   4. Is idempotent: objects that already carry a signingRequestRef are
 *      skipped — re-running this step is safe.
 *
 *   5. Is fail-soft: per-record failures are caught and logged but never
 *      abort the migration. An outer \Throwable catch guards the full run.
 *      The repair output uses IOutput::warning so the NC upgrade path is
 *      never blocked.
 *
 *   6. Does NOT remove or modify signatureFingerprint — it is retained for
 *      legacy provenance (REQ-SIGN-009).
 *
 * Runs on `occ maintenance:repair` and on `occ app:enable shillinq`
 * (registered in `appinfo/info.xml` repair-steps).
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
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that backfills the signing-delegation consumer field set on
 * legacy ACMReport objects that carry a PKI signatureFingerprint.
 *
 * Idempotent, fail-soft, never deletes or removes signatureFingerprint.
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-14
 */
class DelegateSigningMigrationRepair implements IRepairStep {
	use ReadsSourceRowsInBatches;

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
	 * The repair-step display name shown in occ maintenance:repair output.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-14
	 */
	public function getName(): string {
		return 'Shillinq: backfill signing-delegation consumer fields on legacy ACMReport objects (REQ-SIGN-009)';
	}//end getName()

	/**
	 * Run the migration. Idempotent — never duplicates fields and never
	 * removes the legacy signatureFingerprint.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-14
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();

			$output->info('Shillinq: DelegateSigningMigrationRepair — backfilling legacy ACMReport signing fields …');

			$result = $this->backfillAcmReports(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info(
				'Shillinq: DelegateSigningMigrationRepair complete — '
				. $result['updated'] . ' updated, ' . $result['skipped'] . ' skipped.'
			);
		} catch (\Throwable $e) {
			// Fail-soft: a migration failure must NOT block the NC upgrade.
			// Log + warn so an operator can re-run via occ maintenance:repair.
			$output->warning('Shillinq: DelegateSigningMigrationRepair failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: DelegateSigningMigrationRepair failed',
				['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
		}//end try

	}//end run()

	/**
	 * Backfill signing-delegation consumer fields on legacy ACMReport objects.
	 *
	 * For each object with a non-null signatureFingerprint and a null
	 * signingRequestRef, writes:
	 *   - signingRequestRef = "legacy-local:<fingerprint>"
	 *   - signingStatus     = "signed"
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param IOutput $output The repair output.
	 *
	 * @return array{updated: int, skipped: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function backfillAcmReports(object $objectService, string $registerSlug, IOutput $output): array {
		$updated = 0;
		$skipped = 0;

		$reports = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'ACMReport');

		if ($reports === []) {
			$output->info('Shillinq: no ACMReport records found — skipping signing-delegation backfill.');
			return ['updated' => 0, 'skipped' => 0];
		}

		foreach ($reports as $report) {
			$arr = $this->rowPayload(row: $report);
			$fingerprint = (string)($arr['signatureFingerprint'] ?? '');
			$signingRef = (string)($arr['signingRequestRef'] ?? '');

			// Skip objects that have no legacy fingerprint.
			if ($fingerprint === '') {
				$skipped++;
				continue;
			}

			// Idempotency: skip objects already migrated.
			if ($signingRef !== '') {
				$skipped++;
				continue;
			}

			$id = (string)($arr['id'] ?? $arr['_id'] ?? '');

			try {
				$arr['signingRequestRef'] = 'legacy-local:' . $fingerprint;
				$arr['signingStatus'] = 'signed';

				$objectService->saveObject(
					object: $arr,
					register: $registerSlug,
					schema: 'ACMReport',
					_rbac: false,
					_multitenancy: false,
				);
				$updated++;
			} catch (\Throwable $e) {
				// Per-record failure is soft — log and continue.
				$output->warning('Shillinq: DelegateSigningMigrationRepair failed for ACMReport id=' . $id . ': ' . $e->getMessage());
				$this->logger->warning(
					'Shillinq: DelegateSigningMigrationRepair — ACMReport backfill failed for id=' . $id,
					['exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return ['updated' => $updated, 'skipped' => $skipped];
	}//end backfillAcmReports()
}//end class
