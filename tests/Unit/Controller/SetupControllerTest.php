<?php

/**
 * Unit tests for SetupController.
 *
 * Covers the first-time-setup server-side contract (ADR-042): status
 * reporting, config persistence, the `init-administration` run-action (real
 * administrationCode wiring, not a hardcoded guess), and the `seed`
 * run-action's C2 gate (422 while a required step is unmet).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SetupController;
use OCA\Shillinq\Service\DemoDataService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SetupController.
 */
final class SetupControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Controller under test.
	 *
	 * @var SetupController
	 */
	/**
	 * Mock DemoDataService.
	 *
	 * @var DemoDataService&MockObject
	 */
	private DemoDataService&MockObject $demoDataService;

	private SetupController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->demoDataService = $this->createMock(DemoDataService::class);

		$this->controller = new SetupController(
			appName: 'shillinq',
			request: $this->request,
			appConfig: $this->appConfig,
			demoDataService: $this->demoDataService,
			settingsService: $this->settingsService,
		);

	}//end setUp()

	/**
	 * Stub every app-config read the controller performs, from a map of
	 * key => value; unmapped keys default to ''.
	 *
	 * @param array<string,string> $values Config key => value map.
	 *
	 * @return void
	 */
	private function stubConfig(array $values): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
			);

	}//end stubConfig()

	/**
	 * The status document carries the datasets the choice step offers.
	 *
	 * 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
	 * `optionsSource: datasets` and carries no options of its own, so a dataset
	 * missing here is a dataset nobody can pick.
	 *
	 * @return void
	 */
	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		$this->stubConfig([]);
		$this->demoDataService->method('listChoices')->willReturn(
			[
				['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
				['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 8, 'icon' => 'DatabaseOutline'],
			]
		);

		$data = $this->controller->status()->getData();

		$this->assertSame(['none', 'demo'], array_column($data['datasets'], 'id'));
		$this->assertSame(8, $data['datasets'][1]['objectCount']);
		$this->assertArrayHasKey('load-demo-data', $data['steps']);

	}//end testStatusCarriesTheOptionListTheChoiceStepReads()

	/**
	 * Choosing none closes both steps without running anything.
	 *
	 * 🔴 THE DEFECT THIS FIXES. This app implemented `skip-demo-data` and no
	 * manifest step could reach it, so declining was unsayable: the step stayed
	 * `done: false` and CnAppRoot reopened the wizard over every page unless
	 * the operator imported data they did not want.
	 *
	 * @return void
	 */
	public function testChoosingNoneClosesBothStepsWithoutRunningAnything(): void {
		$this->stubConfig(['demo_dataset' => 'none']);
		$this->demoDataService->method('listChoices')->willReturn([]);

		$steps = $this->controller->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertTrue($steps['load-demo-data']['done']);

	}//end testChoosingNoneClosesBothStepsWithoutRunningAnything()

	/**
	 * An unknown dataset is refused rather than stored.
	 *
	 * Storing it would leave the load step pointing at nothing, so the failure
	 * would surface one step later with no clue why.
	 *
	 * @return void
	 */
	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		$this->request->method('getParam')->willReturn('atlantis');
		$this->request->method('getParams')->willReturn(['demo_dataset' => 'atlantis']);
		$this->demoDataService->method('listChoices')->willReturn(
			[['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => '']]
		);

		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertFalse($this->controller->saveConfig()->getData()['success']);

	}//end testAnUnknownDatasetIsRefusedRatherThanStored()

	/**
	 * Running the load step with no dataset picked refuses rather than guessing.
	 *
	 * 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one step
	 * early would plant example objects nobody asked for.
	 *
	 * @return void
	 */
	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		$this->stubConfig([]);
		$this->demoDataService->expects($this->never())->method('install');

		$data = $this->controller->runAction(actionId: 'load-demo-data')->getData();

		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Pick a dataset', $data['message']);

	}//end testLoadingWithoutAChoiceRefusesRatherThanGuessing()

	/**
	 * Choosing none and then running imports nothing, rather than refusing.
	 *
	 * @return void
	 */
	/**
	 * The other half of the pair: picking the shipped set and running imports it.
	 *
	 * Without this the choice step could store an answer that nothing ever acts
	 * on, and the only covered path through `load-demo-data` would be the one
	 * that imports nothing.
	 *
	 * @return void
	 */
	public function testPickingTheShippedSetAndThenRunningImportsIt(): void {
		$this->stubConfig(['demo_dataset' => 'demo']);
		$this->demoDataService->expects($this->once())
			->method('install')
			->willReturn(['objects' => 42, 'schemas' => 7]);

		$data = $this->controller->runAction(actionId: 'load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('42', $data['message']);

	}//end testPickingTheShippedSetAndThenRunningImportsIt()

	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		$this->stubConfig(['demo_dataset' => 'none']);
		$this->demoDataService->expects($this->never())->method('install');

		$data = $this->controller->runAction(actionId: 'load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);

	}//end testChoosingNoneAndThenRunningImportsNothing()

	/**
	 * status() reports every step undone and `completed: false` on a fresh install.
	 *
	 * @return void
	 */
	public function testStatusReportsUndoneOnFreshInstall(): void {
		$this->stubConfig([]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->status();
		$data = $response->getData();

		self::assertFalse($data['completed']);
		self::assertFalse($data['steps']['country']['done']);
		self::assertFalse($data['steps']['organisation']['done']);
		self::assertFalse($data['steps']['rgs-template']['done']);
		self::assertFalse($data['steps']['administration']['done']);
		self::assertFalse($data['steps']['seed']['done']);

	}//end testStatusReportsUndoneOnFreshInstall()

	/**
	 * status() reports `completed: true` and writes setup_completed_version
	 * once every required step's config key is set (REQ-SETUP-SHI-003).
	 *
	 * @return void
	 */
	public function testStatusReportsCompletedWhenAllRequiredStepsSet(): void {
		$this->stubConfig(
			[
				'legal_country' => 'nl',
				'legal_region' => 'mkb',
				'rgs_template' => 'mkb',
				'administration_id' => 'ADM-001',
			]
		);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'setup_completed_version', '1');

		$response = $this->controller->status();
		$data = $response->getData();

		self::assertTrue($data['completed']);
		self::assertTrue($data['steps']['administration']['done']);
		// seed is optional — completion never depends on it.
		self::assertFalse($data['steps']['seed']['done']);

	}//end testStatusReportsCompletedWhenAllRequiredStepsSet()

	/**
	 * status() does not report completed while only some required steps are set.
	 *
	 * @return void
	 */
	public function testStatusNotCompletedWhenAdministrationMissing(): void {
		$this->stubConfig(
			[
				'legal_country' => 'nl',
				'legal_region' => 'mkb',
				'rgs_template' => 'mkb',
			]
		);

		$response = $this->controller->status();
		$data = $response->getData();

		self::assertFalse($data['completed']);
		self::assertFalse($data['steps']['administration']['done']);

	}//end testStatusNotCompletedWhenAdministrationMissing()

	/**
	 * saveConfig() persists every request parameter as an app-config value,
	 * skipping the framework-injected `_route` parameter.
	 *
	 * @return void
	 */
	public function testSaveConfigPersistsParamsAndSkipsRoute(): void {
		$this->request->method('getParams')->willReturn(
			[
				'_route' => 'shillinq.setup.saveConfig',
				'legal_region' => 'municipality',
			]
		);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'legal_region', 'municipality');

		$response = $this->controller->saveConfig();

		self::assertTrue($response->getData()['success']);

	}//end testSaveConfigPersistsParamsAndSkipsRoute()

	/**
	 * runAction('init-administration') persists the seed's real administrationCode
	 * as administration_id — never a hardcoded guess.
	 *
	 * @return void
	 */
	/**
	 * 🔴 THE COUNTS REACH THE OPERATOR. "Demo data installed" with no numbers
	 * cannot be told apart from an import that wrote nothing — the exact defect
	 * the openregister half of this programme shipped and had to fix.
	 *
	 * @return void
	 */
	public function testRunActionInstallDemoDataReportsWhatLanded(): void {
		$this->demoDataService->expects($this->once())
			->method('install')
			->willReturn(['objects' => 1497, 'schemas' => 499]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'demo_data_decided', 'installed');

		$response = $this->controller->runAction('install-demo-data');
		$data = $response->getData();

		self::assertTrue($data['success']);
		self::assertStringContainsString('1497', $data['message']);
		self::assertStringContainsString('499', $data['message']);

	}//end testRunActionInstallDemoDataReportsWhatLanded()

	/**
	 * 🔴 A FAILED INSTALL LEAVES THE STEP UNDECIDED.
	 *
	 * The decision is recorded only after the import returns. Recording it first
	 * would let a failed install present as a finished step: the wizard would
	 * never offer it again and nobody would learn the demo data is absent.
	 *
	 * @return void
	 */
	public function testRunActionInstallDemoDataFailureLeavesTheStepUndecided(): void {
		$this->demoDataService->method('install')
			->willThrowException(new \RuntimeException('openregister is not installed'));

		$this->appConfig->expects($this->never())->method('setValueString');

		$data = $this->controller->runAction('install-demo-data')->getData();

		self::assertFalse($data['success']);
		self::assertStringContainsString('openregister', $data['message']);

	}//end testRunActionInstallDemoDataFailureLeavesTheStepUndecided()

	/**
	 * Skipping is a DECISION, not the absence of one: it records the answer so
	 * the optional wizard stops offering the step, and imports nothing.
	 *
	 * 🔴 AND IT ANSWERS *BOTH* STEPS. The step split into a choice plus a
	 * run-action, and CnAppRoot opens the wizard while ANY optional step is
	 * outstanding — so writing only the decision flag would leave the choice
	 * open and the wizard covering every page.
	 *
	 * @return void
	 */
	public function testRunActionSkipDemoDataRecordsTheDecisionWithoutImporting(): void {
		$this->demoDataService->expects($this->never())->method('install');

		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$written): bool {
					$written[$key] = $value;

					return true;
				}
			);

		$data = $this->controller->runAction('skip-demo-data')->getData();

		self::assertTrue($data['success']);
		self::assertSame('skipped', $written['demo_data_decided'] ?? null);
		self::assertSame('none', $written['demo_dataset'] ?? null, 'skipping IS choosing none');

	}//end testRunActionSkipDemoDataRecordsTheDecisionWithoutImporting()

	public function testRunActionInitAdministrationPersistsSeedAdministrationCode(): void {
		$this->settingsService->expects($this->once())->method('loadConfigurationForced');
		$this->settingsService->expects($this->once())
			->method('seedDefaultAdministration')
			->willReturn(['success' => true, 'seeded' => 1, 'skipped' => 0, 'administrationCode' => 'ADM-001']);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'administration_id', 'ADM-001');

		$response = $this->controller->runAction('init-administration');
		$data = $response->getData();

		self::assertTrue($data['success']);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRunActionInitAdministrationPersistsSeedAdministrationCode()

	/**
	 * runAction('init-administration') returns 500 and writes nothing when the
	 * underlying seed fails (e.g. OpenRegister unavailable).
	 *
	 * @return void
	 */
	public function testRunActionInitAdministrationFailsWhenSeedFails(): void {
		$this->settingsService->method('seedDefaultAdministration')
			->willReturn(['success' => false, 'message' => 'OpenRegister is not installed or enabled.']);

		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->runAction('init-administration');

		self::assertFalse($response->getData()['success']);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testRunActionInitAdministrationFailsWhenSeedFails()

	/**
	 * runAction('init-administration') returns 500 and writes nothing when the
	 * seed succeeds but reports no administrationCode — guards the historical
	 * hardcoded-fallback defect (never silently write a guessed id again).
	 *
	 * @return void
	 */
	public function testRunActionInitAdministrationFailsWhenNoAdministrationCodeReported(): void {
		$this->settingsService->method('seedDefaultAdministration')
			->willReturn(['success' => true, 'seeded' => 0, 'skipped' => 1]);

		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->runAction('init-administration');

		self::assertFalse($response->getData()['success']);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testRunActionInitAdministrationFailsWhenNoAdministrationCodeReported()

	/**
	 * runAction('seed') is rejected with 422 while any required step
	 * (region / rgs-template / administration) is unmet (REQ-SETUP-SHI-002 /
	 * the C2 server-side gate).
	 *
	 * @return void
	 */
	public function testRunActionSeedRejectedWhenAdministrationMissing(): void {
		$this->stubConfig(
			[
				'legal_region' => 'mkb',
				'rgs_template' => 'mkb',
			]
		);

		$this->settingsService->expects($this->never())->method('seedRgsTemplate');
		$this->settingsService->expects($this->never())->method('seedSelectielijst');

		$response = $this->controller->runAction('seed');

		self::assertFalse($response->getData()['success']);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testRunActionSeedRejectedWhenAdministrationMissing()

	/**
	 * runAction('seed') seeds the chart of accounts + statutory reference data
	 * and marks setup_seed_done once region, RGS template and administration
	 * are all set (REQ-SETUP-SHI-002 happy path).
	 *
	 * @return void
	 */
	public function testRunActionSeedRunsWhenRequiredStepsAreSet(): void {
		$this->stubConfig(
			[
				'legal_region' => 'mkb',
				'rgs_template' => 'mkb',
				'administration_id' => 'ADM-001',
			]
		);

		$this->settingsService->expects($this->once())
			->method('seedRgsTemplate')
			->with(templateVariant: 'mkb', administrationId: 'ADM-001');
		$this->settingsService->expects($this->once())->method('seedBtwTariffs');
		$this->settingsService->expects($this->once())->method('seedBbvTaakvelden');
		$this->settingsService->expects($this->once())->method('seedSelectielijst');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'setup_seed_done', '1');

		$response = $this->controller->runAction('seed');

		self::assertTrue($response->getData()['success']);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRunActionSeedRunsWhenRequiredStepsAreSet()

	/**
	 * runAction() returns 404 for an unrecognised action id.
	 *
	 * @return void
	 */
	public function testRunActionUnknownActionReturnsNotFound(): void {
		$response = $this->controller->runAction('bogus-action');

		self::assertFalse($response->getData()['success']);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRunActionUnknownActionReturnsNotFound()
}//end class
