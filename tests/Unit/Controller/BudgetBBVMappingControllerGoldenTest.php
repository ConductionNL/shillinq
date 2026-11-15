<?php

/**
 * Golden test for the BudgetBBVMappingController envelopes and their scope.
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
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BudgetBBVMappingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scope for every active-administration and window shape, and the calls behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BudgetBBVMappingControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The scope the index envelope carries.
	 *
	 * @param mixed      $active The active administration in the context; 'absent' leaves the key out.
	 * @param array|null $window What the fiscal-year service answers.
	 *
	 * @return mixed
	 */
	private function scope(mixed $active, array|null $window=null): mixed {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('buildContext')->willReturnCallback(
			function () use ($active): array {
				$this->calls[] = ['buildContext'];
				if ($active === 'absent') {
					return [];
				}

				return ['activeAdministrationId' => $active];
			}
		);
		$years = $this->createMock(FiscalYearContextService::class);
		$years->method('resolveActiveWindow')->willReturnCallback(
			function (string $administrationId) use ($window): ?array {
				$this->calls[] = ['resolveActiveWindow', $administrationId];
				return $window;
			}
		);

		$controller = new BudgetBBVMappingController($this->createMock(IRequest::class), $session, $this->createMock(IL10N::class), $context, $years);
		return $controller->index()->getData()['scope'];
	}//end scope()

	/**
	 * Without a usable active administration or window the scope is all null.
	 *
	 * @return void
	 */
	public function testEmptyScope(): void {
		$empty = ['administrationId' => null, 'fiscalYear' => null, 'startDate' => null, 'endDate' => null];
		foreach (['absent', null, '', 7] as $active) {
			self::assertSame(expected: $empty, actual: $this->scope(active: $active));
		}

		self::assertSame(expected: $empty, actual: $this->scope(active: 'a1'));
		$expected = [['buildContext'], ['buildContext'], ['buildContext'], ['buildContext'], ['buildContext'], ['resolveActiveWindow', 'a1']];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testEmptyScope()

	/**
	 * With a window the scope carries the administration and the window's year and dates, nothing more.
	 *
	 * @return void
	 */
	public function testWindowScope(): void {
		$window = ['fiscalYear' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'administrationId' => 'x', 'extra' => 1];
		$actual = $this->scope(active: ' a1', window: $window);
		$expected = ['administrationId' => ' a1', 'fiscalYear' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'];
		self::assertSame(expected: $expected, actual: $actual);
		self::assertSame(expected: [['buildContext'], ['resolveActiveWindow', ' a1']], actual: $this->calls);
	}//end testWindowScope()
}//end class
