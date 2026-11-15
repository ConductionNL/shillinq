<?php

/**
 * Unit tests for RevenueWindow.
 *
 * The request value object for a revenue window: the administration-id shape it reads from the request and the static ISO-date (YYYY-MM-DD) check its bounds are held to.
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
 * @spec openspec/specs/bookkeeping-revenue/spec.md
 *
 * @covers \OCA\Shillinq\Controller\RevenueWindow
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RevenueWindow;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class RevenueWindowTest extends TestCase {
	private function window(array $params): RevenueWindow {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new RevenueWindow($request);
	}//end window()

	public function testWellFormedAdministration(): void {
		$this->assertTrue($this->window(['administrationId' => 'adm-1'])->wellFormedAdministration());
		$this->assertFalse($this->window([])->wellFormedAdministration());
		$this->assertFalse($this->window(['administrationId' => 'bad id!'])->wellFormedAdministration());
	}//end testWellFormedAdministration()

	public function testReadsAndTrimsTheBounds(): void {
		$window = $this->window(['administrationId' => 'adm-1', 'from' => '  2026-01-01  ', 'to' => '2026-01-31']);
		$this->assertSame('2026-01-01', $window->from);
		$this->assertSame('2026-01-31', $window->to);
	}//end testReadsAndTrimsTheBounds()

	public function testIsoDateCheck(): void {
		$this->assertTrue(RevenueWindow::isIsoDate('2026-01-01'));
		$this->assertFalse(RevenueWindow::isIsoDate('2026-1-1'));
		$this->assertFalse(RevenueWindow::isIsoDate('2026-01-01T00:00:00Z'));
		$this->assertFalse(RevenueWindow::isIsoDate(''));
	}//end testIsoDateCheck()
}//end class
