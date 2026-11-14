<?php

/**
 * Gap tests for BudgetEditingController's read actions.
 *
 * Pins lines() and multiYear(): their access refusals, the parameters they
 * pass to the budget service and how a refusal or failure is answered.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Budget\BudgetEditingService;
use OCA\Shillinq\Budget\BudgetEditRefusedException;
use OCA\Shillinq\Controller\BudgetEditingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests lines() and multiYear().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(BudgetEditingController::class)]
#[UsesClass(BudgetEditRefusedException::class)]
final class BudgetEditingControllerGapsTest extends TestCase {

	/**
	 * Mock budget service.
	 *
	 * @var BudgetEditingService&MockObject
	 */
	private BudgetEditingService&MockObject $budgets;

	/**
	 * Mock administration context.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the boundary mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->budgets = $this->createMock(BudgetEditingService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller for the given request parameters.
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param bool                $loggedIn Whether a user is signed in.
	 *
	 * @return BudgetEditingController
	 */
	private function controller(array $params, bool $loggedIn=true): BudgetEditingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => 'T:' . vsprintf($text, $parameters)
		);

		return new BudgetEditingController($request, $this->budgets, $this->context, $session, $l10n, $this->logger);
	}//end controller()

	/**
	 * Anonymous is 401, a missing or foreign administration 404, with no service call.
	 *
	 * @return void
	 */
	public function testReadActionsRefuseBeforeTheService(): void {
		$this->budgets->expects($this->never())->method('lines');
		$this->budgets->expects($this->never())->method('multiYear');
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'mine');

		foreach (['lines', 'multiYear'] as $action) {
			$anonymous = $this->controller(['administrationId' => 'mine'], loggedIn: false)->$action();
			self::assertSame(401, $anonymous->getStatus());
			self::assertSame(['message' => 'T:Not logged in'], $anonymous->getData());

			$missing = $this->controller(['administrationId' => '   '])->$action();
			self::assertSame(404, $missing->getStatus());
			self::assertSame(['message' => 'T:Administration not found'], $missing->getData());

			$foreign = $this->controller(['administrationId' => 'other'])->$action();
			self::assertSame(404, $foreign->getStatus());
			self::assertSame(['message' => 'T:Administration not found'], $foreign->getData());
		}
	}//end testReadActionsRefuseBeforeTheService()

	/**
	 * lines() passes the trimmed ids and answers the service's rows as 200.
	 *
	 * @return void
	 */
	public function testLinesPassesTrimmedIds(): void {
		$this->context->method('canAccess')->with('a1')->willReturn(true);
		$this->budgets->expects($this->once())->method('lines')->with('a1', 'b7')
			->willReturn(['rows' => [['ledgerGroupId' => 'g1', 'months' => [1, 2]]]]);

		$response = $this->controller(['administrationId' => ' a1 ', 'annualBudgetId' => ' b7 '])->lines();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['rows' => [['ledgerGroupId' => 'g1', 'months' => [1, 2]]]], $response->getData());
	}//end testLinesPassesTrimmedIds()

	/**
	 * multiYear() casts fromYear, and defaults it to the current year.
	 *
	 * @return void
	 */
	public function testMultiYearCastsAndDefaultsFromYear(): void {
		$this->context->method('canAccess')->willReturn(true);
		$seen = [];
		$this->budgets->expects($this->exactly(2))->method('multiYear')->willReturnCallback(
			static function (string $administrationId, int $fromYear) use (&$seen): array {
				$seen[] = [$administrationId, $fromYear];
				return ['years' => [$fromYear]];
			}
		);

		$given = $this->controller(['administrationId' => 'a1', 'fromYear' => '2024'])->multiYear();
		self::assertSame(200, $given->getStatus());
		self::assertSame(['years' => [2024]], $given->getData());

		$this->controller(['administrationId' => 'a1'])->multiYear();
		self::assertSame([['a1', 2024], ['a1', (int)date('Y')]], $seen);
	}//end testMultiYearCastsAndDefaultsFromYear()

	/**
	 * A service refusal is a translated 422, any other failure a logged 500.
	 *
	 * @return void
	 */
	public function testReadFailuresAreTranslated(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->budgets->method('lines')->willThrowException(new BudgetEditRefusedException('Budget %s is closed.', ['b7']));
		$this->budgets->method('multiYear')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')
			->with('BudgetEditingController: request failed', ['exception' => 'store down']);

		$refused = $this->controller(['administrationId' => 'a1', 'annualBudgetId' => 'b7'])->lines();
		self::assertSame(422, $refused->getStatus());
		self::assertSame(['message' => 'T:Budget b7 is closed.'], $refused->getData());

		$failed = $this->controller(['administrationId' => 'a1'])->multiYear();
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['message' => 'T:The budget could not be updated.'], $failed->getData());
	}//end testReadFailuresAreTranslated()
}//end class
