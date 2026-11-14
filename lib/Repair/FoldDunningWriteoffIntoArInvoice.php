<?php

/**
 * Shillinq FoldDunningWriteoffIntoArInvoice Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

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
	 * @param RegisterLocationInterface $settingsService Where the register lives (slug + OpenRegister availability).
	 * @param IGroupManager $groupManager The group manager (admin IUser resolution).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private RegisterLocationInterface $settingsService,
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
			$this->fold(output: $output);
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
	 * Fold every OninbaarAfschrijving write-off onto its ARInvoice and report the totals.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
	 */
	private function fold(IOutput $output): void {
		$registerSlug = $this->settingsService->getRegisterSlug();
		$actingUser   = $this->resolveAdminUser();

		// Stream every OninbaarAfschrijving write-off row. RBAC +
		// multi-tenancy are bypassed: the repair runs in the
		// installer/upgrade context with no authenticated session.
		$writeOffs = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'OninbaarAfschrijving');

		if ($writeOffs === []) {
			$output->info('Shillinq: no OninbaarAfschrijving rows — ARInvoice writeOff/dunning fold skipped.');
			return;
		}

		$counts = ['folded' => 0, 'skipped' => 0, 'missing' => 0];
		foreach ($writeOffs as $writeOff) {
			$counts[$this->foldOne(output: $output, writeOff: $writeOff, registerSlug: $registerSlug, actingUser: $actingUser)]++;
		}

		$this->reportFolded(output: $output, counts: $counts);

	}//end fold()

	/**
	 * Report the fold totals.
	 *
	 * @param IOutput            $output The repair-step output.
	 * @param array<string, int> $counts The folded, skipped and missing totals.
	 *
	 * @return void
	 */
	private function reportFolded(IOutput $output, array $counts): void {
		$output->info(
			'Shillinq: ARInvoice writeOff/dunning fold complete — ' . $counts['folded'] . ' folded, ' . $counts['skipped']
			. ' skipped (already folded / no link), ' . $counts['missing'] . ' invoices missing.'
		);

	}//end reportFolded()

	/**
	 * Fold one write-off row onto its linked ARInvoice.
	 *
	 * @param IOutput    $output       The repair-step output.
	 * @param mixed      $writeOff     One OninbaarAfschrijving row.
	 * @param string     $registerSlug The register slug.
	 * @param IUser|null $actingUser   The acting admin user, or null.
	 *
	 * @return string The outcome: folded, skipped or missing.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
	 */
	private function foldOne(IOutput $output, mixed $writeOff, string $registerSlug, IUser|null $actingUser): string {
		$row       = $this->rowPayload(row: $writeOff);
		$invoiceId = (string)($row['invoiceId'] ?? '');
		if ($invoiceId === '') {
			return 'skipped';
		}

		$invoice = $this->findInvoice(registerSlug: $registerSlug, invoiceId: $invoiceId);
		if ($invoice === null) {
			$output->warning(
				'Shillinq: ARInvoice ' . $invoiceId . ' for OninbaarAfschrijving not found — fold skipped for this row.'
			);
			return 'missing';
		}

		return $this->foldIntoInvoice(invoiceArr: $invoice->jsonSerialize(), row: $row, registerSlug: $registerSlug, actingUser: $actingUser);

	}//end foldOne()

	/**
	 * Load the linked ARInvoice. factuurId is the ARInvoice UUID.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $invoiceId    The ARInvoice UUID.
	 *
	 * @return mixed The ARInvoice entity, or null when it cannot be loaded.
	 */
	private function findInvoice(string $registerSlug, string $invoiceId): mixed {
		$invoice = null;
		try {
			$invoice = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($registerSlug)->setSchema('ARInvoice'),
				id: $invoiceId,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (\Throwable $e) {
			$invoice = null;
		}

		return $invoice;

	}//end findInvoice()

	/**
	 * Write the writeOff and dunning groups onto the invoice and save it, unless already folded.
	 *
	 * @param array<string, mixed> $invoiceArr   The serialised ARInvoice.
	 * @param array<string, mixed> $row          The OninbaarAfschrijving payload.
	 * @param string               $registerSlug The register slug.
	 * @param IUser|null           $actingUser   The acting admin user, or null.
	 *
	 * @return string The outcome: folded or skipped.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-010
	 */
	private function foldIntoInvoice(array $invoiceArr, array $row, string $registerSlug, IUser|null $actingUser): string {
		$entryId = (string)($row['entryId'] ?? '');
		if ($this->isAlreadyFolded(invoiceArr: $invoiceArr, entryId: $entryId) === true) {
			return 'skipped';
		}

		$invoiceArr['writeOff'] = $this->writeOffGroup(row: $row, entryId: $entryId);

		$this->saveFolded(invoiceArr: $invoiceArr, invoiceId: (string)($row['invoiceId'] ?? ''), registerSlug: $registerSlug, actingUser: $actingUser);
		return 'folded';

	}//end foldIntoInvoice()

	/**
	 * Attach the dunning summary when one exists and save the folded ARInvoice.
	 *
	 * @param array<string, mixed> $invoiceArr   The serialised ARInvoice with its writeOff group.
	 * @param string               $invoiceId    The ARInvoice UUID.
	 * @param string               $registerSlug The register slug.
	 * @param IUser|null           $actingUser   The acting admin user, or null.
	 *
	 * @return void
	 */
	private function saveFolded(array $invoiceArr, string $invoiceId, string $registerSlug, IUser|null $actingUser): void {
		// Mirror the lifecycle onto the discriminator-adjacent flag
		// only when the invoice has no explicit invoiceType yet:
		// a written-off invoice is still a 'standard' document, so
		// leave invoiceType untouched here (fold is write-off only).
		// Derive the dunning summary from the latest DunningRun for
		// this factuurId. Absent any run, dunning is left null.
		$dunning = $this->deriveDunningSummary(objectService: $this->objectService, registerSlug: $registerSlug, invoiceId: $invoiceId);
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

	}//end saveFolded()

	/**
	 * IDEMPOTENT: detect an already-folded write-off by the stable GLTransaction booking key carried on writeOff.
	 *
	 * @param array<string, mixed> $invoiceArr The serialised ARInvoice.
	 * @param string               $entryId    The write-off's GLTransaction booking key.
	 *
	 * @return bool
	 */
	private function isAlreadyFolded(array $invoiceArr, string $entryId): bool {
		$existing = (array)($invoiceArr['writeOff'] ?? []);
		return (($existing['isWrittenOff'] ?? false) === true
			&& $entryId !== ''
			&& (string)($existing['writtenOffGLTransactionId'] ?? '') === $entryId);

	}//end isAlreadyFolded()

	/**
	 * Map the FULL OninbaarAfschrijving field set onto writeOff.
	 *
	 * Optional source fields are absent on many real rows (only
	 * factuurId/hoofdsomAfgeschreven/art29OBVerklaring/administrationId
	 * are required on OninbaarAfschrijving), so a null must NOT be
	 * written for a typed target field (e.g. writeOff.btwBedrag is a
	 * number) — an absent optional property is valid, a null is not.
	 * Prune null-valued keys before saving (#382 live e2e).
	 *
	 * @param array<string, mixed> $row     The OninbaarAfschrijving payload.
	 * @param string               $entryId The write-off's GLTransaction booking key.
	 *
	 * @return array<string, mixed>
	 */
	private function writeOffGroup(array $row, string $entryId): array {
		$declaration = (string)($row['art29OBDeclaration'] ?? '');
		$writeOff    = [
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
		return array_filter($writeOff, static fn ($v) => $v !== null);

	}//end writeOffGroup()

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
		$runs = $this->readDunningRuns(objectService: $objectService, registerSlug: $registerSlug, invoiceId: $invoiceId);
		if ($runs === []) {
			return null;
		}

		$latest = $this->latestDunningRun(runs: $runs);
		if ($latest === null) {
			return null;
		}

		return $this->dunningSummary(latest: $latest);

	}//end deriveDunningSummary()

	/**
	 * Read the DunningRun rows targeting an invoice; empty when none or when the read fails.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param string $invoiceId     The ARInvoice UUID the runs target.
	 *
	 * @return array<int, mixed>
	 */
	private function readDunningRuns(object $objectService, string $registerSlug, string $invoiceId): array {
		try {
			return $this->readAllRows(
				objectService: $objectService,
				registerSlug: $registerSlug,
				schema: 'DunningRun',
				filters: ['invoiceId' => $invoiceId]
			);
		} catch (\Throwable $e) {
			return [];
		}

	}//end readDunningRuns()

	/**
	 * Pick the run with the highest stageNr, ties broken by the most recent executedOn.
	 *
	 * @param array<int, mixed> $runs The DunningRun rows.
	 *
	 * @return array<string, mixed>|null The latest run payload, or null.
	 */
	private function latestDunningRun(array $runs): array|null {
		$latest      = null;
		$latestStage = -1;
		$latestExec  = '';
		foreach ($runs as $run) {
			$runArr = $this->rowPayload(row: $run);
			$stage  = (int)($runArr['stageNr'] ?? 0);
			$exec   = (string)($runArr['executedOn'] ?? '');

			if ($this->isNewerRun(stage: $stage, exec: $exec, latestStage: $latestStage, latestExec: $latestExec) === true) {
				$latest      = $runArr;
				$latestStage = $stage;
				$latestExec  = $exec;
			}
		}

		return $latest;

	}//end latestDunningRun()

	/**
	 * Whether a run outranks the current latest: a higher stage, or the same stage executed later.
	 *
	 * @param int    $stage       The run's stage number.
	 * @param string $exec        The run's execution date.
	 * @param int    $latestStage The current latest stage number.
	 * @param string $latestExec  The current latest execution date.
	 *
	 * @return bool
	 */
	private function isNewerRun(int $stage, string $exec, int $latestStage, string $latestExec): bool {
		$isNewer = false;
		if ($stage > $latestStage) {
			$isNewer = true;
		} elseif ($stage === $latestStage && $exec > $latestExec) {
			$isNewer = true;
		}

		return $isNewer;

	}//end isNewerRun()

	/**
	 * Map the latest DunningRun onto the ARInvoice.dunning group.
	 *
	 * @param array<string, mixed> $latest The latest run payload.
	 *
	 * @return array<string, mixed>
	 */
	private function dunningSummary(array $latest): array {
		return [
			'currentStage' => (int)($latest['stageNr'] ?? 0),
			'nextDunningDate' => null,
			'collectionCostAmount' => $this->numOrNull($latest['collectionCostAmount'] ?? null),
			'interestAmount' => $this->numOrNull($latest['interestAmount'] ?? null),
			'activeLadderId' => $this->strOrNull($latest['ladderId'] ?? null),
		];

	}//end dunningSummary()

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
