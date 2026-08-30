<?php

/**
 * Shillinq FoldExpensesAndHoursIntoProject Repair Step
 *
 * Idempotent repair step that folds the previously-separate expense and
 * time-registration objects into the owning `Project` record, matching
 * the Order-family abstraction merge (`abstract-project-cost-lines`
 * register fragment which adds `Project.costLines` and
 * `Project.hoursLines`).
 *
 * For every `Receipt`, `MileageEntry` and `PerDiem` it appends a
 * `costLine` to the matching `Project`; for every `UrenRegistratie`
 * carrying a `projectId` it appends a `hoursLine`. Expense objects are
 * associated with a project either via a `projectId` carried directly on
 * the object, or — for claim-routed expenses — via the
 * `claimId → ExpenseClaimEntry` join (the claim may itself carry a
 * `projectId`). Every source field is mapped onto the folded line and a
 * stable `sourceId` (the source object's OpenRegister id/uuid) is stamped
 * so re-runs never duplicate lines.
 *
 * Idempotent — a source row whose `sourceId` is already present on the
 * target project's `costLines`/`hoursLines` is skipped. Source rows are
 * NEVER deleted (post-fold cleanup is a separate, deliberate operation).
 * Runs fail-soft per row so a single bad row never aborts the upgrade.
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

use DateTimeImmutable;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Repair step that folds Receipt/MileageEntry/PerDiem into
 * Project.costLines and UrenRegistratie into Project.hoursLines.
 */
class FoldExpensesAndHoursIntoProject implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (register slug).
	 * @param IGroupManager $groupManager The group manager (resolve an admin IUser).
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
		return 'Shillinq: fold Receipt/MileageEntry/PerDiem and UrenRegistratie into Project cost/hours lines';
	}//end getName()

	/**
	 * Run the fold. Idempotent — never duplicates lines and never
	 * deletes source rows.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$admin = $this->resolveAdmin();

			// Index every Project by every identifier a source object might
			// reference (id / uuid / projectNumber / code). Values are kept
			// as live mutable arrays so multiple lines fold into one save.
			$projects = $this->readAllRows(objectService: $this->objectService, registerSlug: $registerSlug, schema: 'Project');

			if ($projects === []) {
				$output->info('Shillinq: no Project records — expense/hours fold skipped.');
				return;
			}

			[$byKey, $projectArrays] = $this->indexProjects(projects: $projects);

			// Pre-load ExpenseClaimEntry rows so claim-routed expenses can
			// resolve a projectId via the claim (keyed by claim id/number).
			$claimIndex = $this->indexClaims(objectService: $this->objectService, registerSlug: $registerSlug);

			$touched = [];

			// Fold expense families → costLines.
			$touched = ($this->foldCostFamily(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: 'Receipt',
				type: 'receipt',
				byKey: $byKey,
				projectArrays: $projectArrays,
				claimIndex: $claimIndex,
				mapper: [$this, 'mapReceipt'],
				output: $output
			) + $touched);

			$touched = ($this->foldCostFamily(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: 'MileageEntry',
				type: 'mileage',
				byKey: $byKey,
				projectArrays: $projectArrays,
				claimIndex: $claimIndex,
				mapper: [$this, 'mapMileage'],
				output: $output
			) + $touched);

			$touched = ($this->foldCostFamily(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: 'PerDiem',
				type: 'perdiem',
				byKey: $byKey,
				projectArrays: $projectArrays,
				claimIndex: $claimIndex,
				mapper: [$this, 'mapPerDiem'],
				output: $output
			) + $touched);

			// Fold UrenRegistratie → hoursLines.
			$touched = ($this->foldHours(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				byKey: $byKey,
				projectArrays: $projectArrays,
				output: $output
			) + $touched);

			// Persist every project we appended at least one line to.
			$saved = 0;
			foreach (array_keys($touched) as $projectKey) {
				if (isset($projectArrays[$projectKey]) === false) {
					continue;
				}

				$record = $projectArrays[$projectKey];
				try {
					$this->objectService
						->setRegister($registerSlug)
						->setSchema('Project')
						->saveObject(
							object: $record,
							register: $registerSlug,
							schema: 'Project',
							_rbac: false,
							_multitenancy: false,
						);
					$saved++;
				} catch (\Throwable $e) {
					$output->warning(
						'Shillinq: fold — failed to save Project ' . $projectKey . ': ' . $e->getMessage()
					);
				}
			}//end foreach

			// Touch $admin to document the resolved installer user (kept for
			// parity with RBAC-bypassing repair steps that stamp an owner).
			unset($admin);

			$output->info(
				'Shillinq: expense/hours fold complete — ' . $saved . ' Project record(s) updated.'
			);
		} catch (\Throwable $e) {
			// Fold is best-effort: failing it must NOT block the app
			// upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: expense/hours fold failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: expense/hours fold failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()

	/**
	 * Resolve an admin IUser object (never a string). Repair steps run
	 * with no web session; an explicit owner is required by some OR
	 * code paths.
	 *
	 * @return IUser|null The first admin user, or null when none exists.
	 */
	private function resolveAdmin(): ?IUser {
		$group = $this->groupManager->get('admin');
		if ($group === null) {
			return null;
		}

		$users = $group->getUsers();
		if ($users === []) {
			return null;
		}

		return reset($users);
	}//end resolveAdmin()

	/**
	 * Index projects by every identifier a source object might reference.
	 *
	 * @param array<int,mixed> $projects The Project records.
	 *
	 * @return array{0:array<string,string>,1:array<string,array<string,mixed>>} A
	 *                                                                           tuple of [identifier → projectKey] and [projectKey → mutable record].
	 */
	private function indexProjects(array $projects): array {
		$byKey = [];
		$projectArrays = [];

		foreach ($projects as $project) {
			$arr = $this->rowPayload(row: $project);
			$id = (string)($arr['id'] ?? '');
			if ($id === '') {
				$id = (string)($arr['uuid'] ?? '');
			}

			if ($id === '') {
				continue;
			}

			// Ensure the save targets this same row and the line arrays exist.
			if (isset($arr['id']) === false) {
				$arr['id'] = $id;
			}

			if (is_array($arr['costLines'] ?? null) === false) {
				$arr['costLines'] = [];
			}

			if (is_array($arr['hoursLines'] ?? null) === false) {
				$arr['hoursLines'] = [];
			}

			$projectArrays[$id] = $arr;

			foreach ([$id, (string)($arr['uuid'] ?? ''), (string)($arr['projectNumber'] ?? ''), (string)($arr['code'] ?? '')] as $alias) {
				if ($alias !== '') {
					$byKey[$alias] = $id;
				}
			}
		}//end foreach

		return [$byKey, $projectArrays];
	}//end indexProjects()

	/**
	 * Build a claimId → projectId index from ExpenseClaimEntry rows, so a
	 * claim-routed expense can inherit the project from its claim.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 *
	 * @return array<string,string> Map of claim id/number → projectId.
	 */
	private function indexClaims(object $objectService, string $registerSlug): array {
		$index = [];

		try {
			$claims = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'ExpenseClaimEntry');
		} catch (\Throwable $e) {
			return $index;
		}

		foreach ($claims as $claim) {
			$arr = $this->rowPayload(row: $claim);
			$projectId = (string)($arr['projectId'] ?? '');
			if ($projectId === '') {
				continue;
			}

			foreach ([(string)($arr['id'] ?? ''), (string)($arr['uuid'] ?? ''), (string)($arr['claimNumber'] ?? '')] as $claimKey) {
				if ($claimKey !== '') {
					$index[$claimKey] = $projectId;
				}
			}
		}//end foreach

		return $index;
	}//end indexClaims()

	/**
	 * Fold one expense schema family into Project.costLines.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $schema The source schema name.
	 * @param string $type The costLine discriminator value.
	 * @param array<string,string> $byKey Identifier → projectKey
	 *                                    index.
	 * @param array<string,array<string,mixed>> $projectArrays ProjectKey → mutable record (by
	 *                                                         ref).
	 * @param array<string,string> $claimIndex Claim id/number →
	 *                                         projectId.
	 * @param callable $mapper Source-row → costLine
	 *                         mapper.
	 * @param IOutput $output Repair output.
	 *
	 * @return array<string,bool> The newly-touched project keys.
	 */
	private function foldCostFamily(
		object $objectService,
		string $registerSlug,
		string $schema,
		string $type,
		array $byKey,
		array &$projectArrays,
		array $claimIndex,
		callable $mapper,
		IOutput $output,
	): array {
		$newlyTouched = [];

		try {
			$rows = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema);
		} catch (\Throwable $e) {
			$output->warning('Shillinq: fold — failed to list ' . $schema . ': ' . $e->getMessage());
			return $newlyTouched;
		}

		foreach ($rows as $row) {
			try {
				$arr = $this->rowPayload(row: $row);
				$sourceId = (string)($arr['id'] ?? ($arr['uuid'] ?? ''));
				if ($sourceId === '') {
					continue;
				}

				$projectKey = $this->resolveProjectKey(
					arr: $arr,
					byKey: $byKey,
					claimIndex: $claimIndex
				);
				if ($projectKey === null) {
					// No owning project — leave the source row in place.
					continue;
				}

				if ($this->costLineExists(record: $projectArrays[$projectKey], sourceId: $sourceId) === true) {
					continue;
				}

				$line = $mapper($arr);
				$line['sourceId'] = $sourceId;
				$line['type'] = $type;

				$projectArrays[$projectKey]['costLines'][] = $line;
				$newlyTouched[$projectKey] = true;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: fold — ' . $schema . ' row failed: ' . $e->getMessage());
			}//end try
		}//end foreach

		return $newlyTouched;
	}//end foldCostFamily()

	/**
	 * Fold UrenRegistratie rows into Project.hoursLines.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param array<string,string> $byKey Identifier → projectKey index.
	 * @param array<string,array<string,mixed>> $projectArrays ProjectKey → mutable record (by ref).
	 * @param IOutput $output Repair output.
	 *
	 * @return array<string,bool> The newly-touched project keys.
	 */
	private function foldHours(
		object $objectService,
		string $registerSlug,
		array $byKey,
		array &$projectArrays,
		IOutput $output,
	): array {
		$newlyTouched = [];

		try {
			$rows = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'UrenRegistratie');
		} catch (\Throwable $e) {
			$output->warning('Shillinq: fold — failed to list UrenRegistratie: ' . $e->getMessage());
			return $newlyTouched;
		}

		foreach ($rows as $row) {
			try {
				$arr = $this->rowPayload(row: $row);
				$sourceId = (string)($arr['id'] ?? ($arr['uuid'] ?? ''));
				if ($sourceId === '') {
					continue;
				}

				$projectId = (string)($arr['projectId'] ?? '');
				if ($projectId === '') {
					continue;
				}

				$projectKey = $byKey[$projectId] ?? null;
				if ($projectKey === null) {
					continue;
				}

				if ($this->hoursLineExists(record: $projectArrays[$projectKey], sourceId: $sourceId) === true) {
					continue;
				}

				$line = $this->mapHours(arr: $arr);
				$line['sourceId'] = $sourceId;

				$projectArrays[$projectKey]['hoursLines'][] = $line;
				$newlyTouched[$projectKey] = true;
			} catch (\Throwable $e) {
				$output->warning('Shillinq: fold — UrenRegistratie row failed: ' . $e->getMessage());
			}//end try
		}//end foreach

		return $newlyTouched;
	}//end foldHours()

	/**
	 * Resolve the owning project key for an expense row: a direct
	 * `projectId` on the row wins; otherwise the claim (claimId →
	 * ExpenseClaimEntry.projectId) is consulted.
	 *
	 * @param array<string,mixed> $arr The source row.
	 * @param array<string,string> $byKey Identifier → projectKey index.
	 * @param array<string,string> $claimIndex Claim id/number → projectId index.
	 *
	 * @return string|null The resolved project key, or null when unmatched.
	 */
	private function resolveProjectKey(array $arr, array $byKey, array $claimIndex): ?string {
		$directProjectId = (string)($arr['projectId'] ?? '');
		if ($directProjectId !== '' && isset($byKey[$directProjectId]) === true) {
			return $byKey[$directProjectId];
		}

		$claimId = (string)($arr['claimId'] ?? '');
		if ($claimId !== '' && isset($claimIndex[$claimId]) === true) {
			$claimProjectId = $claimIndex[$claimId];
			if (isset($byKey[$claimProjectId]) === true) {
				return $byKey[$claimProjectId];
			}
		}

		return null;
	}//end resolveProjectKey()

	/**
	 * Whether a costLine carrying the given sourceId already exists.
	 *
	 * @param array<string,mixed> $record The project record.
	 * @param string $sourceId The source id.
	 *
	 * @return bool True when already folded.
	 */
	private function costLineExists(array $record, string $sourceId): bool {
		foreach (($record['costLines'] ?? []) as $line) {
			if ((string)(((array)$line)['sourceId'] ?? '') === $sourceId) {
				return true;
			}
		}

		return false;
	}//end costLineExists()

	/**
	 * Whether an hoursLine carrying the given sourceId already exists.
	 *
	 * @param array<string,mixed> $record The project record.
	 * @param string $sourceId The source id.
	 *
	 * @return bool True when already folded.
	 */
	private function hoursLineExists(array $record, string $sourceId): bool {
		foreach (($record['hoursLines'] ?? []) as $line) {
			if ((string)(((array)$line)['sourceId'] ?? '') === $sourceId) {
				return true;
			}
		}

		return false;
	}//end hoursLineExists()

	/**
	 * Map a Receipt row onto a costLine (every source field carried).
	 *
	 * @param array<string,mixed> $arr The Receipt row.
	 *
	 * @return array<string,mixed> The costLine (without type/sourceId).
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Invoked via the
	 *     `[$this, 'mapReceipt']` callable passed as foldCostFamily()'s
	 *     $mapper argument — PHPMD's static analysis does not follow
	 *     callable-array method references.
	 */
	private function mapReceipt(array $arr): array {
		$amount = $arr['amountInBaseCurrency'] ?? ($arr['amount'] ?? 0);

		return [
			'costDate' => $this->toIsoDate(value: (string)($arr['receiptDate'] ?? '')),
			'description' => (string)($arr['description'] ?? ($arr['vendorName'] ?? ($arr['receiptNumber'] ?? ''))),
			'amount' => (float)$amount,
			'currency' => (string)($arr['currency'] ?? ''),
			'costCentreCode' => (string)($arr['costCentreCode'] ?? ''),
		];

	}//end mapReceipt()

	/**
	 * Map a MileageEntry row onto a costLine (every source field carried).
	 *
	 * @param array<string,mixed> $arr The MileageEntry row.
	 *
	 * @return array<string,mixed> The costLine (without type/sourceId).
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Invoked via the
	 *     `[$this, 'mapMileage']` callable passed as foldCostFamily()'s
	 *     $mapper argument — PHPMD's static analysis does not follow
	 *     callable-array method references.
	 */
	private function mapMileage(array $arr): array {
		$description = (string)($arr['purpose'] ?? '');
		if ($description === '') {
			$description = trim((string)($arr['fromLocation'] ?? '') . ' → ' . (string)($arr['toLocation'] ?? ''));
		}

		return [
			'costDate' => $this->toIsoDate(value: (string)($arr['journeyDate'] ?? '')),
			'description' => $description,
			'amount' => (float)($arr['totalAmount'] ?? 0),
			'currency' => '',
			'costCentreCode' => (string)($arr['costCentreCode'] ?? ''),
		];

	}//end mapMileage()

	/**
	 * Map a PerDiem row onto a costLine (every source field carried).
	 *
	 * @param array<string,mixed> $arr The PerDiem row.
	 *
	 * @return array<string,mixed> The costLine (without type/sourceId).
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Invoked via the
	 *     `[$this, 'mapPerDiem']` callable passed as foldCostFamily()'s
	 *     $mapper argument — PHPMD's static analysis does not follow
	 *     callable-array method references.
	 */
	private function mapPerDiem(array $arr): array {
		$description = (string)($arr['description'] ?? '');
		if ($description === '') {
			$description = trim((string)($arr['country'] ?? '') . ' per-diem (' . (string)($arr['nightCount'] ?? 0) . ' nights)');
		}

		return [
			'costDate' => $this->toIsoDate(value: (string)($arr['travelStartDate'] ?? '')),
			'description' => $description,
			'amount' => (float)($arr['allowanceAmount'] ?? 0),
			'currency' => '',
			'costCentreCode' => (string)($arr['costCentreCode'] ?? ''),
		];

	}//end mapPerDiem()

	/**
	 * Map an UrenRegistratie row onto an hoursLine (every relevant field carried).
	 *
	 * @param array<string,mixed> $arr The UrenRegistratie row.
	 *
	 * @return array<string,mixed> The hoursLine (without sourceId).
	 */
	private function mapHours(array $arr): array {
		return [
			'date' => $this->toIsoDate(value: (string)($arr['date'] ?? '')),
			'hours' => (float)($arr['hours'] ?? 0),
			'personId' => (string)($arr['personId'] ?? ''),
			'recognisedRate' => (float)($arr['recognisedRate'] ?? 0),
			'wbsoTagId' => (string)($arr['wbsoTagId'] ?? ''),
		];

	}//end mapHours()

	/**
	 * Normalise a date value to a bare ISO 8601 date (YYYY-MM-DD). The
	 * folded line `costDate`/`date` properties use OR `format: date`, so
	 * any time component is dropped. Empty/invalid input yields ''.
	 *
	 * @param string $value The source date value.
	 *
	 * @return string The normalised date, or '' when unparseable.
	 */
	private function toIsoDate(string $value): string {
		if ($value === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Throwable $e) {
			return '';
		}

	}//end toIsoDate()
}//end class
