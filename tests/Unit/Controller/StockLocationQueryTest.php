<?php

/**
 * Unit tests for StockLocationQuery.
 *
 * The request value object for a stock-location lookup: the three required ids, their completeness, their identifier shape and the log context it exposes.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-stock-ledger/spec.md
 *
 * @covers \OCA\Shillinq\Controller\StockLocationQuery
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\StockLocationQuery;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class StockLocationQueryTest extends TestCase {
	private function query(array $params): StockLocationQuery {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new StockLocationQuery($request);
	}//end query()

	private const FULL = ['administration_id' => 'a1', 'location_id' => 'loc-1', 'sku' => 'SKU-1'];

	public function testCompletenessNeedsAllThree(): void {
		$this->assertTrue($this->query(self::FULL)->isComplete());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'location_id' => 'loc-1'])->isComplete());
		$this->assertFalse($this->query([])->isComplete());
	}//end testCompletenessNeedsAllThree()

	public function testWellFormedChecksEveryId(): void {
		$this->assertTrue($this->query(self::FULL)->isWellFormed());
		$this->assertFalse($this->query(['administration_id' => 'bad id!', 'location_id' => 'loc-1', 'sku' => 'SKU-1'])->isWellFormed());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'location_id' => 'loc-1', 'sku' => 'bad sku!'])->isWellFormed());
	}//end testWellFormedChecksEveryId()

	public function testLogContextCarriesTheIds(): void {
		$this->assertSame(['administrationId' => 'a1', 'locationId' => 'loc-1', 'sku' => 'SKU-1'], $this->query(self::FULL)->logContext());
	}//end testLogContextCarriesTheIds()
}//end class
