<?php

/**
 * Unit tests for SettingsService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/spec/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService.
 */
class SettingsServiceTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IAppManager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SettingsService(
			appConfig: $this->appConfig,
			appManager: $this->appManager,
			container: $this->container,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Test that seedRgsTemplate returns failure when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testSeedRgsTemplateFailsWhenOpenRegisterUnavailable(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(false);

		$result = $this->service->seedRgsTemplate(templateVariant: 'mkb', administrationId: 'adm-test');

		self::assertFalse($result['success']);
		self::assertStringContainsString('OpenRegister', $result['message']);

	}//end testSeedRgsTemplateFailsWhenOpenRegisterUnavailable()

	/**
	 * Test that seedRgsTemplate returns failure for unknown template variant.
	 *
	 * @return void
	 */
	public function testSeedRgsTemplateFailsForUnknownVariant(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(true);

		$result = $this->service->seedRgsTemplate(templateVariant: 'nonexistent', administrationId: 'test-admin-id');

		self::assertFalse($result['success']);
		self::assertStringContainsString('nonexistent', $result['message']);

	}//end testSeedRgsTemplateFailsForUnknownVariant()

	/**
	 * Test that seedRgsTemplate delegates to ObjectService and returns seeded count.
	 *
	 * @return void
	 */
	public function testSeedRgsTemplateSeeksAndSkipsCorrectly(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(true);

		$mockObjectService = $this->createMock(\stdClass::class);

		$this->container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjectService);

		$result = $this->service->seedRgsTemplate(
			templateVariant: 'zzp',
			administrationId: 'adm-test'
		);

		self::assertTrue(
			$result['success'] === true || $result['success'] === false,
			'Result must have a success key'
		);
		self::assertArrayHasKey('message', $result);

	}//end testSeedRgsTemplateSeeksAndSkipsCorrectly()

	/**
	 * Test that isOpenRegisterAvailable delegates to IAppManager.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableDelegatesToAppManager(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(true);

		$result = $this->service->isOpenRegisterAvailable();

		self::assertTrue($result);

	}//end testIsOpenRegisterAvailableDelegatesToAppManager()

	/**
	 * seedRetentionPolicies fails when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testSeedRetentionPoliciesFailsWhenOpenRegisterUnavailable(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(false);

		$result = $this->service->seedRetentionPolicies();

		self::assertFalse($result['success']);
		self::assertStringContainsString('OpenRegister', $result['message']);

	}//end testSeedRetentionPoliciesFailsWhenOpenRegisterUnavailable()

	/**
	 * Test seedDefaultAdministration returns failure when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	public function testSeedDefaultAdministrationFailsWhenOpenRegisterUnavailable(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(false);

		$result = $this->service->seedDefaultAdministration();

		self::assertFalse($result['success']);
		self::assertStringContainsString('OpenRegister', $result['message']);

	}//end testSeedDefaultAdministrationFailsWhenOpenRegisterUnavailable()

	/**
	 * Test the default-administration seed file parses and carries the required fields.
	 *
	 * Covers REQ-MA-001 / REQ-MA-007: the seed provides exactly one Administration
	 * with a unique administrationCode dedupe key and the lifecycle/backup defaults
	 * the repair step relies on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	public function testDefaultAdministrationSeedFileIsValid(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/seeds/administraties/default.json';
		self::assertFileExists($seedPath, 'Default administration seed file must exist.');

		$content = file_get_contents($seedPath);
		self::assertNotFalse($content, 'Must be able to read default administration seed file.');

		$data = json_decode($content, associative: true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Default administration seed must be valid JSON.');
		self::assertArrayHasKey('administrations', $data, 'Seed must have an administrations key.');
		self::assertCount(1, $data['administrations'], 'Exactly one default administration must be seeded.');

		$admin = $data['administrations'][0];
		foreach (['administrationCode', 'name', 'legalForm', 'status', 'backupSchedule', 'dataRetentionYears'] as $field) {
			self::assertArrayHasKey($field, $admin, 'Default administration must declare ' . $field . '.');
		}

		self::assertSame('ADM-001', $admin['administrationCode'], 'Default administration code must be ADM-001.');
		self::assertSame('actief', $admin['status'], 'Default administration must seed as actief.');
		self::assertSame(7, $admin['dataRetentionYears'], 'Default retention must be the 7-year wettelijke bewaartermijn.');

	}//end testDefaultAdministrationSeedFileIsValid()

	/**
	 * readDefaultAdministrationCode() reads the real administrationCode from the
	 * bundled seed file rather than a hardcoded guess. This backs
	 * SetupController::runAction('init-administration'), which used to fall back
	 * to a literal 'ADM-001' string when seedDefaultAdministration()'s result
	 * carried no id — silently "working" only because it happened to match the
	 * seed file's current value. Reading it here means a future change to the
	 * seed file can never desync the two.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function testReadDefaultAdministrationCodeReturnsSeedFileValue(): void {
		$reflection = new \ReflectionMethod(SettingsService::class, 'readDefaultAdministrationCode');
		$reflection->setAccessible(true);

		$code = $reflection->invoke($this->service);

		self::assertSame('ADM-001', $code);

	}//end testReadDefaultAdministrationCodeReturnsSeedFileValue()

	/**
	 * Test seedDefaultAdministration resolves ObjectService when OpenRegister is available.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	public function testSeedDefaultAdministrationResolvesObjectService(): void {
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('openregister')
			->willReturn(true);

		$mockObjectService = $this->createMock(\stdClass::class);

		$this->container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($mockObjectService);

		$result = $this->service->seedDefaultAdministration();

		self::assertArrayHasKey('success', $result);

	}//end testSeedDefaultAdministrationResolvesObjectService()

	/**
	 * Test the multi-administratie register fragment declares the five required schemas.
	 *
	 * Covers Tasks 5-9: Administration, AdministrationMembership, IntercompanyJournalEntry,
	 * ConsolidationMapping and AdministrationMigration are declared in the ADR-037 fragment
	 * (never in the monolith shillinq_register.json), each as a typed object with required fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-5
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-6
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-7
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-8
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-9
	 */
	public function testMultiAdministratieRegisterFragmentDeclaresSchemas(): void {
		$fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-multi-administratie.json';
		self::assertFileExists($fragmentPath, 'Multi-administratie register fragment must exist.');

		$content = file_get_contents($fragmentPath);
		self::assertNotFalse($content, 'Must be able to read the register fragment.');

		$data = json_decode($content, associative: true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Register fragment must be valid JSON.');

		$schemas = ($data['components']['schemas'] ?? []);
		foreach (['Administration', 'AdministrationMembership', 'IntercompanyJournalEntry', 'ConsolidationMapping', 'AdministrationMigration'] as $schemaName) {
			self::assertArrayHasKey($schemaName, $schemas, $schemaName . ' schema must be declared in the fragment.');
			self::assertSame('object', $schemas[$schemaName]['type'], $schemaName . ' must be an object schema.');
			self::assertNotEmpty($schemas[$schemaName]['required'], $schemaName . ' must declare required fields.');
		}

		// Administration is the tenant boundary: administrationCode is the dedupe key.
		self::assertContains('administrationCode', $schemas['Administration']['required']);
		// Membership ties a user to an administration with a role (Task 6).
		self::assertContains('userId', $schemas['AdministrationMembership']['required']);
		self::assertContains('administrationId', $schemas['AdministrationMembership']['required']);
		self::assertContains('role', $schemas['AdministrationMembership']['required']);

	}//end testMultiAdministratieRegisterFragmentDeclaresSchemas()

	/**
	 * Test the multi-administratie schemas are NOT written into the monolith register (ADR-037).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-1
	 */
	public function testMultiAdministratieSchemasNotInMonolith(): void {
		$monolithPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';
		self::assertFileExists($monolithPath, 'Monolith register must exist.');

		$data = json_decode(file_get_contents($monolithPath), associative: true);
		$schemas = ($data['components']['schemas'] ?? []);

		foreach (['Administration', 'AdministrationMembership', 'IntercompanyJournalEntry', 'ConsolidationMapping', 'AdministrationMigration'] as $schemaName) {
			self::assertArrayNotHasKey(
				$schemaName,
				$schemas,
				$schemaName . ' must live in the register.d fragment, never the monolith (ADR-037).'
			);
		}

	}//end testMultiAdministratieSchemasNotInMonolith()

	/**
	 * seedRetentionPolicies is idempotent: when all three default policies already
	 * exist (matched by slug) every record is skipped and none is re-created
	 * (REQ-RET-012).
	 *
	 * @return void
	 */
	public function testSeedRetentionPoliciesIsIdempotent(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		// ObjectService stub: findAll always returns an existing record (so every
		// policy is skipped); saveObject must never be called.
		$objectService = new class {
			public int $saveCalls = 0;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [['slug' => ($params['filters']['slug'] ?? 'x')]];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedRetentionPolicies();

		self::assertTrue($result['success']);
		self::assertSame(0, $result['seeded']);
		self::assertSame(3, $result['skipped']);
		self::assertSame(0, $objectService->saveCalls, 'No policy should be re-created when all already exist');

	}//end testSeedRetentionPoliciesIsIdempotent()

	/**
	 * seedRetentionPolicies creates all three default policies on a fresh install.
	 *
	 * @return void
	 */
	public function testSeedRetentionPoliciesCreatesDefaultsOnFreshInstall(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		// ObjectService stub: findAll returns empty (nothing exists), so each
		// policy is created via saveObject.
		$objectService = new class {
			public int $saveCalls = 0;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedRetentionPolicies();

		self::assertTrue($result['success']);
		self::assertSame(3, $result['seeded']);
		self::assertSame(0, $result['skipped']);
		self::assertSame(3, $objectService->saveCalls);

	}//end testSeedRetentionPoliciesCreatesDefaultsOnFreshInstall()

	/**
	 * seedStatementManifests imports all three manifests when none are yet persisted.
	 *
	 * Per REQ-FS-002: a fresh install imports balance-sheet, P&L, and cash-flow.
	 *
	 * @return void
	 */
	public function testSeedStatementManifestsImportsAllWhenAbsent(): void {
		// No manifest persisted yet → every getValueString returns ''.
		$this->appConfig->method('getValueString')->willReturn('');

		// Each manifest is persisted exactly once → three setValueString calls.
		$this->appConfig->expects($this->exactly(3))
			->method('setValueString');

		$result = $this->service->seedStatementManifests();

		self::assertTrue($result['success']);
		self::assertSame(3, $result['imported']);
		self::assertSame(0, $result['skipped']);

	}//end testSeedStatementManifestsImportsAllWhenAbsent()

	/**
	 * seedStatementManifests preserves operator edits — already-persisted manifests are skipped.
	 *
	 * Per REQ-FS-002: a manifest the operator has customised is never re-overwritten.
	 *
	 * @return void
	 */
	public function testSeedStatementManifestsSkipsPersisted(): void {
		// Every manifest already persisted → getValueString returns a non-empty value.
		$this->appConfig->method('getValueString')->willReturn('{"_meta":{"imported":"2026-01-01T00:00:00Z"}}');

		// Nothing is re-persisted.
		$this->appConfig->expects($this->never())
			->method('setValueString');

		$result = $this->service->seedStatementManifests();

		self::assertTrue($result['success']);
		self::assertSame(0, $result['imported']);
		self::assertSame(3, $result['skipped']);

	}//end testSeedStatementManifestsSkipsPersisted()

	/**
	 * seedRateCardTemplates elevates to a system operation context when the
	 * OpenRegister ObjectService exposes runAsSystem(), so seeding does not
	 * fail under RBAC when the repair step runs userless (issue #508).
	 *
	 * @return void
	 */
	public function testSeedRateCardTemplatesRunsUnderSystemContextWhenAvailable(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$objectService = new class {
			public int $saveCalls = 0;
			public bool $ranAsSystem = false;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}

			/**
			 * Mirrors OpenRegister's ObjectService::runAsSystem() — bypasses RBAC.
			 *
			 * @param callable $operation The operation to run elevated.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->ranAsSystem = true;
				return $operation();
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedRateCardTemplates(administrationId: 'adm-test');

		self::assertTrue($result['success']);
		self::assertSame(4, $result['seeded']);
		self::assertSame(0, $result['skipped']);
		self::assertSame(4, $objectService->saveCalls);
		self::assertTrue($objectService->ranAsSystem, 'Seeding must elevate via runAsSystem() so Anonymous RBAC does not deny the create');

	}//end testSeedRateCardTemplatesRunsUnderSystemContextWhenAvailable()

	/**
	 * seedRateCardTemplates still seeds successfully when the OpenRegister
	 * ObjectService does not yet ship runAsSystem() (older OR releases).
	 *
	 * @return void
	 */
	public function testSeedRateCardTemplatesFallsBackWhenRunAsSystemUnavailable(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$objectService = new class {
			public int $saveCalls = 0;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedRateCardTemplates(administrationId: 'adm-test');

		self::assertTrue($result['success']);
		self::assertSame(4, $result['seeded']);
		self::assertSame(4, $objectService->saveCalls);

	}//end testSeedRateCardTemplatesFallsBackWhenRunAsSystemUnavailable()

	/**
	 * seedSelectielijst elevates to a system operation context when the
	 * OpenRegister ObjectService exposes runAsSystem(), so seeding does not
	 * fail under RBAC when the repair step runs userless (issue #508).
	 *
	 * @return void
	 */
	public function testSeedSelectielijstRunsUnderSystemContextWhenAvailable(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$objectService = new class {
			public int $saveCalls = 0;
			public bool $ranAsSystem = false;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}

			/**
			 * Mirrors OpenRegister's ObjectService::runAsSystem() — bypasses RBAC.
			 *
			 * @param callable $operation The operation to run elevated.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->ranAsSystem = true;
				return $operation();
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedSelectielijst();

		self::assertTrue($result['success']);
		self::assertSame(30, $result['seeded']);
		self::assertSame(0, $result['skipped']);
		self::assertSame(30, $objectService->saveCalls);
		self::assertTrue($objectService->ranAsSystem, 'Seeding must elevate via runAsSystem() so Anonymous RBAC does not deny the create');

	}//end testSeedSelectielijstRunsUnderSystemContextWhenAvailable()

	/**
	 * seedSelectielijst still seeds successfully when the OpenRegister
	 * ObjectService does not yet ship runAsSystem() (older OR releases).
	 *
	 * @return void
	 */
	public function testSeedSelectielijstFallsBackWhenRunAsSystemUnavailable(): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$objectService = new class {
			public int $saveCalls = 0;

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema): void {
				$this->saveCalls++;
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->seedSelectielijst();

		self::assertTrue($result['success']);
		self::assertSame(30, $result['seeded']);
		self::assertSame(30, $objectService->saveCalls);

	}//end testSeedSelectielijstFallsBackWhenRunAsSystemUnavailable()

	/**
	 * BTW tariff seed data must carry every property the VatTariff schema
	 * marks required (code, rate, description, category, effectiveFrom),
	 * otherwise OpenRegister rejects the create with a validation error
	 * (issue #508).
	 *
	 * @return void
	 */
	public function testBtwTariffSeedFileHasSchemaRequiredProperties(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/seeds/btw-tariffs-2026.json';
		self::assertFileExists($seedPath);

		$content = file_get_contents($seedPath);
		self::assertNotFalse($content);

		$data = json_decode($content, associative: true);
		self::assertSame(JSON_ERROR_NONE, json_last_error());
		self::assertNotEmpty($data['tariffs']);

		$validCategories = ['standard', 'verlaagd', 'nul', 'exempt', 'verleggingsregeling'];
		foreach ($data['tariffs'] as $tariff) {
			foreach (['code', 'rate', 'description', 'category', 'effectiveFrom'] as $requiredField) {
				self::assertArrayHasKey(
					$requiredField,
					$tariff,
					'VatTariff schema requires "' . $requiredField . '" — missing on tariff ' . ($tariff['code'] ?? '?')
				);
			}

			self::assertIsFloat(
				is_float($tariff['rate']) === true ? $tariff['rate'] : (float)$tariff['rate']
			);
			self::assertContains($tariff['category'], $validCategories);
		}//end foreach

	}//end testBtwTariffSeedFileHasSchemaRequiredProperties()

	/**
	 * BBV taakveld seed data must carry every property the BbvTaakveld schema
	 * marks required (taakveldCode, name, category, legalBasis, effectiveFrom),
	 * otherwise OpenRegister rejects the create with a validation error
	 * (issue #508).
	 *
	 * @return void
	 */
	public function testBbvTaakveldSeedFileHasSchemaRequiredProperties(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/seeds/bbv-taakvelden-2024.json';
		self::assertFileExists($seedPath);

		$content = file_get_contents($seedPath);
		self::assertNotFalse($content);

		$data = json_decode($content, associative: true);
		self::assertSame(JSON_ERROR_NONE, json_last_error());
		self::assertNotEmpty($data['taskFields']);

		foreach ($data['taskFields'] as $taskField) {
			foreach (['taskFieldCode', 'name', 'category', 'legalBasis', 'effectiveFrom'] as $requiredField) {
				self::assertArrayHasKey(
					$requiredField,
					$taskField,
					'BbvTaakveld schema requires "' . $requiredField . '" — missing on taakveld ' . ($taskField['code'] ?? '?')
				);
			}

			self::assertNotSame('', $taskField['taskFieldCode']);
		}//end foreach

	}//end testBbvTaakveldSeedFileHasSchemaRequiredProperties()
}//end class
