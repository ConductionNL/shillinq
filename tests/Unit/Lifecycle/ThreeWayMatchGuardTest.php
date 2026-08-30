<?php

/**
 * Unit tests for ThreeWayMatchGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-payable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ThreeWayMatchGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ThreeWayMatchGuard::matches (REQ-AP-006).
 */
class ThreeWayMatchGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The guard under test.
	 *
	 * @var ThreeWayMatchGuard
	 */
	private ThreeWayMatchGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->guard = new ThreeWayMatchGuard(
			container: $this->container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * 2-way fallback: no PO/GR references → operator-confirmed match passes.
	 *
	 * @return void
	 */
	public function testTwoWayMatchPassesWithoutPoGr(): void {
		// Container must not be touched on the 2-way path.
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->matches(
			['poRef' => null, 'grRef' => null, 'grossAmount' => 1210.00, 'administrationId' => 'adm-1']
		);

		self::assertTrue(condition: $result);

	}//end testTwoWayMatchPassesWithoutPoGr()

	/**
	 * 3-way match passes when GR amount matches within tolerance.
	 *
	 * @return void
	 */
	public function testThreeWayMatchPassesWhenAmountsMatch(): void {
		$objectService = $this->buildObjectServiceStub(gr: ['grossAmount' => 1210.00]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->matches(
			['poRef' => 'PO-1', 'grRef' => 'GR-1', 'grossAmount' => 1210.00, 'administrationId' => 'adm-1']
		);

		self::assertTrue(condition: $result);

	}//end testThreeWayMatchPassesWhenAmountsMatch()

	/**
	 * 3-way match fails when GR amount diverges beyond tolerance.
	 *
	 * @return void
	 */
	public function testThreeWayMatchFailsOnAmountMismatch(): void {
		$objectService = $this->buildObjectServiceStub(gr: ['grossAmount' => 1000.00]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->matches(
			['poRef' => 'PO-1', 'grRef' => 'GR-1', 'grossAmount' => 1210.00, 'administrationId' => 'adm-1']
		);

		self::assertFalse(condition: $result);

	}//end testThreeWayMatchFailsOnAmountMismatch()

	/**
	 * 3-way requested but GR register unavailable → fail closed.
	 *
	 * @return void
	 */
	public function testThreeWayFailsClosedWhenGrUnresolvable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no GoodsReceipt schema'));

		$result = $this->guard->matches(
			['poRef' => 'PO-1', 'grRef' => 'GR-1', 'grossAmount' => 1210.00, 'administrationId' => 'adm-1']
		);

		self::assertFalse(condition: $result);

	}//end testThreeWayFailsClosedWhenGrUnresolvable()

	/**
	 * Build a fluent ObjectService stub returning a single GR record (or none).
	 *
	 * @param array<string,mixed>|null $gr Goods-receipt record to return, or null for empty.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(?array $gr): object {
		return new class($gr) {
			/**
			 * Constructor for the anonymous ObjectService stub.
			 *
			 * @param array<string,mixed>|null $gr Goods-receipt record to return, or null for empty.
			 *
			 * @return void
			 */
			public function __construct(
				private ?array $gr,
			) {
			}//end __construct()

			/**
			 * Set the register (no-op stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set the schema (no-op stub).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Find all records for the current schema context.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->gr === null) {
					return [];
				}

				return [$this->gr];
			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
