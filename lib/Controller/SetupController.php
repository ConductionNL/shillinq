<?php

/**
 * Shillinq first-time setup contract (ADR-042).
 *
 * Backs the abstract CnSetupWizard. Shillinq is the canonical "block-on-required"
 * case: the legal region, RGS chart-of-accounts template and the active
 * administration MUST be chosen before the app is usable, after which an admin can
 * seed the chart of accounts + region-specific reference data. Seeding runs here
 * (admin request context, OpenRegister RBAC satisfied) and is rejected (422) while
 * any required choice is unmet — enforcing the C2 "no tenant data without
 * administration" constraint at the server, not only in the UI.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://shillinq.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.Operators.ComparisonOperatorUsage, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\DemoDataService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * First-time setup status + actions for the abstract setup wizard.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var int
	 */
	private const SETUP_VERSION = 1;
	/**
	 * App-config key recording that the optional demo-data step has been dealt with.
	 *
	 * Records a DECISION, not a state: "installed" and "declined" both set it.
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them.
	 *
	 * @var string
	 */
	private const DEMO_DATA_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding the dataset the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`.
	 * Each card's Load button posts `{ dataset }` to the `load-demo-data`
	 * action, which stores the same key once the load succeeds, so both routes
	 * land in one place (`loadAction` on the step, wizard-dataset-card-load).
	 *
	 * @var string
	 */
	private const DATASET_KEY = 'demo_dataset';

	/**
	 * Construct the setup controller.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App-config reader/writer.
	 * @param DemoDataService $demoDataService Demo dataset import (ADR-111 rule 4).
	 * @param SettingsService $settingsService OR availability + config import + seeders.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly DemoDataService $demoDataService,
		private readonly SettingsService $settingsService,
	) {
		parent::__construct($appName, $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * @return DataResponse `{ version, completed, steps: { <id>: { done } } }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		$done = $this->stepsDone();
		$requiredDone = $this->requiredStepsDone(done: $done);

		if ($requiredDone === true) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new DataResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $requiredDone,
				// The choice step reads its options from here: it declares
				// `optionsSource: datasets` and no options of its own, so a
				// dataset missing from this list is a dataset nobody can pick.
				'datasets' => $this->demoDataService->listChoices(),
				'steps' => $this->stepReport(done: $done, requiredDone: $requiredDone),
			]
		);
	}//end status()

	/**
	 * Whether each configurable step is done, read from app config.
	 *
	 * @return array<string, bool> Keyed by step id.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function stepsDone(): array {
		$done = [
			'country' => $this->config(key: 'legal_country') !== '',
			'organisation' => $this->config(key: 'legal_region') !== '',
			'rgs-template' => $this->config(key: 'rgs_template') !== '',
			'administration' => $this->config(key: 'administration_id') !== '',
			'seed' => $this->config(key: 'setup_seed_done') === '1',
		];
		// DEALT WITH, not "demo objects exist". An operator who declines demo
		// data has finished the step; re-offering it every visit would make
		// "no thanks" impossible to express.
		$demoDecided = $this->config(key: self::DEMO_DATA_DECIDED_KEY) !== '';
		$pickedDataset = $this->config(key: self::DATASET_KEY);
		// A pick without a load still counts: a wizard that
		// predates `loadAction` can only record the pick.
		$done['demo-data'] = ($demoDecided === true || $pickedDataset !== '');

		return $done;
	}//end stepsDone()

	/**
	 * Whether every required step (country, region, RGS template, administration) is done.
	 *
	 * @param array<string, bool> $done The steps done.
	 *
	 * @return bool
	 */
	private function requiredStepsDone(array $done): bool {
		return ($done['country'] === true && $done['organisation'] === true && $done['rgs-template'] === true && $done['administration'] === true);
	}//end requiredStepsDone()

	/**
	 * The per-step report. Exactly the ids of `manifest.setup.steps`: a step
	 * the server never reports stays open and reopens the wizard.
	 *
	 * @param array<string, bool> $done         The steps done.
	 * @param bool                $requiredDone Whether every required step is done.
	 *
	 * @return array<string, array{done: bool}>
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function stepReport(array $done, bool $requiredDone): array {
		return [
			'welcome' => ['done' => true],
			'demo-data' => ['done' => $done['demo-data']],
			'country' => ['done' => $done['country']],
			'organisation' => ['done' => $done['organisation']],
			'rgs-template' => ['done' => $done['rgs-template']],
			'administration' => ['done' => $done['administration']],
			'seed' => ['done' => $done['seed']],
			'done' => ['done' => $requiredDone],
		];
	}//end stepReport()

	/**
	 * Persist app-config values from a `choice` / `config-fields` step.
	 *
	 * @return DataResponse `{ success }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): DataResponse {
		// 🔴 THE DATASET IS VALIDATED BEFORE IT IS STORED. Everything else here
		// is written as posted, because a `config-fields` step declares its own
		// keys and this endpoint cannot know them. The dataset is different:
		// the load step reads it back and hands it to the importer, so an
		// unknown value would surface a step later as a failed import with no
		// clue why.
		$refusal = $this->storedDatasetRefusal(dataset: $this->request->getParam(self::DATASET_KEY));
		if ($refusal !== null) {
			return $refusal;
		}

		$this->storeParams();

		return new DataResponse(['success' => true]);
	}//end saveConfig()

	/**
	 * The refusal of a posted dataset nobody can import, or null when none was
	 * posted or it is known.
	 *
	 * @param mixed $dataset The posted dataset (null when not posted).
	 *
	 * @return DataResponse|null
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function storedDatasetRefusal(mixed $dataset): ?DataResponse {
		if ($dataset === null) {
			return null;
		}

		$named = $this->datasetName(value: $dataset);
		if (in_array($named, $this->knownDatasets(), true) === false) {
			return new DataResponse(['success' => false, 'message' => 'No dataset is called "' . $named . '".']);
		}

		return null;
	}//end storedDatasetRefusal()

	/**
	 * Write every posted param except the route to app config, as posted
	 * (non-scalars JSON-encoded).
	 *
	 * @return void
	 */
	private function storeParams(): void {
		foreach ($this->request->getParams() as $key => $value) {
			if ($key === '_route') {
				continue;
			}

			$this->appConfig->setValueString(
				AppIdentity::APP_ID,
				(string)$key,
				is_scalar($value) ? (string)$value : json_encode($value),
			);
		}
	}//end storeParams()

	/**
	 * How a posted dataset is named back: its value when scalar, else "that".
	 *
	 * @param mixed $value The posted dataset.
	 *
	 * @return string
	 */
	private function datasetName(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return 'that';
	}//end datasetName()

	/**
	 * The ids of the datasets this app can import.
	 *
	 * @return array<int, mixed>
	 */
	private function knownDatasets(): array {
		return array_column($this->demoDataService->listChoices(), 'id');
	}//end knownDatasets()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * @param string $actionId One of `init-administration` | `seed`.
	 *
	 * @return DataResponse `{ success, message, detail }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): DataResponse {
		// `install-demo-data` is the id the step used before it asked WHICH
		// dataset, and it still means "import the one this app ships". Kept so
		// an older manifest, a runbook or a script that posts it keeps working.
		return match ($actionId) {
			'load-demo-data' => $this->loadDataset(),
			'install-demo-data' => $this->installDemoData(dataset: DemoDataService::DEMO_DATASET),
			'skip-demo-data' => $this->skipDemoData(),
			'init-administration' => $this->initAdministration(),
			'seed' => $this->seedReferenceData(),
			default => new DataResponse(
				['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
				Http::STATUS_NOT_FOUND,
			),
		};
	}//end runAction()

	/**
	 * Create (or find) the default administration and record its real code.
	 *
	 * @return DataResponse 200 with the seed detail; 500 when the seed fails.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function initAdministration(): DataResponse {
		$this->settingsService->loadConfigurationForced();
		$result = $this->settingsService->seedDefaultAdministration();

		if (($result['success'] ?? false) !== true) {
			return new DataResponse(
				['success' => false, 'message' => ($result['message'] ?? 'Failed to create default administration.')],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return $this->recordAdministration(result: $result);
	}//end initAdministration()

	/**
	 * Record the administrationCode the seed created/found.
	 *
	 * Read the real administrationCode the seed created/found — never
	 * guess it, so a future change to the seed file (or seeding onto an
	 * instance where it was already created under a different code)
	 * never silently sets the wrong administration_id.
	 *
	 * @param array<string, mixed> $result The successful seed result.
	 *
	 * @return DataResponse 200 with the seed detail; 500 when no code was reported.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function recordAdministration(array $result): DataResponse {
		$adminId = (string)($result['administrationCode'] ?? '');
		if ($adminId === '') {
			return new DataResponse(
				['success' => false, 'message' => 'Default administration seed did not report an administrationCode.'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, 'administration_id', $adminId);
		return new DataResponse(['success' => true, 'message' => 'Default administration created.', 'detail' => $result]);
	}//end recordAdministration()

	/**
	 * Seed the chart of accounts and statutory reference data once region,
	 * RGS template and administration are chosen.
	 *
	 * @return DataResponse 200 when seeded; 422 while a required step is open.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function seedReferenceData(): DataResponse {
		$region = $this->config(key: 'legal_region');
		$template = $this->config(key: 'rgs_template');
		$adminId = $this->config(key: 'administration_id');
		if ($region === '' || $template === '' || $adminId === '') {
			return new DataResponse(
				['success' => false, 'message' => 'Choose region, RGS template and administration before seeding.'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		return $this->seedFor(template: $template, adminId: $adminId);
	}//end seedReferenceData()

	/**
	 * Seed the RGS template for the administration, then the BTW tariffs, BBV
	 * taakvelden and selectielijst, and mark the seed step done.
	 *
	 * @param string $template The RGS template variant.
	 * @param string $adminId  The administration.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function seedFor(string $template, string $adminId): DataResponse {
		$this->settingsService->seedRgsTemplate(templateVariant: $template, administrationId: $adminId);
		$this->settingsService->seedBtwTariffs();
		$this->settingsService->seedBbvTaakvelden();
		// Statutory retention rules (region-specific per REQ-ARC-002); idempotent,
		// and re-running here matters when OpenRegister was enabled AFTER shillinq
		// so the install-time repair step never seeded it (ADR-042 recovery path).
		$this->settingsService->seedSelectielijst();
		$this->appConfig->setValueString(AppIdentity::APP_ID, 'setup_seed_done', '1');
		return new DataResponse(['success' => true, 'message' => 'Chart of accounts and reference data seeded.']);
	}//end seedFor()

	/**
	 * Install the dataset a card's Load button posted as `dataset`, or the
	 * stored pick when nothing is posted (ADR-111 rule 4).
	 *
	 * @return DataResponse The outcome, carrying the counts.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function loadDataset(): DataResponse {
		$picked = $this->pickedDataset();
		if ($picked instanceof DataResponse) {
			return $picked;
		}

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for.
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoDataService::NONE_DATASET) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, DemoDataService::NONE_DATASET);
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new DataResponse(['success' => true, 'message' => 'No example data was loaded.']);
		}

		return $this->installDemoData(dataset: $picked);

	}//end loadDataset()

	/**
	 * The dataset to load: the one the card posts, else the pick stored a step earlier.
	 *
	 * The card's Load button names its dataset in the body. An older wizard
	 * posts nothing and relies on the pick stored a step earlier. Nothing is
	 * stored before the load succeeds: a failed load must leave the step
	 * open for an operator who asked for data and got none.
	 *
	 * @return string|DataResponse The dataset ('' when none), or the refusal of an unknown posted one.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function pickedDataset(): string|DataResponse {
		$picked = $this->config(key: self::DATASET_KEY);

		$posted = $this->request->getParam('dataset');
		if ($posted === null) {
			return $picked;
		}

		return $this->refuseDataset(value: $posted) ?? (string)$posted;
	}//end pickedDataset()

	/**
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return DataResponse|null The refusal, or null when the dataset is known.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function refuseDataset(mixed $value): ?DataResponse {
		$named = $this->datasetName(value: $value);

		$known = $this->knownDatasets();
		if (is_scalar($value) === true && in_array($named, $known, true) === true) {
			return null;
		}

		return new DataResponse(
			['success' => false, 'message' => 'No dataset is called "' . $named . '".'],
			Http::STATUS_BAD_REQUEST,
		);
	}//end refuseDataset()

	/**
	 * Import the dataset this app ships.
	 *
	 * @param string $dataset The dataset id, recorded as the pick once the
	 *                        import succeeded.
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function installDemoData(string $dataset): DataResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			return new DataResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// Recorded only after the import actually returned. Marking it first
		// would let a failed install present as a finished step.
		// Loading IS choosing the set, so the pick is recorded too.
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, $dataset);
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'installed');

		return $this->installedResponse(imported: $imported);
	}//end installDemoData()

	/**
	 * The answer to a finished import.
	 *
	 * 🔴 THE COUNTS, ALWAYS. "Demo data installed" with no numbers cannot be
	 * told apart from an import that wrote nothing.
	 *
	 * @param array<string, mixed> $imported The importer's counts.
	 *
	 * @return DataResponse
	 */
	private function installedResponse(array $imported): DataResponse {
		return new DataResponse(
			[
				'success' => true,
				'message' => sprintf(
					'Demo data installed: %d objects across %d schemas.',
					$imported['objects'],
					$imported['schemas']
				),
				'detail'  => $imported,
			]
		);
	}//end installedResponse()

	/**
	 * Record that the operator declined the demo dataset.
	 *
	 * Its own action so "no thanks" is a decision the wizard can record. Without
	 * it the only way past the step would be to install demo data, which is
	 * wrong on a production instance.
	 *
	 * @return DataResponse The outcome.
	 *
	 * @spec exclude Demo-data skip action (ADR-111 rule 4); no per-app openspec change yet.
	 */
	private function skipDemoData(): DataResponse {
		// Skipping IS choosing "None", so both keys are written: an older
		// runbook may read either one.
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, DemoDataService::NONE_DATASET);
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new DataResponse(['success' => true, 'message' => 'Demo data skipped.']);
	}//end skipDemoData()

	/**
	 * Read a shillinq app-config string value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
	}//end config()
}//end class
