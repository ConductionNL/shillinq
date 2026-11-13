<?php

/**
 * Golden record of SettingsService's inventory demo seeds.
 *
 * Each scenario runs one inventory seed against a recording object-service
 * double and compares the result, every setRegister/setSchema/findAll/
 * saveObject call and every log line with
 * tests/Unit/Service/fixtures/settings-inventory-seed-golden.json.
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
 * Inventory seed golden tests.
 *
 * @covers \OCA\Shillinq\Service\SettingsService
 * @covers \OCA\Shillinq\Service\InventorySeedService
 * @covers \OCA\Shillinq\Service\Seed\SeedImporter
 * @uses   \OCA\Shillinq\Service\Seed\SeedImport
 * @uses   \OCA\Shillinq\Service\Seed\SeedMessages
 * @uses   \OCA\Shillinq\Service\RegisterLocation
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SettingsServiceInventorySeedGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/settings-inventory-seed-golden.json';

	private const SEEDS = ['barcodes', 'lots', 'valuation', 'stock', 'glconfig'];

	private const MODES = ['unavailable', 'noAdmin', 'fresh', 'existing', 'saveFails', 'containerFails'];

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
		$objects = new RecordingInventorySeedObjectService($mode);
		$logger  = new RecordingInventorySeedLogger();

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

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($slug, $adminId): string {
				if ($key === 'register') {
					return $slug;
				}

				if ($key === 'administration_id') {
					return $adminId;
				}

				return $app . ':' . $key . ':' . $default;
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
			'barcodes' => $service->seedInventoryBarcodes(),
			'lots' => $service->seedInventoryLots(),
			'valuation' => $service->seedInventoryValuationExamples($adminId),
			'stock' => $service->seedInventoryStockExamples($adminId),
			'glconfig' => $service->seedInventoryGLConfig($adminId),
		};

		return ['result' => $result, 'calls' => $objects->calls, 'log' => $logger->lines];

	}//end runScenario()
}//end class

/**
 * Object-service double that records every call.
 *
 * The findAll() probe answers with a row on every odd-numbered probe in mode "existing";
 * saveObject() throws on the second save in mode "saveFails".
 */
final class RecordingInventorySeedObjectService {

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
final class RecordingInventorySeedLogger extends AbstractLogger {

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
