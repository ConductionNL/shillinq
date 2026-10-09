<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Budget
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Budget;

use OCA\Shillinq\Controller\BudgetEditingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The budget entry endpoints over the real service (REQ-PBE-001 to REQ-PBE-003).
 */
final class BudgetEditingControllerTest extends TestCase {
	use BudgetEditingFixture;

	/**
	 * Seed Gemeente Voorbeeld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * The controller with request parameters.
	 *
	 * @param array<string,mixed> $params    Request parameters.
	 * @param bool                $canAccess Whether the user may act in the administration.
	 * @param bool                $loggedIn  Whether a user is signed in.
	 *
	 * @return BudgetEditingController
	 */
	private function controller(array $params, bool $canAccess = true, bool $loggedIn = true): BudgetEditingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($canAccess);
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new BudgetEditingController($request, $this->service(), $context, $session, $l10n, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Lines, a cell, a spread, the multi-year view and next year through the endpoints.
	 *
	 * @return void
	 */
	public function testTheBudgetIsTypedSpreadAndStartedNextYear(): void {
		$base = ['administrationId' => 'adm-voorbeeld', 'annualBudgetId' => $this->ids['budget-2026']];

		$lines = $this->controller($base)->lines();
		$this->assertSame(200, $lines->getStatus());
		$this->assertCount(3, $lines->getData()['rows']);

		$cell = $this->controller($base + ['ledgerGroupId' => $this->ids['lg-personeel'], 'month' => '1', 'amount' => '20600000', 'expected' => '20000000'])->saveCell();
		$this->assertSame(200, $cell->getStatus());
		$this->assertSame(20600000, $cell->getData()['months'][0]);

		$stale = $this->controller($base + ['ledgerGroupId' => $this->ids['lg-personeel'], 'month' => '1', 'amount' => '1', 'expected' => '20000000'])->saveCell();
		$this->assertSame(422, $stale->getStatus());

		$this->assertSame(400, $this->controller($base + ['ledgerGroupId' => $this->ids['lg-personeel'], 'yearly' => '1200'])->spread()->getStatus());
		$months = array_merge([20600000], array_fill(0, 11, 20000000));
		$spread = $this->controller($base + ['ledgerGroupId' => $this->ids['lg-personeel'], 'yearly' => '247200000', 'expected' => $months])->spread();
		$this->assertSame(200, $spread->getStatus());
		$this->assertSame(array_fill(0, 12, 20600000), $spread->getData()['months']);

		$this->assertSame(422, $this->controller($base + ['percentage' => 'three'])->startNextYear()->getStatus());
		$started = $this->controller($base + ['percentage' => '0'])->startNextYear();
		$this->assertSame(201, $started->getStatus());
		$this->assertSame(2027, $started->getData()['fiscalYear']);

		$view = $this->controller(['administrationId' => 'adm-voorbeeld', 'fromYear' => '2026'])->multiYear();
		$this->assertSame(200, $view->getStatus());
		$this->assertSame([2026, 2027], array_column($view->getData()['years'], 'fiscalYear'));
		$this->assertSame(247200000, $view->getData()['rows'][1]['amounts'][2027]);
	}//end testTheBudgetIsTypedSpreadAndStartedNextYear()

	/**
	 * Signed out answers 401; another administration 404 on every endpoint.
	 *
	 * @return void
	 */
	public function testOutsiderIsRefused(): void {
		$params = ['administrationId' => 'adm-voorbeeld', 'annualBudgetId' => $this->ids['budget-2026']];

		$this->assertSame(401, $this->controller($params, true, false)->lines()->getStatus());
		$outsider = $this->controller($params, false);
		$this->assertSame(404, $outsider->lines()->getStatus());
		$this->assertSame(404, $outsider->saveCell()->getStatus());
		$this->assertSame(404, $outsider->spread()->getStatus());
		$this->assertSame(404, $outsider->multiYear()->getStatus());
		$this->assertSame(404, $outsider->startNextYear()->getStatus());
		$this->assertSame(422, $this->controller(['administrationId' => 'adm-voorbeeld', 'annualBudgetId' => 'budget-other'])->lines()->getStatus());
	}//end testOutsiderIsRefused()
}//end class
