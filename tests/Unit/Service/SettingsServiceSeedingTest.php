<?php

/**
 * Seeding-path unit tests for SettingsService.
 *
 * Covers the default-administration membership grant, the fixed-assets and
 * WMO commercial-activity demo seeds, and every outcome branch of the
 * register configuration import.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SettingsService seeding tests.
 */
#[CoversClass(SettingsService::class)]
#[UsesClass(RegisterLocation::class)]
#[UsesClass(SeedImporter::class)]
#[UsesClass(SeedImport::class)]
#[UsesClass(SeedMessages::class)]
#[UsesClass(ObjectIdentifier::class)]
final class SettingsServiceSeedingTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @param object|null          $objectService     What the container hands out as ObjectService.
	 * @param bool                 $openRegister      Whether OpenRegister is installed.
	 * @param string|null          $userId            Session user uid, or null for anonymous.
	 * @param LoggerInterface|null $logger            Optional logger mock.
	 * @param object|null          $configService     What the container hands out as ConfigurationService.
	 * @param \Throwable|null      $containerFailure  Thrown by the container on any get().
	 *
	 * @return SettingsService
	 */
	private function makeService(
		?object $objectService = null,
		bool $openRegister = true,
		?string $userId = 'admin',
		?LoggerInterface $logger = null,
		?object $configService = null,
		?\Throwable $containerFailure = null,
	): SettingsService {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($openRegister);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $configService, $containerFailure): object {
				if ($containerFailure !== null) {
					throw $containerFailure;
				}

				if ($id === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $configService;
				}

				return $objectService;
			}
		);

		$userSession = $this->createStub(IUserSession::class);
		if ($userId !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new SettingsService(
			appConfig: $appConfig,
			registerLocation: new RegisterLocation(appConfig: $appConfig, appManager: $appManager),
			container: $container,
			groupManager: $this->createStub(IGroupManager::class),
			userSession: $userSession,
			logger: ($logger ?? $this->createStub(LoggerInterface::class)),
		);

	}//end makeService()

	/**
	 * A ConfigurationService stand-in answering importFromApp() with $result.
	 *
	 * @param array<string,mixed> $result Canned import result.
	 *
	 * @return object
	 */
	private function configService(array $result): object {
		return new class($result) {
			/**
			 * Arguments of the last importFromApp() call.
			 *
			 * @var array<string,mixed>
			 */
			public array $lastCall = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $result Canned result.
			 */
			public function __construct(private array $result) {
			}

			/**
			 * Mirror of ConfigurationService::importFromApp().
			 *
			 * @param string              $appId   App id.
			 * @param array<string,mixed> $data    Configuration.
			 * @param string              $version Version.
			 * @param bool                $force   Force flag.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->lastCall = ['appId' => $appId, 'version' => $version, 'force' => $force, 'hasData' => $data !== []];
				return $this->result;
			}
		};

	}//end configService()

	/**
	 * Saves made on $schema in the stub.
	 *
	 * @param InMemoryObjectServiceStub $os     The stub.
	 * @param string                    $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function savedOn(InMemoryObjectServiceStub $os, string $schema): array {
		return array_values(
			array_map(
				static fn (array $s): array => $s['object'],
				array_filter($os->saved, static fn (array $s): bool => $s['schema'] === $schema)
			)
		);

	}//end savedOn()

	/**
	 * A fresh install seeds the default administration and grants the
	 * calling user an owner membership on its administrationCode.
	 *
	 * @return void
	 */
	public function testDefaultAdministrationGrantsOwnerMembership(): void {
		$os = new InMemoryObjectServiceStub();
		$logger = $this->createMock(LoggerInterface::class);
		$infos = [];
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$infos): void {
				$infos[] = [$message, $context];
			}
		);

		$result = $this->makeService(objectService: $os, userId: 'alice', logger: $logger)->seedDefaultAdministration();

		self::assertTrue($result['success']);
		self::assertSame('ADM-001', $result['administrationCode']);
		self::assertSame(['granted' => true, 'administrationId' => 'ADM-001'], $result['membership']);

		$memberships = $this->savedOn(os: $os, schema: 'AdministrationMembership');
		self::assertCount(1, $memberships);
		self::assertSame('alice', $memberships[0]['userId']);
		self::assertSame('ADM-001', $memberships[0]['administrationId']);
		self::assertSame('eigenaar', $memberships[0]['role']);
		self::assertTrue($memberships[0]['mayPostJournalEntries']);
		self::assertTrue($memberships[0]['mayCloseFiscalYear']);
		self::assertNull($memberships[0]['validUntil']);
		self::assertSame('alice', $memberships[0]['grantedBy']);
		self::assertContains(
			['SettingsService: granted default administration membership', ['userId' => 'alice', 'administrationId' => 'ADM-001']],
			$infos
		);

	}//end testDefaultAdministrationGrantsOwnerMembership()

	/**
	 * An existing membership is left alone.
	 *
	 * @return void
	 */
	public function testDefaultAdministrationKeepsExistingMembership(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [['id' => 'adm-uuid', 'administrationCode' => 'ADM-001']],
				'AdministrationMembership' => [['id' => 'm-1', 'userId' => 'alice', 'administrationId' => 'ADM-001', 'role' => 'boekhouder']],
			]
		);

		$result = $this->makeService(objectService: $os, userId: 'alice')->seedDefaultAdministration();

		self::assertSame(0, $result['seeded']);
		self::assertSame(1, $result['skipped']);
		self::assertSame(
			['granted' => false, 'reason' => 'already a member', 'administrationId' => 'ADM-001'],
			$result['membership']
		);
		self::assertSame([], $os->saved);

	}//end testDefaultAdministrationKeepsExistingMembership()

	/**
	 * Without a session user nothing is granted.
	 *
	 * @return void
	 */
	public function testDefaultAdministrationWithoutUserGrantsNothing(): void {
		$os = new InMemoryObjectServiceStub(
			data: ['Administration' => [['id' => 'adm-uuid', 'administrationCode' => 'ADM-001']]]
		);

		$result = $this->makeService(objectService: $os, userId: null)->seedDefaultAdministration();

		self::assertSame(['granted' => false, 'reason' => 'no authenticated user'], $result['membership']);
		self::assertSame([], $this->savedOn(os: $os, schema: 'AdministrationMembership'));

	}//end testDefaultAdministrationWithoutUserGrantsNothing()

	/**
	 * When the administration cannot be found after seeding, nothing is granted.
	 *
	 * @return void
	 */
	public function testDefaultAdministrationNotFoundGrantsNothing(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willReturn([]);
		$os->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$os->expects($this->once())->method('saveObject')
			->with($this->callback(static fn (array $o): bool => $o['administrationCode'] === 'ADM-001'))
			->willReturn(new ObjectEntityStub(payload: ['id' => 'adm-uuid']));

		$result = $this->makeService(objectService: $os)->seedDefaultAdministration();

		self::assertSame(1, $result['seeded']);
		self::assertSame(
			['granted' => false, 'reason' => 'administration not found: ADM-001'],
			$result['membership']
		);

	}//end testDefaultAdministrationNotFoundGrantsNothing()

	/**
	 * A failing lookup during the grant is logged and reported, not thrown.
	 *
	 * @return void
	 */
	public function testDefaultAdministrationGrantFailureIsReported(): void {
		$calls = 0;
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$os->method('findAll')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					return [['id' => 'adm-uuid']];
				}

				throw new RuntimeException('boom');
			}
		);
		$os->expects($this->never())->method('saveObject');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'SettingsService: failed to grant default administration membership',
			['userId' => 'admin', 'administrationCode' => 'ADM-001', 'exception' => 'boom']
		);

		$result = $this->makeService(objectService: $os, logger: $logger)->seedDefaultAdministration();

		self::assertTrue($result['success']);
		self::assertSame(['granted' => false, 'reason' => 'exception: boom'], $result['membership']);

	}//end testDefaultAdministrationGrantFailureIsReported()

	/**
	 * The fixed-assets demo refuses without OpenRegister or administration.
	 *
	 * @return void
	 */
	public function testFixedAssetsDemoGuards(): void {
		self::assertSame(
			['success' => false, 'message' => 'OpenRegister is not installed or enabled.'],
			$this->makeService(openRegister: false)->seedFixedAssetsDemo(administrationId: 'ADM-001')
		);
		self::assertSame(
			['success' => false, 'message' => 'administrationId must not be empty.'],
			$this->makeService()->seedFixedAssetsDemo(administrationId: '')
		);

	}//end testFixedAssetsDemoGuards()

	/**
	 * A re-run skips every existing asset and schedule and writes nothing.
	 *
	 * @return void
	 */
	public function testFixedAssetsDemoRerunSkipsExisting(): void {
		$os = new InMemoryObjectServiceStub();
		$service = $this->makeService(objectService: $os);

		$first = $service->seedFixedAssetsDemo(administrationId: 'ADM-001');
		self::assertSame(4, $first['seededAssets']);
		self::assertSame(4, $first['seededSchedules']);
		$savesAfterFirst = count($os->saved);

		$second = $service->seedFixedAssetsDemo(administrationId: 'ADM-001');

		self::assertSame(
			[
				'success' => true,
				'message' => 'FixedAsset + DepreciationSchedule demo records seeded successfully.',
				'seededAssets' => 0,
				'skippedAssets' => 4,
				'seededSchedules' => 0,
				'skippedSchedules' => 4,
			],
			$second
		);
		self::assertCount($savesAfterFirst, $os->saved);

	}//end testFixedAssetsDemoRerunSkipsExisting()

	/**
	 * A schedule seeded after its asset already existed links to the
	 * existing asset's id.
	 *
	 * @return void
	 */
	public function testFixedAssetsDemoLinksScheduleToExistingAsset(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'FixedAsset' => [
					['id' => 'existing-uuid', 'assetNumber' => 'FA-DEMO-V-2026-0001', 'administrationId' => 'ADM-002'],
				],
			]
		);

		$result = $this->makeService(objectService: $os)->seedFixedAssetsDemo(administrationId: 'ADM-002');

		self::assertSame(3, $result['seededAssets']);
		self::assertSame(1, $result['skippedAssets']);
		$schedules = $this->savedOn(os: $os, schema: 'DepreciationSchedule');
		$linked = array_values(
			array_filter($schedules, static fn (array $s): bool => $s['scheduleNumber'] === 'SCH-FA-DEMO-V-2026-0001-2026')
		);
		self::assertSame('existing-uuid', $linked[0]['assetRef']);
		self::assertSame('ADM-002', $linked[0]['administrationId']);

	}//end testFixedAssetsDemoLinksScheduleToExistingAsset()

	/**
	 * A failure while seeding is logged and reported.
	 *
	 * @return void
	 */
	public function testFixedAssetsDemoFailureIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq: fixed-assets demo seeding failed',
			['exception' => 'container down']
		);

		$result = $this->makeService(logger: $logger, containerFailure: new RuntimeException('container down'))
			->seedFixedAssetsDemo(administrationId: 'ADM-001');

		self::assertSame(['success' => false, 'message' => 'container down'], $result);

	}//end testFixedAssetsDemoFailureIsReported()

	/**
	 * The WMO seed refuses without OpenRegister or administration.
	 *
	 * @return void
	 */
	public function testWmoSeedGuards(): void {
		self::assertSame(
			['success' => false, 'message' => 'OpenRegister is not installed or enabled.'],
			$this->makeService(openRegister: false)->seedWmoCommercialActivities(administrationId: 'ADM-001')
		);
		self::assertSame(
			['success' => false, 'message' => 'administrationId must not be empty.'],
			$this->makeService()->seedWmoCommercialActivities(administrationId: '')
		);

	}//end testWmoSeedGuards()

	/**
	 * The WMO seed stamps the administration, dedupes per schema and is
	 * idempotent on re-run.
	 *
	 * @return void
	 */
	public function testWmoSeedIsIdempotent(): void {
		$os = new InMemoryObjectServiceStub();
		$service = $this->makeService(objectService: $os);

		$first = $service->seedWmoCommercialActivities(administrationId: 'ADM-007');

		self::assertTrue($first['success']);
		self::assertSame('WMO commercial activities seeded.', $first['message']);
		self::assertSame(2, $first['seeded']);
		self::assertSame(0, $first['skipped']);

		$activities = $this->savedOn(os: $os, schema: 'CommercialActivity');
		self::assertCount(1, $activities);
		self::assertSame('MO-WS-SLIBRUIM-01', $activities[0]['code']);
		self::assertSame('ADM-007', $activities[0]['administrationId']);
		self::assertArrayNotHasKey('_meta', $activities[0]);

		$ikp = $this->savedOn(os: $os, schema: 'IntegralCostPrice');
		self::assertCount(1, $ikp);
		self::assertSame('MO-SP-015', $ikp[0]['commercialActivityId']);
		self::assertSame('2026-Q1', $ikp[0]['period']);

		$second = $service->seedWmoCommercialActivities(administrationId: 'ADM-007');
		self::assertSame(0, $second['seeded']);
		self::assertSame(2, $second['skipped']);
		self::assertCount(2, $os->saved);

	}//end testWmoSeedIsIdempotent()

	/**
	 * A failure while seeding WMO data is logged and reported.
	 *
	 * @return void
	 */
	public function testWmoSeedFailureIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Shillinq: WMO seeding failed', ['exception' => 'no OR']);

		$result = $this->makeService(logger: $logger, containerFailure: new RuntimeException('no OR'))
			->seedWmoCommercialActivities(administrationId: 'ADM-001');

		self::assertSame(['success' => false, 'message' => 'no OR'], $result);

	}//end testWmoSeedFailureIsReported()

	/**
	 * Loading the configuration without OpenRegister is refused with a warning.
	 *
	 * @return void
	 */
	public function testLoadConfigurationWithoutOpenRegister(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Shillinq: OpenRegister not available, skipping register initialization');

		$result = $this->makeService(openRegister: false, logger: $logger)->loadConfiguration();

		self::assertSame(['success' => false, 'message' => 'OpenRegister is not installed or enabled.'], $result);

	}//end testLoadConfigurationWithoutOpenRegister()

	/**
	 * An import with errors reports failure with its errors and warnings.
	 *
	 * @return void
	 */
	public function testLoadConfigurationReportsImportErrors(): void {
		$config = $this->configService(
			result: ['errors' => ['schema X broken'], 'warnings' => ['w1'], 'version' => '2.0.0']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq: register configuration imported with errors',
			['errors' => ['schema X broken']]
		);

		$result = $this->makeService(logger: $logger, configService: $config)->loadConfiguration();

		self::assertSame(
			[
				'success' => false,
				'message' => 'Configuration import completed with errors.',
				'errors' => ['schema X broken'],
				'warnings' => ['w1'],
				'version' => '2.0.0',
			],
			$result
		);
		self::assertSame('shillinq', $config->lastCall['appId']);
		self::assertFalse($config->lastCall['force']);
		self::assertTrue($config->lastCall['hasData']);
		self::assertMatchesRegularExpression('/\+frag\.[0-9a-f]{8}$/', $config->lastCall['version']);

	}//end testLoadConfigurationReportsImportErrors()

	/**
	 * Warnings alone still succeed, are logged, and an absent version reads 'unknown'.
	 *
	 * @return void
	 */
	public function testLoadConfigurationLogsWarnings(): void {
		$config = $this->configService(result: ['warnings' => ['minor drift']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Shillinq: register configuration imported with warnings',
			['warnings' => ['minor drift']]
		);
		$logger->expects($this->never())->method('error');

		$result = $this->makeService(logger: $logger, configService: $config)->loadConfigurationForced();

		self::assertTrue($result['success']);
		self::assertSame(0, $result['skippedSeedObjects']);
		self::assertSame(['minor drift'], $result['warnings']);
		self::assertSame('unknown', $result['version']);
		self::assertTrue($config->lastCall['force']);

	}//end testLoadConfigurationLogsWarnings()

	/**
	 * An empty import result is the version-unchanged skip, not a failure.
	 *
	 * @return void
	 */
	public function testLoadConfigurationTreatsEmptyResultAsUpToDate(): void {
		$result = $this->makeService(configService: $this->configService(result: []))->loadConfiguration();

		self::assertSame(
			[
				'success' => true,
				'skipped' => true,
				'message' => 'Configuration already up-to-date (version-unchanged skip).',
			],
			$result
		);

	}//end testLoadConfigurationTreatsEmptyResultAsUpToDate()

	/**
	 * A failing ConfigurationService lookup is logged and reported.
	 *
	 * @return void
	 */
	public function testLoadConfigurationFailureIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq: configuration import failed',
			['exception' => 'no config service']
		);

		$result = $this->makeService(logger: $logger, containerFailure: new RuntimeException('no config service'))
			->loadConfiguration();

		self::assertSame(['success' => false, 'message' => 'no config service'], $result);

	}//end testLoadConfigurationFailureIsReported()
}//end class
