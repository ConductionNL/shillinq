<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FinancialStatementController;
use OCA\Shillinq\Reporting\ProfitLossComparison;
use OCA\Shillinq\Reporting\StatementCalculator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Reporting\Statement\VanDijkLedger;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The statement endpoints check the caller and the administration first (REQ-RFS-001, REQ-RFS-002).
 *
 * @covers \OCA\Shillinq\Controller\FinancialStatementController
 * @uses   \OCA\Shillinq\Reporting\ProfitLossComparison
 * @uses   \OCA\Shillinq\Reporting\StatementCalculator
 * @uses   \OCA\Shillinq\Reporting\StatementPeriods
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class FinancialStatementControllerTest extends TestCase {

	/**
	 * A controller for a caller who belongs to the Van Dijk administration only.
	 *
	 * @param array<string, string>    $params     The request parameters.
	 * @param string|null              $userId     The caller.
	 * @param StatementCalculator|null $calculator The calculator; the seed's when null.
	 * @param LoggerInterface|null     $logger     The logger.
	 *
	 * @return FinancialStatementController
	 */
	private function controller(array $params, ?string $userId = 'director', ?StatementCalculator $calculator = null, ?LoggerInterface $logger = null): FinancialStatementController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => VanDijkLedger::ADM]);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === VanDijkLedger::ADM);
		$calculator ??= VanDijkLedger::calculator($this, VanDijkLedger::rows());

		return new FinancialStatementController(
			$request,
			$context,
			$calculator,
			new ProfitLossComparison($calculator),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}

	/**
	 * The compared profit and loss of the active administration.
	 *
	 * @return void
	 */
	public function testTheProfitAndLossOfTheActiveAdministration(): void {
		$response = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30', 'compare' => 'previous-year'])->profitAndLoss();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['current' => 48000.0, 'previous-year' => 30000.0], $response->getData()['totals']['result']['amounts']);
	}

	/**
	 * The balance sheet as of a date.
	 *
	 * @return void
	 */
	public function testTheBalanceSheetAsOfADate(): void {
		$response = $this->controller(['asOf' => '2026-09-30', 'administrationId' => VanDijkLedger::ADM])->balanceSheet();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(174000.0, $response->getData()['totals']['assets']);
		self::assertSame(0.0, $response->getData()['totals']['difference']);
	}

	/**
	 * Refusals, before anything is read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$untouched = $this->createMock(StatementCalculator::class);
		$untouched->expects(self::never())->method('balancesAsOf');
		$untouched->expects(self::never())->method('resultsBetween');

		$anonymous = $this->controller(['asOf' => '2026-09-30'], null, $untouched)->balanceSheet();
		self::assertSame([Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated']], [$anonymous->getStatus(), $anonymous->getData()]);

		$foreign = $this->controller(['asOf' => '2026-09-30', 'administrationId' => 'adm-other'], 'director', $untouched)->balanceSheet();
		self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Not found']], [$foreign->getStatus(), $foreign->getData()]);

		$foreignPnl = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30', 'administrationId' => 'adm-other'], 'director', $untouched)->profitAndLoss();
		self::assertSame(Http::STATUS_NOT_FOUND, $foreignPnl->getStatus());

		$badDate = $this->controller(['asOf' => '30-09-2026'], 'director', $untouched)->balanceSheet();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'asOf must be a date as YYYY-MM-DD.']], [$badDate->getStatus(), $badDate->getData()]);

		$badCompare = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30', 'compare' => 'budget'], 'director', $untouched)->profitAndLoss();
		self::assertSame(Http::STATUS_BAD_REQUEST, $badCompare->getStatus());

		$reversed = $this->controller(['from' => '2026-09-30', 'to' => '2026-01-01'], 'director', $untouched)->profitAndLoss();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'to must not be before from.']], [$reversed->getStatus(), $reversed->getData()]);
	}

	/**
	 * No administration named and none active is a bad request.
	 *
	 * @return void
	 */
	public function testNoAdministration(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('director');
		$context->method('buildContext')->willReturn([]);
		$context->expects(self::never())->method('canAccess');
		$calculator = $this->createMock(StatementCalculator::class);

		$response = (new FinancialStatementController($request, $context, $calculator, new ProfitLossComparison($calculator), $this->createMock(LoggerInterface::class)))->balanceSheet();

		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'No administration selected']], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * A failure while computing is logged and answered without its message.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndMasked(): void {
		$calculator = $this->createMock(StatementCalculator::class);
		$calculator->method('balancesAsOf')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('FinancialStatementController: statement failed', ['exception' => 'register down']);

		$response = $this->controller(['asOf' => '2026-09-30'], 'director', $calculator, $logger)->balanceSheet();

		self::assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Statement unavailable']], [$response->getStatus(), $response->getData()]);
	}
}
