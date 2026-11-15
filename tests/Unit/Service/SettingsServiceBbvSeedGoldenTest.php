<?php

/**
 * Golden record of SettingsService's per-administration BBV seeds.
 *
 * Each scenario runs one BBV seed against a recording object-service
 * double and compares the result, every setRegister/setSchema/findAll/
 * saveObject call, every log line and every app config write with
 * tests/Unit/Service/fixtures/settings-bbv-seed-golden.json.
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * BBV seed golden tests.
 *
 * @covers \OCA\Shillinq\Service\SettingsService
 * @covers \OCA\Shillinq\Service\BbvAdministrationSeedService
 * @covers \OCA\Shillinq\Service\Seed\SeedImporter
 * @uses   \OCA\Shillinq\Service\Seed\SeedImport
 * @uses   \OCA\Shillinq\Service\Seed\SeedMessages
 * @uses   \OCA\Shillinq\Service\RegisterLocation
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SettingsServiceBbvSeedGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/settings-bbv-seed-golden.json';

	private const SEEDS = ['mappingsMunicipality', 'mappingsWaterschap', 'mappingsCompany', 'programmes', 'budget'];

	private const MODES = ['unavailable', 'noAdmin', 'fresh', 'existing', 'saveFails', 'containerFails', 'dated'];

	/**
	 * Every seed in every mode.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function scenarios(): array {
		$cases = [];
		foreach (self::SEEDS as $seed) {
			foreach (self::MODES as $mode) {
				$cases[$seed . ' ' . $mode] = [$seed, $mode];
			}
		}

		return $cases;

	}//end scenarios()

	/**
	 * The seed matches the golden record for one scenario.
	 *
	 * @param string $seed The seed to run.
	 * @param string $mode The scenario mode.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testSeedMatchesGolden(string $seed, string $mode): void {
		$key      = $seed . ' ' . $mode;
		$recorded = $this->runScenario($seed, $mode);
		$golden   = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			$golden[$key] = $recorded;
			ksort($golden);
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
			file_put_contents(self::GOLDEN, json_encode($golden, $flags) . "\n");
		}

		self::assertSame(
			json_encode($golden[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION),
			json_encode($recorded, JSON_PRESERVE_ZERO_FRACTION)
		);

	}//end testSeedMatchesGolden()

	/**
	 * Run one seed in one mode and return what it did.
	 *
	 * @param string $seed The seed to run.
	 * @param string $mode The scenario mode.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(string $seed, string $mode): array {
		$objects = new RecordingBbvSeedObjectService($mode);
		$logger  = new RecordingBbvSeedLogger();

		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($mode !== 'unavailable');

		$slug = 'books';
		if ($mode === 'existing') {
			$slug = '';
		}

		$adminId = 'adm-7';
		if ($mode === 'noAdmin') {
			$adminId = '';
		}

		$installDate = '';
		if ($mode === 'dated') {
			$installDate = '2025-01-31';
		}

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($slug, $adminId, $installDate): string {
				if ($key === 'register') {
					return $slug;
				}

				if ($key === 'administration_id') {
					return $adminId;
				}

				if ($key === 'bbv_gate_install_date') {
					return $installDate;
				}

				return $app . ':' . $key . ':' . $default;
			}
		);
		$configWrites = [];
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$configWrites): bool {
				if ($value === date('Y-m-d')) {
					$value = '(today)';
				}

				$configWrites[] = [$app, $key, $value];
				return true;
			}
		);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $mode): object {
				if ($mode === 'containerFails') {
					throw new RuntimeException('no service ' . $id);
				}

				return $objects;
			}
		);

		$service = new SettingsService(
			appConfig: $appConfig,
			registerLocation: new RegisterLocation(appConfig: $appConfig, appManager: $appManager),
			container: $container,
			groupManager: $this->createStub(IGroupManager::class),
			userSession: $this->createStub(IUserSession::class),
			logger: $logger,
		);

		$result = match ($seed) {
			'mappingsMunicipality' => $service->seedBbvAccountMappings($adminId, 'municipality'),
			'mappingsWaterschap' => $service->seedBbvAccountMappings($adminId, 'waterschap'),
			'mappingsCompany' => $service->seedBbvAccountMappings($adminId, 'bv'),
			'programmes' => $service->seedBbvProgrammes($adminId),
			'budget' => $service->seedBudgetBbvMappings($adminId),
		};

		return ['result' => $result, 'calls' => $this->digest($objects->calls), 'log' => $logger->lines, 'configWrites' => $configWrites];

	}//end runScenario()

	/**
	 * The first calls in full, then a count and a hash of all of them.
	 *
	 * @param array<int, array<string, mixed>> $calls The recorded calls.
	 *
	 * @return array<string, mixed>
	 */
	private function digest(array $calls): array {
		return [
			'count' => count($calls),
			'sha256' => hash('sha256', (string) json_encode($calls, JSON_PRESERVE_ZERO_FRACTION)),
			'head' => array_slice($calls, 0, 12),
		];

	}//end digest()
}//end class

/**
 * Object-service double that records every call.
 *
 * The findAll() probe answers with a row on every odd-numbered probe in mode "existing";
 * saveObject() throws on the second save in mode "saveFails".
 */
final class RecordingBbvSeedObjectService {

	/**
	 * Recorded calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Number of findAll() probes so far.
	 *
	 * @var integer
	 */
	private int $probes = 0;

	/**
	 * Number of saveObject() calls so far.
	 *
	 * @var integer
	 */
	private int $saves = 0;

	/**
	 * @param string $mode The scenario mode.
	 */
	public function __construct(
		private string $mode,
	) {
	}

	/**
	 * @param mixed $register The register.
	 *
	 * @return self
	 */
	public function setRegister(mixed $register): self {
		$this->calls[] = ['setRegister' => $register];
		return $this;
	}

	/**
	 * @param mixed $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(mixed $schema): self {
		$this->calls[] = ['setSchema' => $schema];
		return $this;
	}

	/**
	 * @param mixed $config The query.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function findAll(mixed $config): array {
		$this->calls[] = ['findAll' => $config];
		$this->probes++;
		if ($this->mode === 'existing' && ($this->probes % 2) === 1) {
			return [['id' => 'existing-' . $this->probes]];
		}

		return [];
	}

	/**
	 * @param mixed $object   The object.
	 * @param mixed $register The register.
	 * @param mixed $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(mixed $object, mixed $register, mixed $schema): array {
		$this->calls[] = ['saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema]];
		$this->saves++;
		if ($this->mode === 'saveFails' && $this->saves === 2) {
			throw new RuntimeException('save refused');
		}

		return ['id' => 'saved-' . $this->saves];
	}
}//end class

/**
 * Logger that records every line.
 */
final class RecordingBbvSeedLogger extends AbstractLogger {

	/**
	 * Recorded lines.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $lines = [];

	/**
	 * @param mixed             $level   The level.
	 * @param string|Stringable $message The message.
	 * @param array<mixed>      $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->lines[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
	}
}//end class
