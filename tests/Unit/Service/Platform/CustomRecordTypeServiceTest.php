<?php

/**
 * Unit tests for CustomRecordTypeService: shipped versus own schemas and the shown list.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Platform
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Platform;

use InvalidArgumentException;
use OCA\Shillinq\Service\Platform\CustomRecordTypeService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Custom means "in the register but not in the shipped register files".
 */
#[CoversClass(CustomRecordTypeService::class)]
class CustomRecordTypeServiceTest extends TestCase {

	/**
	 * The stored app config values by key.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * The schemas the register holds, by id.
	 *
	 * @var array<int,object>
	 */
	private array $schemas = [];

	private RegisterLocation&MockObject $location;

	private LoggerInterface&MockObject $logger;

	private bool $registerThrows = false;

	/**
	 * Reset the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->location = $this->createMock(RegisterLocation::class);
		$this->location->method('isOpenRegisterAvailable')->willReturn(true);
		$this->location->method('getRegisterSlug')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemas = [
			1 => $this->schema(slug: 'Invoice', title: 'Invoice'),
			2 => $this->schema(slug: 'parkeerplaats', title: 'Parkeerplaats', description: 'Rented parking spaces', icon: 'Car'),
			3 => $this->schema(slug: 'Voertuig', title: 'Voertuig'),
			4 => $this->schema(slug: 'bankconnection', title: 'Bank connection'),
		];
	}//end setUp()

	/**
	 * A stand-in for an Open Register schema entity.
	 *
	 * @param string      $slug        The slug
	 * @param string|null $title       The title
	 * @param string|null $description The description
	 * @param string|null $icon        The icon
	 *
	 * @return object
	 */
	private function schema(string $slug, ?string $title = null, ?string $description = null, ?string $icon = null): object {
		return new class($slug, $title, $description, $icon) {
			/**
			 * Hold the values.
			 *
			 * @param string      $slug        The slug
			 * @param string|null $title       The title
			 * @param string|null $description The description
			 * @param string|null $icon        The icon
			 */
			public function __construct(
				private string $slug,
				private ?string $title,
				private ?string $description,
				private ?string $icon,
			) {
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}

			/**
			 * The title.
			 *
			 * @return string|null
			 */
			public function getTitle(): ?string {
				return $this->title;
			}

			/**
			 * The description.
			 *
			 * @return string|null
			 */
			public function getDescription(): ?string {
				return $this->description;
			}

			/**
			 * The icon.
			 *
			 * @return string|null
			 */
			public function getIcon(): ?string {
				return $this->icon;
			}
		};
	}//end schema()

	/**
	 * The service over the real shipped register files.
	 *
	 * @param string|null $settingsDir A settings directory instead of the real one
	 *
	 * @return CustomRecordTypeService
	 */
	private function service(?string $settingsDir = null): CustomRecordTypeService {
		$test = $this;
		$registerMapper = new class($test) {
			/**
			 * Keep the test for its fixture.
			 *
			 * @param CustomRecordTypeServiceTest $test The test
			 */
			public function __construct(private CustomRecordTypeServiceTest $test) {
			}

			/**
			 * The register.
			 *
			 * @param string $slug The register slug
			 *
			 * @return object
			 */
			public function find(string $slug): object {
				return $this->test->register(slug: $slug);
			}
		};
		$schemaMapper = new class($test) {
			/**
			 * Keep the test for its fixture.
			 *
			 * @param CustomRecordTypeServiceTest $test The test
			 */
			public function __construct(private CustomRecordTypeServiceTest $test) {
			}

			/**
			 * One schema.
			 *
			 * @param int $id The schema id
			 *
			 * @return object
			 */
			public function find(int $id): object {
				return $this->test->schemaById(id: $id);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => str_ends_with($id, 'RegisterMapper') === true ? $registerMapper : $schemaMapper
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new CustomRecordTypeService(
			container: $container,
			registerLocation: $this->location,
			appConfig: $appConfig,
			logger: $this->logger,
			settingsDir: $settingsDir,
		);
	}//end service()

	/**
	 * The register the mapper answers, listing the fixture's schema ids.
	 *
	 * @param string $slug The register slug asked for
	 *
	 * @return object
	 */
	public function register(string $slug): object {
		if ($this->registerThrows === true || $slug !== 'shillinq') {
			throw new RuntimeException('no register');
		}

		$ids = array_keys($this->schemas);
		$ids[] = 99;

		return new class($ids) {
			/**
			 * Hold the ids.
			 *
			 * @param int[] $ids The schema ids
			 */
			public function __construct(private array $ids) {
			}

			/**
			 * The schema ids.
			 *
			 * @return int[]
			 */
			public function getSchemas(): array {
				return $this->ids;
			}
		};
	}//end register()

	/**
	 * A fixture schema; an unknown id throws as the mapper would.
	 *
	 * @param int $id The schema id
	 *
	 * @return object
	 */
	public function schemaById(int $id): object {
		if (isset($this->schemas[$id]) === false) {
			throw new RuntimeException('no schema');
		}

		return $this->schemas[$id];
	}//end schemaById()

	/**
	 * The real shipped files name the monolith's and the fragments' schemas.
	 *
	 * @return void
	 */
	public function testShippedSlugsComeFromTheMonolithAndEveryFragment(): void {
		$shipped = $this->service()->shippedSlugs();

		$this->assertContains('invoice', $shipped);
		$this->assertContains('bankconnection', $shipped);
		$this->assertContains('feeschedule', $shipped);
		$this->assertNotContains('parkeerplaats', $shipped);
		$this->assertSame(array_values(array_unique($shipped)), $shipped);
		$this->assertTrue($this->service()->isShipped(slug: 'BankConnection'));
		$this->assertFalse($this->service()->isShipped(slug: 'Parkeerplaats'));
	}//end testShippedSlugsComeFromTheMonolithAndEveryFragment()

	/**
	 * Only schemas the app did not ship are custom, sorted by title.
	 *
	 * @return void
	 */
	public function testCustomTypesLeaveOutShippedSchemas(): void {
		$this->assertSame(
			[
				['slug' => 'parkeerplaats', 'title' => 'Parkeerplaats', 'description' => 'Rented parking spaces', 'icon' => 'Car'],
				['slug' => 'Voertuig', 'title' => 'Voertuig', 'description' => '', 'icon' => null],
			],
			$this->service()->customTypes()
		);
	}//end testCustomTypesLeaveOutShippedSchemas()

	/**
	 * Without Open Register there are no custom types and no lookup.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereAreNoCustomTypes(): void {
		$this->location = $this->createMock(RegisterLocation::class);
		$this->location->method('isOpenRegisterAvailable')->willReturn(false);
		$this->location->expects($this->never())->method('getRegisterSlug');

		$this->assertSame([], $this->service()->customTypes());
	}//end testWithoutOpenRegisterThereAreNoCustomTypes()

	/**
	 * An unreadable register is logged and yields no custom types.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterYieldsNoTypes(): void {
		$this->registerThrows = true;
		$this->logger->expects($this->once())->method('warning')
			->with('Shillinq: cannot read the register for custom record types', ['exception' => 'no register']);

		$this->assertSame([], $this->service()->customTypes());
	}//end testAnUnreadableRegisterYieldsNoTypes()

	/**
	 * Nothing is shown until an administrator chooses.
	 *
	 * @return void
	 */
	public function testNothingIsShownByDefault(): void {
		$this->assertSame([], $this->service()->shownTypes());
		$this->assertSame([false, false], array_column($this->service()->customTypesWithState(), 'shown'));
	}//end testNothingIsShownByDefault()

	/**
	 * Saving stores canonical slugs once and shows exactly those types.
	 *
	 * @return void
	 */
	public function testSavingShowsTheChosenTypes(): void {
		$service = $this->service();

		$shown = $service->saveShown(slugs: ['Parkeerplaats', 'parkeerplaats']);

		$this->assertSame('["parkeerplaats"]', $this->config[CustomRecordTypeService::CONFIG_KEY]);
		$this->assertSame(['parkeerplaats'], array_column($shown, 'slug'));
		$this->assertSame(
			['parkeerplaats' => true, 'Voertuig' => false],
			array_column($service->customTypesWithState(), 'shown', 'slug')
		);
	}//end testSavingShowsTheChosenTypes()

	/**
	 * Saving an empty list hides every type.
	 *
	 * @return void
	 */
	public function testSavingAnEmptyListHidesAll(): void {
		$this->config[CustomRecordTypeService::CONFIG_KEY] = '["parkeerplaats"]';

		$this->assertSame([], $this->service()->saveShown(slugs: []));
		$this->assertSame('[]', $this->config[CustomRecordTypeService::CONFIG_KEY]);
	}//end testSavingAnEmptyListHidesAll()

	/**
	 * A shipped slug, an unknown slug and a non-string are refused, and nothing is stored.
	 *
	 * @return array<string,array{array<mixed>,string}>
	 */
	public static function refusedLists(): array {
		return [
			'shipped' => [['Invoice'], 'Record type "Invoice" ships with shillinq and cannot be chosen'],
			'unknown' => [['Spaceship'], 'Record type "Spaceship" does not exist in the register'],
			'not a string' => [[42], 'Every record type must be a schema slug'],
			'empty' => [[''], 'Every record type must be a schema slug'],
		];
	}//end refusedLists()

	/**
	 * Refusals name the offending slug and store nothing.
	 *
	 * @param array<mixed> $slugs   The list sent
	 * @param string       $message The refusal
	 *
	 * @return void
	 *
	 * @dataProvider refusedLists
	 */
	public function testRefusedListsStoreNothing(array $slugs, string $message): void {
		try {
			$this->service()->saveShown(slugs: array_merge(['parkeerplaats'], $slugs));
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertArrayNotHasKey(CustomRecordTypeService::CONFIG_KEY, $this->config);
	}//end testRefusedListsStoreNothing()

	/**
	 * A stored slug that is no longer custom (since shipped or removed) is not shown.
	 *
	 * @return void
	 */
	public function testAStaleStoredSlugIsNotShown(): void {
		$this->config[CustomRecordTypeService::CONFIG_KEY] = '["invoice","gone","Voertuig"]';

		$this->assertSame(['Voertuig'], array_column($this->service()->shownTypes(), 'slug'));
	}//end testAStaleStoredSlugIsNotShown()

	/**
	 * Unreadable stored config counts as nothing shown.
	 *
	 * @return void
	 */
	public function testUnreadableConfigShowsNothing(): void {
		$this->config[CustomRecordTypeService::CONFIG_KEY] = '{not json';

		$this->assertSame([], $this->service()->shownTypes());
	}//end testUnreadableConfigShowsNothing()

	/**
	 * Switching one type on keeps the others; switching it off drops only it.
	 *
	 * @return void
	 */
	public function testSwitchingOneTypeKeepsTheOthers(): void {
		$service = $this->service();

		$service->switchShown(slug: 'Voertuig', shown: true);
		$service->switchShown(slug: 'PARKEERPLAATS', shown: true);
		$this->assertSame('["Voertuig","parkeerplaats"]', $this->config[CustomRecordTypeService::CONFIG_KEY]);
		$this->assertSame(['parkeerplaats' => true, 'Voertuig' => true], $service->shownMap());

		$shown = $service->switchShown(slug: 'voertuig', shown: false);
		$this->assertSame(['parkeerplaats'], array_column($shown, 'slug'));
		$this->assertSame(['parkeerplaats' => true, 'Voertuig' => false], $service->shownMap());
	}//end testSwitchingOneTypeKeepsTheOthers()

	/**
	 * Switching a shipped type is refused, even switching it off.
	 *
	 * @return void
	 */
	public function testSwitchingAShippedTypeIsRefused(): void {
		$this->config[CustomRecordTypeService::CONFIG_KEY] = '["parkeerplaats"]';

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Record type "Invoice" ships with shillinq and cannot be chosen');
		try {
			$this->service()->switchShown(slug: 'Invoice', shown: false);
		} finally {
			$this->assertSame('["parkeerplaats"]', $this->config[CustomRecordTypeService::CONFIG_KEY]);
		}
	}//end testSwitchingAShippedTypeIsRefused()

	/**
	 * A settings directory without register files ships nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySettingsDirShipsNothing(): void {
		$this->assertSame([], $this->service(settingsDir: sys_get_temp_dir() . '/no-such-shillinq-settings')->shippedSlugs());
	}//end testAnEmptySettingsDirShipsNothing()
}//end class
