<?php

/**
 * Unit tests for StockMoveOffsetCreator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StockMoveOffsetCreator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cancelling a posted stock move writes a mirror-image, locked offset move
 * once: it swaps source and destination, refuses without an id or an
 * administration, is idempotent per original, and fails closed when the
 * write cannot be made.
 *
 * @covers \OCA\Shillinq\Lifecycle\StockMoveOffsetCreator
 */
final class StockMoveOffsetCreatorTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An object service with the given existing offsets that records saves.
	 *
	 * @param mixed           $existing What findAll answers, or a Throwable to raise.
	 * @param \Throwable|null $saveFails What saveObject raises, if anything.
	 *
	 * @return object The double.
	 */
	private function objectService(mixed $existing = [], ?\Throwable $saveFails = null): object {
		return new class ($existing, $saveFails) {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @var string
			 */
			public string $register = '';

			/**
			 * @param mixed           $existing  The findAll answer.
			 * @param \Throwable|null $saveFails The save failure.
			 */
			public function __construct(private readonly mixed $existing, private readonly ?\Throwable $saveFails) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->register = $register;

				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed The rows.
			 */
			public function findAll(array $config): mixed {
				$this->queries[] = $config;
				if ($this->existing instanceof \Throwable) {
					throw $this->existing;
				}

				return $this->existing;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];

				return $object;
			}
		};
	}//end objectService()

	/**
	 * The creator over the given object service and configured register.
	 *
	 * @param object $objectService The object service double.
	 * @param string $register      The configured register slug.
	 *
	 * @return StockMoveOffsetCreator The creator.
	 */
	private function creator(object $objectService, string $register = 'shillinq-test'): StockMoveOffsetCreator {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		return new StockMoveOffsetCreator($container, $config, $this->logger);
	}//end creator()

	/**
	 * A posted move.
	 *
	 * @return array<string, mixed> The move.
	 */
	private static function original(): array {
		return [
			'id' => 'mv-1',
			'administrationId' => 'adm-1',
			'movementNumber' => 'SM-0042',
			'itemId' => 'item-7',
			'quantity' => '12',
			'unitCost' => '3.5',
			'movementType' => 'issue',
			'sourceLocationId' => 'loc-a',
			'destinationLocationId' => 'loc-b',
		];
	}//end original()

	/**
	 * The offset mirrors the original: locations swapped, locked, posted, linked back.
	 *
	 * @return void
	 */
	public function testTheOffsetMirrorsTheOriginal(): void {
		$service = $this->objectService();

		$this->assertTrue($this->creator($service)->emitOffset(self::original()));

		$this->assertCount(1, $service->saves);
		$this->assertSame('shillinq-test', $service->saves[0]['register']);
		$this->assertSame('StockMove', $service->saves[0]['schema']);
		$offset = $service->saves[0]['object'];
		$this->assertSame('SM-0042-CANCEL', $offset['movementNumber']);
		$this->assertSame('item-7', $offset['itemId']);
		$this->assertSame(12.0, $offset['quantity']);
		$this->assertSame(3.5, $offset['unitCost']);
		$this->assertSame('issue', $offset['movementType']);
		$this->assertSame('loc-b', $offset['sourceLocationId']);
		$this->assertSame('loc-a', $offset['destinationLocationId']);
		$this->assertSame('cancellation', $offset['movementReason']);
		$this->assertSame('Offset for SM-0042', $offset['notes']);
		$this->assertSame('adm-1', $offset['administrationId']);
		$this->assertTrue($offset['locked']);
		$this->assertSame('mv-1', $offset['offsetOfMoveId']);
		$this->assertSame('posted', $offset['lifecycleState']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $offset['postedAt']);
		$this->assertSame(
			[['filters' => ['offsetOfMoveId' => 'mv-1', 'administrationId' => 'adm-1']]],
			$service->queries
		);
	}//end testTheOffsetMirrorsTheOriginal()

	/**
	 * The id may come from @self, and a missing type defaults to transfer.
	 *
	 * @return void
	 */
	public function testTheIdMayComeFromSelf(): void {
		$service  = $this->objectService();
		$original = ['@self' => ['id' => 'mv-9'], 'administrationId' => 'adm-1', 'movementNumber' => 'SM-9'];

		$this->assertTrue($this->creator($service, '')->emitOffset($original));

		$this->assertSame('shillinq', $service->saves[0]['register'], 'a blank register falls back to the app id');
		$this->assertSame('mv-9', $service->saves[0]['object']['offsetOfMoveId']);
		$this->assertSame('transfer', $service->saves[0]['object']['movementType']);
		$this->assertNull($service->saves[0]['object']['sourceLocationId']);
	}//end testTheIdMayComeFromSelf()

	/**
	 * Without an id or an administration the offset is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAMoveWithoutIdOrAdministrationIsRefused(): void {
		$service = $this->objectService();
		$this->logger->expects($this->exactly(2))
			->method('info')
			->with('StockMoveOffsetCreator: emit denied — missing id/administrationId', ['movementNumber' => 'SM-0042']);
		$creator = $this->creator($service);

		$noId = self::original();
		unset($noId['id']);
		$noAdministration = self::original();
		$noAdministration['administrationId'] = '';

		$this->assertFalse($creator->emitOffset($noId));
		$this->assertFalse($creator->emitOffset($noAdministration));
		$this->assertSame([], $service->saves);
		$this->assertSame([], $service->queries);
	}//end testAMoveWithoutIdOrAdministrationIsRefused()

	/**
	 * An existing offset makes a repeat a success without a second write.
	 *
	 * @return void
	 */
	public function testAnExistingOffsetIsNotWrittenTwice(): void {
		$service = $this->objectService([['id' => 'off-1']]);

		$this->assertTrue($this->creator($service)->emitOffset(self::original()));
		$this->assertSame([], $service->saves);
	}//end testAnExistingOffsetIsNotWrittenTwice()

	/**
	 * An existence check that fails proceeds to the write.
	 *
	 * @return void
	 */
	public function testAFailedExistenceCheckStillWrites(): void {
		$service = $this->objectService(new RuntimeException('search down'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('StockMoveOffsetCreator: existence check failed; proceeding', ['exception' => 'search down']);

		$this->assertTrue($this->creator($service)->emitOffset(self::original()));
		$this->assertCount(1, $service->saves);
	}//end testAFailedExistenceCheckStillWrites()

	/**
	 * A write that fails is a logged refusal.
	 *
	 * @return void
	 */
	public function testAFailedWriteFailsClosed(): void {
		$service = $this->objectService([], new RuntimeException('disk full'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'StockMoveOffsetCreator: emitOffset failed — denying (fail-closed)',
				['movementNumber' => 'SM-0042', 'exception' => 'disk full']
			);

		$this->assertFalse($this->creator($service)->emitOffset(self::original()));
	}//end testAFailedWriteFailsClosed()
}//end class
