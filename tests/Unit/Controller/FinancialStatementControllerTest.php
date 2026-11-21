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
use OCA\Shillinq\Reporting\CashFlowCalculator;
use OCA\Shillinq\Reporting\ProfitLossComparison;
use OCA\Shillinq\Reporting\StatementCalculator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
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
 * @uses   \OCA\Shillinq\Reporting\CashFlowCalculator
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
	 * @param CashFlowCalculator|null  $cashFlow   The cash flow calculator; one over the seed when null.
	 *
	 * @return FinancialStatementController
	 */
	private function controller(array $params, ?string $userId = 'director', ?StatementCalculator $calculator = null, ?LoggerInterface $logger = null, ?CashFlowCalculator $cashFlow = null): FinancialStatementController {
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
			($cashFlow ?? $this->cashFlow($calculator)),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}

	/**
	 * A cash flow calculator over a statement calculator and the seed's register.
	 *
	 * @param StatementCalculator $calculator The statement calculator.
	 *
	 * @return CashFlowCalculator
	 */
	private function cashFlow(StatementCalculator $calculator): CashFlowCalculator {
		$location = $this->getMockBuilder(RegisterLocation::class)->disableOriginalConstructor()->getMock();
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new CashFlowCalculator($calculator, new InMemoryObjectServiceStub(VanDijkLedger::rows()), $location);
	}

	/**
	 * The cash flow statement of the active administration: the seed's
	 * accounts carry no category, so every balance movement is unclassified
	 * and the statement still adds up.
	 *
	 * @return void
	 */
	public function testTheCashFlowOfTheActiveAdministration(): void {
		$response = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30'])->cashFlow();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame([VanDijkLedger::ADM, 'indirect'], [$data['administrationId'], $data['method']]);
		self::assertSame(48000.0, $data['totals']['operating']);
		self::assertSame(-48000.0, $data['totals']['unclassified']);
		self::assertSame(0.0, $data['totals']['cashChange']);
		self::assertSame(0.0, $data['totals']['difference']);
	}

	/**
	 * The cash flow refuses like the other statements, before anything is read.
	 *
	 * @return void
	 */
	public function testCashFlowRefusals(): void {
		$untouched = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$untouched->expects(self::never())->method('between');
		$calculator = $this->createMock(StatementCalculator::class);

		$anonymous = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30'], null, $calculator, null, $untouched)->cashFlow();
		self::assertSame([Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated']], [$anonymous->getStatus(), $anonymous->getData()]);

		$foreign = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30', 'administrationId' => 'adm-other'], 'director', $calculator, null, $untouched)->cashFlow();
		self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Not found']], [$foreign->getStatus(), $foreign->getData()]);

		$reversed = $this->controller(['from' => '2026-09-30', 'to' => '2026-01-01'], 'director', $calculator, null, $untouched)->cashFlow();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'to must not be before from.']], [$reversed->getStatus(), $reversed->getData()]);

		$noDate = $this->controller(['to' => '2026-09-30'], 'director', $calculator, null, $untouched)->cashFlow();
		self::assertSame(Http::STATUS_BAD_REQUEST, $noDate->getStatus());
	}

	/**
	 * The lines behind one cash flow figure of the active administration add
	 * up to it; the seed's result row is the profit and loss of the period.
	 *
	 * @return void
	 */
	public function testTheLinesBehindACashFlowFigure(): void {
		$statement = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30'])->cashFlow()->getData();
		$result = $statement['operating']['rows'][0];

		$response = $this->controller(['key' => $result['linesKey']])->cashFlowLines();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame([VanDijkLedger::ADM, 'result', '2026-01-01', '2026-09-30'], [$data['administrationId'], $data['scope'], $data['from'], $data['to']]);
		self::assertSame($result['amount'], $data['total']);
		self::assertNotSame([], $data['lines']);
	}

	/**
	 * A profit and loss figure opens the posted lines of its account in its
	 * range, adding up to it (REQ-RFS-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-2.3
	 */
	public function testTheLinesBehindAnAccountFigure(): void {
		$statement = $this->controller(['from' => '2026-01-01', 'to' => '2026-09-30', 'compare' => 'previous-year'])->profitAndLoss()->getData();
		$housing = $statement['expense'][1];

		$response = $this->controller(['key' => $housing['linesKey']])->accountLines();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame([VanDijkLedger::ADM, '4100', 36000.0], [$data['administrationId'], $data['accountNumber'], $data['total']]);
		self::assertSame($housing['amounts']['current'], $data['total']);
	}

	/**
	 * The account lines refuse like the statements, before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-2.3
	 */
	public function testAccountLinesRefusals(): void {
		$untouched = $this->createMock(StatementCalculator::class);
		$untouched->expects(self::never())->method('accountLines');
		$key = '4100~2026-01-01~2026-09-30';

		$anonymous = $this->controller(['key' => $key], null, $untouched)->accountLines();
		self::assertSame([Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated']], [$anonymous->getStatus(), $anonymous->getData()]);

		$foreign = $this->controller(['key' => $key, 'administrationId' => 'adm-other'], 'director', $untouched)->accountLines();
		self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Not found']], [$foreign->getStatus(), $foreign->getData()]);

		$malformed = $this->controller(['key' => '4100~2026'])->accountLines();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'key must be account~from~to or a trial balance line id.']], [$malformed->getStatus(), $malformed->getData()]);
	}

	/**
	 * The lines refuse like the statements, before anything is read, and a
	 * malformed key is a bad request.
	 *
	 * @return void
	 */
	public function testCashFlowLinesRefusals(): void {
		$untouched = $this->getMockBuilder(CashFlowCalculator::class)->disableOriginalConstructor()->getMock();
		$untouched->expects(self::never())->method('lines');
		$calculator = $this->createMock(StatementCalculator::class);
		$key = 'result~2026-01-01~2026-09-30';

		$anonymous = $this->controller(['key' => $key], null, $calculator, null, $untouched)->cashFlowLines();
		self::assertSame([Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated']], [$anonymous->getStatus(), $anonymous->getData()]);

		$foreign = $this->controller(['key' => $key, 'administrationId' => 'adm-other'], 'director', $calculator, null, $untouched)->cashFlowLines();
		self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Not found']], [$foreign->getStatus(), $foreign->getData()]);

		$malformed = $this->controller(['key' => 'result'])->cashFlowLines();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'key must be scope~from~to.']], [$malformed->getStatus(), $malformed->getData()]);

		$reversed = $this->controller(['key' => 'result~2026-09-30~2026-01-01'])->cashFlowLines();
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'to must not be before from.']], [$reversed->getStatus(), $reversed->getData()]);
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

		$response = (new FinancialStatementController($request, $context, $calculator, new ProfitLossComparison($calculator), $this->cashFlow($calculator), $this->createMock(LoggerInterface::class)))->balanceSheet();

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
