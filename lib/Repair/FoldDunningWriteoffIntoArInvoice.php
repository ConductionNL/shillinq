<?php

/**
 * Shillinq FoldDunningWriteoffIntoArInvoice Repair Step
 *
 * Idempotent repair step that folds the dunning (credit-control) and
 * write-off (oninbaar) sub-concepts onto the existing ARInvoice schema
 * (per the `abstract-arinvoice-types` change).
 *
 * For every OninbaarAfschrijving row it maps the FULL eight-field source
 * set onto the linked ARInvoice `writeOff` group, and for the same
 * invoice it derives a `dunning` summary from the latest DunningRun
 * targeting that factuurId.
 *
 *   1. Lists every OninbaarAfschrijving via the OpenRegister ObjectService
 *      `findAll` (RBAC + multi-tenancy bypassed — repair runs with no
 *      session).
 *   2. For each row, loads the linked ARInvoice (by `factuurId` =
 *      ARInvoice UUID). Skips when the invoice already carries this
 *      write-off booking (idempotent — detected by the stable
 *      `writtenOffGLTransactionId` key).
 *   3. Maps all OninbaarAfschrijving fields onto ARInvoice.writeOff,
 *      derives a categorised `writtenOffReason` from the art. 29 OB
 *      declaration, then derives the ARInvoice.dunning summary from the
 *      latest DunningRun for the same factuurId.
 *   4. Persists the ARInvoice additively.
 *
 * Source rows (DunningRun / OninbaarAfschrijving) are NEVER deleted.
 *
 * Idempotent — re-runs of `occ maintenance:repair` (or a fresh
 * `occ app:enable shillinq`) never re-apply a write-off already folded.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repair step that folds DunningRun + OninbaarAfschrijving onto ARInvoice.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
 */
class FoldDunningWriteoffIntoArInvoice implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param IGroupManager $groupManager The group manager (admin IUser resolution).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 */
	public function getName(): string {
		return 'Shillinq: fold DunningRun + OninbaarAfschrijving onto ARInvoice (dunning / writeOff groups)';
	}//end getName()

	/**
	 * Run the fold. Idempotent — never re-applies a write-off already folded.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$actingUser = $this->resolveAdminUser();

			// Stream every OninbaarAfschrijving write-off row. RBAC +
			// multi-tenancy are bypassed: the repair runs in the
			// installer/upgrade context with no authenticated session.
			$writeOffs = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'OninbaarAfschrijving');

			if ($writeOffs === []) {
				$output->info('Shillinq: no OninbaarAfschrijving rows — ARInvoice writeOff/dunning fold skipped.');
				return;
			}

			$folded = 0;
			$skipped = 0;
			$missing = 0;
			foreach ($writeOffs as $writeOff) {
				$row = $this->rowPayload(row: $writeOff);
				$invoiceId = (string)($row['invoiceId'] ?? '');
				if ($invoiceId === '') {
					$skipped++;
					continue;
				}

				// Load the linked ARInvoice. factuurId is the ARInvoice UUID.
				$invoice = null;
				try {
					$invoice = $this->objectService->find(
						id: $invoiceId,
						register: $registerSlug,
						schema: 'ARInvoice',
						_rbac: false,
						_multitenancy: false,
					);
				} catch (\Throwable $e) {
					$invoice = null;
				}

				if ($invoice === null) {
					$output->warning(
						'Shillinq: ARInvoice ' . $invoiceId . ' for OninbaarAfschrijving not found — fold skipped for this row.'
					);
					$missing++;
					continue;
				}

				$invoiceArr = $invoice->jsonSerialize();

				// IDEMPOTENT: detect an already-folded write-off by the
				// stable GLTransaction booking key carried on writeOff.
				$entryId = (string)($row['entryId'] ?? '');
				$existing = (array)($invoiceArr['writeOff'] ?? []);
				if (($existing['isWrittenOff'] ?? false) === true
					&& $entryId !== ''
					&& (string)($existing['writtenOffGLTransactionId'] ?? '') === $entryId
				) {
					$skipped++;
					continue;
				}

				// Map the FULL OninbaarAfschrijving field set onto writeOff.
				// Optional source fields are absent on many real rows (only
				// factuurId/hoofdsomAfgeschreven/art29OBVerklaring/administrationId
				// are required on OninbaarAfschrijving), so a null must NOT be
				// written for a typed target field (e.g. writeOff.btwBedrag is a
				// number) — an absent optional property is valid, a null is not.
				// Prune null-valued keys before saving (#382 live e2e).
				$declaration = (string)($row['art29OBDeclaration'] ?? '');
				$writeOff = [
					'isWrittenOff' => true,
					'writtenOffReason' => $this->deriveWriteOffReason(declaration: $declaration),
					'art29OBDeclaration' => $declaration,
					'principalDepreciated' => $this->numOrNull($row['principalDepreciated'] ?? null),
					'vatAmount' => $this->numOrNull($row['vatAmount'] ?? null),
					'evidenceRef' => $this->strOrNull($row['evidenceRef'] ?? null),
					'writtenOffGLTransactionId' => ($entryId !== '' ? $entryId : null),
					'vatRefundPeriod' => $this->strOrNull($row['vatTaxReturnPeriod'] ?? null),
					'administrationId' => $this->strOrNull($row['administrationId'] ?? null),
				];
				$invoiceArr['writeOff'] = array_filter($writeOff, static fn ($v) => $v !== null);

				// Mirror the lifecycle onto the discriminator-adjacent flag
				// only when the invoice has no explicit invoiceType yet:
				// a written-off invoice is still a 'standard' document, so
				// leave invoiceType untouched here (fold is write-off only).
				// Derive the dunning summary from the latest DunningRun for
				// this factuurId. Absent any run, dunning is left null.
				$dunning = $this->deriveDunningSummary(
					objectService: $this->objectService,
					registerSlug: $registerSlug,
					invoiceId: $invoiceId
				);
				if ($dunning !== null) {
					$invoiceArr['dunning'] = $dunning;
				}

				$this->objectService->saveObject(
					object: $invoiceArr,
					register: $registerSlug,
					schema: 'ARInvoice',
					_rbac: false,
					_multitenancy: false,
					currentUser: $actingUser,
				);
				$folded++;
			}//end foreach

			$output->info(
				'Shillinq: ARInvoice writeOff/dunning fold complete — ' . $folded . ' folded, ' . $skipped
				. ' skipped (already folded / no link), ' . $missing . ' invoices missing.'
			);
		} catch (\Throwable $e) {
			// Fold is best-effort: failing it must NOT block the app upgrade.
			$output->warning('Shillinq: ARInvoice writeOff/dunning fold failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: ARInvoice writeOff/dunning fold failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Resolve an admin user as an IUser object (NEVER a string). The
	 * repair runs without a session, so OR's `@self.folder` access checks
	 * need an explicit acting user. Falls back to null when no admin can
	 * be resolved (OR then uses its own resolution).
	 *
	 * @return IUser|null The first admin IUser, or null.
	 */
	private function resolveAdminUser(): ?IUser {
		try {
			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup === null) {
				return null;
			}

			$users = $adminGroup->getUsers();
			foreach ($users as $user) {
				if ($user instanceof IUser) {
					return $user;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: could not resolve admin IUser for ARInvoice fold',
				['exception' => $e->getMessage()]
			);
		}

		return null;
	}//end resolveAdminUser()

	/**
	 * Derive the latest-DunningRun summary for an invoice, mapped onto the
	 * ARInvoice.dunning group. Picks the run with the highest stageNr
	 * (ties broken by the most recent uitgevoerdOp). Returns null when no
	 * DunningRun targets this factuurId.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $invoiceId The ARInvoice UUID the runs target.
	 *
	 * @return array<string,mixed>|null The dunning summary group, or null.
	 */
	private function deriveDunningSummary(
		object $objectService,
		string $registerSlug,
		string $invoiceId,
	): ?array {
		try {
			$runs = $this->readAllRows(
				objectService: $objectService,
				registerSlug: $registerSlug,
				schema: 'DunningRun',
				filters: ['invoiceId' => $invoiceId]
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($runs === []) {
			return null;
		}

		$latest = null;
		$latestStage = -1;
		$latestExec = '';
		foreach ($runs as $run) {
			$runArr = $this->rowPayload(row: $run);
			$stage = (int)($runArr['stageNr'] ?? 0);
			$exec = (string)($runArr['executedOn'] ?? '');

			$isNewer = false;
			if ($stage > $latestStage) {
				$isNewer = true;
			} elseif ($stage === $latestStage && $exec > $latestExec) {
				$isNewer = true;
			}

			if ($isNewer === true) {
				$latest = $runArr;
				$latestStage = $stage;
				$latestExec = $exec;
			}
		}//end foreach

		if ($latest === null) {
			return null;
		}

		return [
			'currentStage' => (int)($latest['stageNr'] ?? 0),
			'nextDunningDate' => null,
			'collectionCostAmount' => $this->numOrNull($latest['collectionCostAmount'] ?? null),
			'interestAmount' => $this->numOrNull($latest['interestAmount'] ?? null),
			'activeLadderId' => $this->strOrNull($latest['ladderId'] ?? null),
		];

	}//end deriveDunningSummary()

	/**
	 * Derive the categorised writeOff.writtenOffReason enum value from the
	 * free-text art. 29 OB declaration.
	 *
	 * @param string $declaration The art29OBVerklaring text.
	 *
	 * @return string The enum value (Faillissement | Schuldsanering | 1jaar-onbetaald | overig).
	 */
	private function deriveWriteOffReason(string $declaration): string {
		$haystack = mb_strtolower($declaration);

		if (str_contains($haystack, 'faillissement') === true) {
			return 'Faillissement';
		}

		if (str_contains($haystack, 'schuldsanering') === true
			|| str_contains($haystack, 'wsnp') === true
		) {
			return 'Schuldsanering';
		}

		if (str_contains($haystack, '1jaar') === true
			|| str_contains($haystack, '1 jaar') === true
			|| str_contains($haystack, 'jaar onbetaald') === true
			|| str_contains($haystack, 'een jaar') === true
		) {
			return '1jaar-onbetaald';
		}

		return 'other';
	}//end deriveWriteOffReason()

	/**
	 * Coerce a value to a float, or null when not numeric.
	 *
	 * @param mixed $value The source value.
	 *
	 * @return float|null The float, or null.
	 */
	private function numOrNull(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value) === true) {
			return (float)$value;
		}

		return null;
	}//end numOrNull()

	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value The source value.
	 *
	 * @return string|null The string, or null.
	 */
	private function strOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = (string)$value;
		if ($string === '') {
			return null;
		}

		return $string;
	}//end strOrNull()
}//end class
