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
	 * Skipping is a DECISION, not the absence of one: it records `skipped` so
	 * the optional wizard stops offering the step, and imports nothing.
	 *
	 * @return void
	 */
	public function testRunActionSkipDemoDataRecordsTheDecisionWithoutImporting(): void {
		$this->demoDataService->expects($this->never())->method('install');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('shillinq', 'demo_data_decided', 'skipped');

		$data = $this->controller->runAction('skip-demo-data')->getData();

		self::assertTrue($data['success']);

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
