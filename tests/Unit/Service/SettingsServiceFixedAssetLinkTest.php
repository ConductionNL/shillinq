<?php

/**
 * Unit tests for the asset -> schedule link in SettingsService::seedFixedAssetsDemo().
 *
 * The shipped code resolved the saved FixedAsset's identifier with
 * `method_exists($saved, 'getId')`. OpenRegister's `ObjectEntity` reaches every
 * `get*` accessor through `OCP\AppFramework\Db\Entity::__call()`, so that probe
 * is permanently FALSE, neither arm ran, and `$assetUuidByNumber` stayed empty.
 *
 * The consumer then guards the assignment with `isset($assetUuidByNumber[...])`
 * and saves the schedule ANYWAY — so every seeded DepreciationSchedule was
 * written without `assetRef`, with `assetNumber` already unset, and the link
 * could not be reconstructed afterwards. Nothing threw and nothing was logged
 * (shillinq#527).
 *
 * ⚠️ Two distinct wrong answers are possible here, so this file carries a
 * control for each:
 *
 *   1. The ORIGINAL defect — no `assetRef` key at all.
 *   2. The PLAUSIBLE WRONG REPAIR — `property_exists($saved,'id')` then
 *      `getId()`, which yields the numeric bigint row id. `assetRef` is a
 *      declared relation to `FixedAsset.id`, which OpenRegister renders as the
 *      UUID, so that repair stores a dangling reference. A test that only
 *      asserts "assetRef is present" passes that repair.
 *
 * Therefore every assertion below is on the VALUE, and the numeric row id is
 * asserted absent by name.
 *
 * ⚠️ The shared stub at `tests/stubs/OpenRegister/Db/ObjectEntity.php` declares
 * `getId()` and `getUuid()` concretely, which INVERTS the predicate under test.
 * The double in this file therefore mirrors the real entity's shape — magic
 * accessors via `__call`, a concrete `jsonSerialize()` — and one test asserts
 * that shape so a future edit cannot quietly make this file vacuous.
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
 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that every seeded DepreciationSchedule carries its asset's UUID.
 */
class SettingsServiceFixedAssetLinkTest extends TestCase {

	/**
	 * The numeric bigint row id a saveObject() return yields from getId().
	 *
	 * Asserted ABSENT from every assetRef — it is the wrong identifier space.
	 *
	 * @var integer
	 */
	private const ROW_ID = 7;

	/**
	 * The fake ObjectService the service under test is handed.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build a SettingsService wired to a recording fake ObjectService.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		return new SettingsService(
			$this->createMock(IAppConfig::class),
			$appManager,
			$container,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Stand up the recording fake before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->objectService = new class(self::ROW_ID) {
			/** @var array<int,array<string,mixed>> */
			public array $savedSchedules = [];

			/** @var array<string,string> */
			public array $assetUuids = [];

			private string $schema = '';

			public function __construct(
				private int $rowId,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Nothing exists yet, so every seed row takes the create path.
			 */
			public function findAll(array $query = []): array {
				return [];
			}

			public function saveObject(array $object, string $register, string $schema): object {
				if ($schema === 'DepreciationSchedule') {
					$this->savedSchedules[] = $object;
					return $this->entity(uuid: 'schedule-' . count($this->savedSchedules));
				}

				// A FixedAsset. Mint a per-asset UUID the test can assert against.
				$uuid = 'uuid-' . $object['assetNumber'];
				$this->assetUuids[$object['assetNumber']] = $uuid;
				return $this->entity(uuid: $uuid);
			}

			/**
			 * A double shaped like the REAL ObjectEntity, not like the caller's
			 * wishes: getId()/getUuid() are magic, jsonSerialize() is concrete
			 * and renders `id` as the UUID.
			 */
			public function entity(string $uuid): object {
				return new class($uuid, $this->rowId) {
					/**
					 * Both backing properties are declared, exactly as on the real
					 * ObjectEntity — `property_exists()` must answer TRUE for `id`
					 * AND `uuid`, or the wrong-repair control below cannot fire.
					 */
					public function __construct(
						private string $uuid,
						private int $id,
					) {
					}

					public function __call(string $name, array $arguments): mixed {
						return match ($name) {
							'getId' => $this->id,
							'getUuid' => $this->uuid,
							default => throw new \BadMethodCallException($name),
						};
					}

					public function jsonSerialize(): array {
						return ['id' => $this->uuid, 'name' => 'probe'];
					}
				};
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end setUp()

	/**
	 * The double must mirror the real entity's shape, not the caller's wishes.
	 *
	 * Fixture-faithfulness assertion. If this fails, every other test in this
	 * file has stopped testing the defect it is named for.
	 *
	 * @return void
	 */
	public function testTheDoubleIsFaithfulToTheRealEntityShape(): void {
		$entity = $this->objectService->entity(uuid: 'probe-uuid');

		$this->assertFalse(
			method_exists($entity, 'getId'),
			'getId() must be magic on the double, as it is on the real ObjectEntity'
		);
		$this->assertFalse(
			method_exists($entity, 'getUuid'),
			'getUuid() must be magic on the double, as it is on the real ObjectEntity'
		);
		$this->assertTrue(
			method_exists($entity, 'jsonSerialize'),
			'POSITIVE CONTROL: jsonSerialize() is concrete on the real ObjectEntity'
		);
		$this->assertTrue(
			property_exists($entity, 'id'),
			'the real ObjectEntity declares $id, so the wrong-repair control can fire'
		);
		$this->assertTrue(
			property_exists($entity, 'uuid'),
			'the real ObjectEntity declares $uuid, which is what the correct probe reads'
		);

	}//end testTheDoubleIsFaithfulToTheRealEntityShape()

	/**
	 * The seed must actually run, so a later zero is a measurement not an artefact.
	 *
	 * POSITIVE CONTROL for the assertions below: without this, "no schedule is
	 * missing its assetRef" would also be satisfied by no schedule being saved.
	 *
	 * @return void
	 */
	public function testTheSeedReachesTheScheduleWritePath(): void {
		$result = $this->service()->seedFixedAssetsDemo(administrationId: 'adm-1');

		$this->assertTrue($result['success'], 'seed must succeed for the rest to mean anything');
		$this->assertNotEmpty($this->objectService->assetUuids, 'assets must have been saved');
		$this->assertCount(
			count($this->objectService->assetUuids),
			$this->objectService->savedSchedules,
			'the demo seed pairs one schedule with each asset'
		);

	}//end testTheSeedReachesTheScheduleWritePath()

	/**
	 * Every seeded schedule carries its asset's UUID in assetRef.
	 *
	 * Asserts the VALUE, not merely the key: the plausible wrong repair
	 * (`property_exists('id')` + `getId()`) supplies the key with the numeric
	 * bigint row id, which is a dangling reference into a UUID-typed relation.
	 *
	 * @return void
	 */
	public function testEverySeededScheduleLinksToItsAssetByUuid(): void {
		$this->service()->seedFixedAssetsDemo(administrationId: 'adm-1');

		$linked = [];
		foreach ($this->objectService->savedSchedules as $schedule) {
			$this->assertArrayHasKey(
				'assetRef',
				$schedule,
				'a schedule saved without assetRef is orphaned from its asset for good — '
				. 'assetNumber is unset before the write, so the link cannot be rebuilt'
			);
			$this->assertNotSame(
				(string)self::ROW_ID,
				$schedule['assetRef'],
				'assetRef must be the UUID; the numeric row id is a different identifier '
				. 'space and stores a dangling reference'
			);
			$linked[] = $schedule['assetRef'];
		}

		// Each schedule points at the UUID minted for ITS OWN asset, so a fix
		// that linked every schedule to the same (e.g. last) asset also fails.
		$this->assertSame(
			array_values($this->objectService->assetUuids),
			$linked,
			'each schedule must carry the UUID of the asset it names'
		);

	}//end testEverySeededScheduleLinksToItsAssetByUuid()

	/**
	 * The asset number is still stripped before the write.
	 *
	 * Pins the behaviour the defect made irreversible: the schedule payload
	 * drops assetNumber, so assetRef is the only surviving link.
	 *
	 * @return void
	 */
	public function testAssetNumberIsStrippedFromTheStoredSchedule(): void {
		$this->service()->seedFixedAssetsDemo(administrationId: 'adm-1');

		foreach ($this->objectService->savedSchedules as $schedule) {
			$this->assertArrayNotHasKey('assetNumber', $schedule);
		}

	}//end testAssetNumberIsStrippedFromTheStoredSchedule()

}//end class
