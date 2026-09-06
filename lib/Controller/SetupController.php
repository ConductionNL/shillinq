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

use OCA\Shillinq\AppInfo\Application;
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
	 * The wizard's `choice` step writes it through `POST /api/setup/config`, and
	 * the `run-action` step that follows reads it back. Two steps rather than
	 * one because `CnSetupWizard::runAction()` posts to
	 * `/api/setup/action/{action}` with no body: an action cannot carry the
	 * answer, so the answer has to be stored before the action runs.
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
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		$countryDone = $this->config(key: 'legal_country') !== '';
		$regionDone = $this->config(key: 'legal_region') !== '';
		$rgsDone = $this->config(key: 'rgs_template') !== '';
		$adminDone = $this->config(key: 'administration_id') !== '';
		$seedDone = $this->config(key: 'setup_seed_done') === '1';
		// DEALT WITH, not "demo objects exist". An operator who declines demo
		// data has finished the step; re-offering it every visit would make
		// "no thanks" impossible to express.
		$demoDecided = $this->config(key: self::DEMO_DATA_DECIDED_KEY) !== '';
		$pickedDataset = $this->config(key: self::DATASET_KEY);
		$requiredDone = ($countryDone === true && $regionDone === true && $rgsDone === true && $adminDone === true);

		if ($requiredDone === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new DataResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $requiredDone,
				// The choice step reads its options from here: it declares
				// `optionsSource: datasets` and no options of its own, so a
				// dataset missing from this list is a dataset nobody can pick.
				'datasets' => $this->demoDataService->listChoices(),
				'steps' => [
					'demo-data' => ['done' => ($pickedDataset !== '')],
					// "None" is an ANSWER, so the load step is finished the
					// moment it is chosen: there is nothing left to run.
					'load-demo-data' => [
						'done' => ($demoDecided === true || $pickedDataset === DemoDataService::NONE_DATASET),
					],
					'country' => ['done' => $countryDone],
					'organisation' => ['done' => $regionDone],
					'rgs-template' => ['done' => $rgsDone],
					'administration' => ['done' => $adminDone],
					'seed' => ['done' => $seedDone],
				],
			]
		);
	}//end status()

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
		$dataset = $this->request->getParam(self::DATASET_KEY);
		if ($dataset !== null) {
			$named = 'that';
			if (is_scalar($dataset) === true) {
				$named = (string)$dataset;
			}

			$known = array_column($this->demoDataService->listChoices(), 'id');
			if (in_array($named, $known, true) === false) {
				return new DataResponse(['success' => false, 'message' => 'No dataset is called "' . $named . '".']);
			}
		}

		foreach ($this->request->getParams() as $key => $value) {
			if ($key === '_route') {
				continue;
			}

			$this->appConfig->setValueString(
				Application::APP_ID,
				(string)$key,
				is_scalar($value) ? (string)$value : json_encode($value),
			);
		}

		return new DataResponse(['success' => true]);
	}//end saveConfig()

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
		if ($actionId === 'load-demo-data') {
			return $this->loadDataset();
		}

		if ($actionId === 'install-demo-data') {
			return $this->installDemoData();
		}

		if ($actionId === 'skip-demo-data') {
			return $this->skipDemoData();
		}

		if ($actionId === 'init-administration') {
			$this->settingsService->loadConfigurationForced();
			$result = $this->settingsService->seedDefaultAdministration();

			if (($result['success'] ?? false) !== true) {
				return new DataResponse(
					['success' => false, 'message' => ($result['message'] ?? 'Failed to create default administration.')],
					Http::STATUS_INTERNAL_SERVER_ERROR,
				);
			}

			// Read the real administrationCode the seed created/found — never
			// guess it, so a future change to the seed file (or seeding onto an
			// instance where it was already created under a different code)
			// never silently sets the wrong administration_id.
			$adminId = (string)($result['administrationCode'] ?? '');
			if ($adminId === '') {
				return new DataResponse(
					['success' => false, 'message' => 'Default administration seed did not report an administrationCode.'],
					Http::STATUS_INTERNAL_SERVER_ERROR,
				);
			}

			$this->appConfig->setValueString(Application::APP_ID, 'administration_id', $adminId);
			return new DataResponse(['success' => true, 'message' => 'Default administration created.', 'detail' => $result]);
		}//end if

		if ($actionId === 'seed') {
			$region = $this->config(key: 'legal_region');
			$template = $this->config(key: 'rgs_template');
			$adminId = $this->config(key: 'administration_id');
			if ($region === '' || $template === '' || $adminId === '') {
				return new DataResponse(
					['success' => false, 'message' => 'Choose region, RGS template and administration before seeding.'],
					Http::STATUS_UNPROCESSABLE_ENTITY,
				);
			}

			$this->settingsService->seedRgsTemplate(templateVariant: $template, administrationId: $adminId);
			$this->settingsService->seedBtwTariffs();
			$this->settingsService->seedBbvTaakvelden();
			// Statutory retention rules (region-specific per REQ-ARC-002); idempotent,
			// and re-running here matters when OpenRegister was enabled AFTER shillinq
			// so the install-time repair step never seeded it (ADR-042 recovery path).
			$this->settingsService->seedSelectielijst();
			$this->appConfig->setValueString(Application::APP_ID, 'setup_seed_done', '1');
			return new DataResponse(['success' => true, 'message' => 'Chart of accounts and reference data seeded.']);
		}//end if

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Install the shipped demo dataset (ADR-111 rule 4).
	 *
	 * @return DataResponse The outcome, carrying the counts.
	 *
	 * @spec exclude Demo-data install action (ADR-111 rule 4); no per-app openspec change yet.
	 */
	private function loadDataset(): DataResponse {
		$picked = $this->config(key: self::DATASET_KEY);

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for.
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoDataService::NONE_DATASET) {
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new DataResponse(['success' => true, 'message' => 'No example data was loaded.']);
		}

		return $this->installDemoData();

	}//end loadDataset()

	/**
	 * Import the dataset this app ships.
	 *
	 * @return DataResponse `{ success, message }`.
	 */
	private function installDemoData(): DataResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			return new DataResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// Recorded only after the import actually returned. Marking it first
		// would let a failed install present as a finished step.
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'installed');

		// 🔴 THE COUNTS, ALWAYS. "Demo data installed" with no numbers cannot be
		// told apart from an import that wrote nothing.
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
	}//end installDemoData()

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
		// 🔴 IT ANSWERS *BOTH* STEPS. The wizard now has a choice step and a
		// run-action step; closing only the second leaves the first
		// outstanding, and CnAppRoot opens the wizard while ANY optional step
		// is outstanding.
		$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoDataService::NONE_DATASET);
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

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
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end config()
}//end class
