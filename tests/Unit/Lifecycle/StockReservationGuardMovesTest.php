<?php

/**
 * Unit tests for StockReservationGuard's reserve, commit and release.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\StockReservationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stock move reserves on its source when drafted, moves stock from source
 * to destination when posted, and releases the reservation when cancelled.
 * Quantities are compared in hundredths, every write is a compare-and-set on
 * the row's version, and anything that cannot be decided denies.
 *
 * The register is an in-memory InventoryStock table behind the object
 * service double; nothing else is mocked but the logger and app config.
 *
 * @covers \OCA\Shillinq\Lifecycle\StockReservationGuard
 * @uses \OCA\Shillinq\Util\ObjectIdentifier
 */
final class StockReservationGuardMovesTest extends TestCase {

	/**
	 * The InventoryStock rows, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stock = [];

	/**
	 * The updates written, as [id, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $updates = [];

	/**
	 * The rows created.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	/**
	 * Version the register reports on re-read, when it differs from the read.
	 *
	 * @var integer|null
	 */
	private ?int $concurrentVersion = null;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];

				return array_values(
					array_filter(
						$this->stock,
						static fn (array $row): bool => $row['administrationId'] === $filters['administrationId']
							&& $row['locationId'] === $filters['locationId']
							&& $row['sku'] === $filters['sku']
					)
				);
			}
		);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				if (isset($this->stock[$id]) === false) {
					return null;
				}

				$row = $this->stock[$id];
				if ($this->concurrentVersion !== null) {
					$row['version'] = $this->concurrentVersion;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);

				return $entity;
			}
		);
		$this->objects->method('updateObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->updates[]         = [$objectId, $data];
				$this->stock[$objectId] = $data;

				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->created[] = $object;

				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard.
	 *
	 * @return StockReservationGuard The guard.
	 */
	private function guard(): StockReservationGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new StockReservationGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * Put a stock row in the register.
	 *
	 * @param string $id       The row id.
	 * @param string $location The location.
	 * @param mixed  $quantity On hand.
	 * @param mixed  $reserved Reserved.
	 *
	 * @return void
	 */
	private function stockAt(string $id, string $location, mixed $quantity, mixed $reserved = 0): void {
		$this->stock[$id] = [
			'id' => $id,
			'administrationId' => 'adm-1',
			'locationId' => $location,
			'sku' => 'SKU-1',
			'quantity' => $quantity,
			'reservedQuantity' => $reserved,
			'version' => 3,
		];
	}//end stockAt()

	/**
	 * A move of a quantity of SKU-1.
	 *
	 * @param mixed       $quantity    The quantity.
	 * @param string|null $source      The source location.
	 * @param string|null $destination The destination location.
	 *
	 * @return array<string, mixed> The move.
	 */
	private static function move(mixed $quantity, ?string $source = 'loc-a', ?string $destination = null): array {
		return [
			'movementNumber' => 'SM-1',
			'administrationId' => 'adm-1',
			'itemId' => 'SKU-1',
			'quantity' => $quantity,
			'sourceLocationId' => $source,
			'destinationLocationId' => $destination,
			'unitCost' => '2.5',
		];
	}//end move()

	/**
	 * Reserving adds to the reservation and bumps the version.
	 *
	 * @return void
	 */
	public function testReservingAddsToTheReservation(): void {
		$this->stockAt('st-a', 'loc-a', 10, 2.5);

		$this->assertTrue($this->guard()->reserveReservation(self::move('4.25')));

		$this->assertCount(1, $this->updates);
		$this->assertSame('st-a', $this->updates[0][0]);
		$this->assertSame(6.75, $this->updates[0][1]['reservedQuantity']);
		$this->assertSame(4, $this->updates[0][1]['version']);
		$this->assertSame(10, $this->updates[0][1]['quantity'], 'on hand is untouched by a reservation');
	}//end testReservingAddsToTheReservation()

	/**
	 * Reserving more than is available is denied.
	 *
	 * @return void
	 */
	public function testReservingBeyondAvailableIsDenied(): void {
		$this->stockAt('st-a', 'loc-a', 10, 7);
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'StockReservationGuard: reserve denied — insufficient available',
				['movementNumber' => 'SM-1', 'availableCents' => 300, 'requestedCents' => 301]
			);

		$this->assertFalse($this->guard()->reserveReservation(self::move('3.01')));
		$this->assertSame([], $this->updates);
	}//end testReservingBeyondAvailableIsDenied()

	/**
	 * Reserving at a location with no stock row is denied.
	 *
	 * @return void
	 */
	public function testReservingWithoutAStockRowIsDenied(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with('StockReservationGuard: reserve denied — no InventoryStock row', ['movementNumber' => 'SM-1', 'sku' => 'SKU-1', 'locationId' => 'loc-a']);

		$this->assertFalse($this->guard()->reserveReservation(self::move(1)));
	}//end testReservingWithoutAStockRowIsDenied()

	/**
	 * A receipt (no source) or a zero quantity reserves nothing and passes.
	 *
	 * @return void
	 */
	public function testNothingToReserveOrReleasePasses(): void {
		$guard = $this->guard();

		$this->assertTrue($guard->reserveReservation(self::move(5, null)));
		$this->assertTrue($guard->reserveReservation(self::move(0)));
		$this->assertTrue($guard->releaseReservation(self::move(5, '')));
		$this->assertTrue($guard->releaseReservation(self::move('-1')));
		$this->assertTrue($guard->commitReservation(self::move(0, 'loc-a', 'loc-b')));
		$this->assertSame([], $this->updates);
	}//end testNothingToReserveOrReleasePasses()

	/**
	 * A row changed since it was read is not overwritten.
	 *
	 * @return void
	 */
	public function testAConcurrentChangeIsNotOverwritten(): void {
		$this->stockAt('st-a', 'loc-a', 10);
		$this->concurrentVersion = 4;
		$this->logger->expects($this->once())->method('info')->with('StockReservationGuard: CAS collision', ['id' => 'st-a']);

		$this->assertFalse($this->guard()->reserveReservation(self::move(1)));
		$this->assertSame([], $this->updates);
	}//end testAConcurrentChangeIsNotOverwritten()

	/**
	 * Committing a transfer takes from the source's stock and reservation and adds to the destination.
	 *
	 * @return void
	 */
	public function testCommittingATransferMovesTheStock(): void {
		$this->stockAt('st-a', 'loc-a', 10, 4);
		$this->stockAt('st-b', 'loc-b', 1.5);

		$this->assertTrue($this->guard()->commitReservation(self::move(4, 'loc-a', 'loc-b')));

		$this->assertSame(['st-a', 'st-b'], array_column($this->updates, 0));
		$this->assertSame(6.0, $this->updates[0][1]['quantity']);
		$this->assertSame(0.0, $this->updates[0][1]['reservedQuantity']);
		$this->assertSame(4, $this->updates[0][1]['version']);
		$this->assertSame(date('Y-m-d'), $this->updates[0][1]['lastMovementDate']);
		$this->assertSame(5.5, $this->updates[1][1]['quantity']);
		$this->assertSame(4, $this->updates[1][1]['version']);
	}//end testCommittingATransferMovesTheStock()

	/**
	 * Committing into a location with no row creates one holding the moved quantity.
	 *
	 * @return void
	 */
	public function testCommittingIntoANewLocationCreatesItsRow(): void {
		$this->assertTrue($this->guard()->commitReservation(self::move('2.5', null, 'loc-c')));

		$this->assertSame(
			[[
				'administrationId' => 'adm-1',
				'locationId' => 'loc-c',
				'sku' => 'SKU-1',
				'quantity' => 2.5,
				'reservedQuantity' => 0,
				'unitCost' => 2.5,
				'lastMovementDate' => date('Y-m-d'),
				'version' => 1,
			]],
			$this->created
		);
	}//end testCommittingIntoANewLocationCreatesItsRow()

	/**
	 * A commit the source cannot cover, or with no source row, is denied without a write.
	 *
	 * @return void
	 */
	public function testACommitTheSourceCannotCoverIsDenied(): void {
		$this->stockAt('st-a', 'loc-a', 10, 3);
		$this->logger->expects($this->once())
			->method('info')
			->with('StockReservationGuard: commit denied — source reservation/on-hand inconsistent', ['movementNumber' => 'SM-1', 'sourceLocation' => 'loc-a']);
		$guard = $this->guard();

		$this->assertFalse($guard->commitReservation(self::move(4, 'loc-a', 'loc-b')), 'reserved 3 cannot cover 4');
		$this->assertFalse($guard->commitReservation(self::move(4, 'loc-x', 'loc-b')), 'no source row');
		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->created);
	}//end testACommitTheSourceCannotCoverIsDenied()

	/**
	 * Releasing takes the quantity off the reservation, never below zero.
	 *
	 * @return void
	 */
	public function testReleasingNeverGoesBelowZero(): void {
		$this->stockAt('st-a', 'loc-a', 10, 2);

		$this->assertTrue($this->guard()->releaseReservation(self::move(5)));
		$this->assertSame(0.0, $this->updates[0][1]['reservedQuantity']);
		$this->assertTrue($this->guard()->releaseReservation(self::move(1, 'loc-x')), 'no row to release from');
		$this->assertCount(1, $this->updates);
	}//end testReleasingNeverGoesBelowZero()

	/**
	 * A row without an id cannot be compare-and-set, so the write is denied.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdIsNotWritten(): void {
		$this->stockAt('st-a', 'loc-a', 10);
		$this->stock['st-a']['id'] = '';

		$this->assertFalse($this->guard()->reserveReservation(self::move(1)));
	}//end testARowWithoutAnIdIsNotWritten()

	/**
	 * A register that throws denies every step.
	 *
	 * @return void
	 */
	public function testAFailingRegisterDenies(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willThrowException(new RuntimeException('register offline'));
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$guard = new StockReservationGuard($config, $this->logger, $objects);

		$this->assertFalse($guard->reserveReservation(self::move(1)));
		$this->assertFalse($guard->commitReservation(self::move(1, 'loc-a', 'loc-b')));
		$this->assertFalse($guard->releaseReservation(self::move(1)));
		$this->assertSame(
			[
				'StockReservationGuard: reserveReservation failed — denying (fail-closed)',
				'StockReservationGuard: commitReservation failed — denying (fail-closed)',
				'StockReservationGuard: releaseReservation failed — denying (fail-closed)',
			],
			$messages
		);
	}//end testAFailingRegisterDenies()
}//end class
