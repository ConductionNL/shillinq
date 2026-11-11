<?php

/**
 * Branch tests for InventoryValuationMethodGuard::checkUniqueActiveSnapshot().
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the query, the own-row rule, the row shapes and the fail-closed path (REQ-INV-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryValuationSnapshotGuardTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The guard under a register slug.
	 *
	 * @param string $register The configured register slug.
	 *
	 * @return InventoryValuationMethodGuard
	 */
	private function guard(string $register = 'shillinq'): InventoryValuationMethodGuard {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		return new InventoryValuationMethodGuard(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);

	}//end guard()

	/**
	 * The proposed snapshot.
	 *
	 * @param array<string,mixed> $extra Fields to add.
	 *
	 * @return array<string,mixed>
	 */
	private static function proposed(array $extra = []): array {
		return $extra + ['productId' => 'P-1', 'warehouse' => 'W-1', 'administrationId' => 'adm-1'];

	}//end proposed()

	/**
	 * A row that serialises to the given data.
	 *
	 * @param mixed $data The serialised data.
	 *
	 * @return JsonSerializable
	 */
	private static function entity(mixed $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $data The serialised data.
			 */
			public function __construct(private readonly mixed $data) {
			}

			/**
			 * The data.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};

	}//end entity()

	/**
	 * Missing coordinates are accepted without a query.
	 *
	 * @return void
	 */
	public function testMissingCoordinatesAreAccepted(): void {
		$this->objects->expects(self::never())->method('findAll');

		self::assertTrue($this->guard()->checkUniqueActiveSnapshot(['productId' => 'P-1', 'warehouse' => 'W-1']));
		self::assertTrue($this->guard()->checkUniqueActiveSnapshot(['productId' => '', 'warehouse' => 'W-1', 'administrationId' => 'adm-1']));
		self::assertTrue($this->guard()->checkUniqueActiveSnapshot(['productId' => 'P-1', 'administrationId' => 'adm-1']));

	}//end testMissingCoordinatesAreAccepted()

	/**
	 * The query filters on the tuple and active status in the configured register; an empty register falls back.
	 *
	 * @return void
	 */
	public function testTheQueryFiltersTheTupleInTheRegister(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects(self::once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->expects(self::once())->method('setSchema')->with('InventoryValuation')->willReturnSelf();
		$objects->expects(self::once())->method('findAll')
			->with(['filters' => ['productId' => 'P-1', 'warehouse' => 'W-1', 'status' => 'active', 'administrationId' => 'adm-1']])
			->willReturn([]);
		$this->objects = $objects;

		self::assertTrue($this->guard(register: '')->checkUniqueActiveSnapshot(self::proposed()));

	}//end testTheQueryFiltersTheTupleInTheRegister()

	/**
	 * The own row is skipped by id, by @self id, and in entity form.
	 *
	 * @return void
	 */
	public function testTheOwnRowIsSkippedInEveryShape(): void {
		$this->objects->method('findAll')->willReturn(
			[
				['id' => 'iv-1'],
				['@self' => ['id' => 'iv-1']],
				self::entity(['id' => 'iv-1']),
				self::entity(['@self' => ['id' => 'iv-1']]),
			]
		);
		$this->logger->expects(self::never())->method('info');

		self::assertTrue($this->guard()->checkUniqueActiveSnapshot(self::proposed(['@self' => ['id' => 'iv-1']])));

	}//end testTheOwnRowIsSkippedInEveryShape()

	/**
	 * A row without a readable id blocks, even when the proposal has no id either.
	 *
	 * @return void
	 */
	public function testARowWithoutReadableIdBlocks(): void {
		$cases = [[[]], ['scalar'], [self::entity('not-an-array')]];
		foreach ($cases as $rows) {
			$objects = $this->createMock(ObjectServiceInterface::class);
			$objects->method('setRegister')->willReturnSelf();
			$objects->method('setSchema')->willReturnSelf();
			$objects->method('findAll')->willReturn($rows);
			$this->objects = $objects;

			self::assertFalse($this->guard()->checkUniqueActiveSnapshot(self::proposed()));
		}

	}//end testARowWithoutReadableIdBlocks()

	/**
	 * Another active row blocks once, with the tuple logged, after own rows are passed.
	 *
	 * @return void
	 */
	public function testAnotherRowBlocksWithTheTupleLogged(): void {
		$this->objects->method('findAll')->willReturn([['id' => 'iv-1'], self::entity(['id' => 'iv-2']), ['id' => 'iv-3']]);
		$this->logger->expects(self::once())->method('info')->with(
			'InventoryValuationMethodGuard: duplicate active snapshot blocked',
			['productId' => 'P-1', 'warehouse' => 'W-1', 'administrationId' => 'adm-1']
		);

		self::assertFalse($this->guard()->checkUniqueActiveSnapshot(self::proposed(['id' => 'iv-1'])));

	}//end testAnotherRowBlocksWithTheTupleLogged()

	/**
	 * A failing query denies, with the error logged.
	 *
	 * @return void
	 */
	public function testAFailingQueryDenies(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::once())->method('error')->with(
			'InventoryValuationMethodGuard: checkUniqueActiveSnapshot failed — denying (fail-closed)',
			['exception' => 'register down']
		);

		self::assertFalse($this->guard()->checkUniqueActiveSnapshot(self::proposed()));

	}//end testAFailingQueryDenies()
}//end class
