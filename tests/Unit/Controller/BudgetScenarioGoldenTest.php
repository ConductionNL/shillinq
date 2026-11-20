<?php

/**
 * Golden tests for BudgetScenarioController outcomes.
 *
 * Pins the exact refusal bodies, their order, the store and evaluator calls
 * and the failure log entries of promote() and evaluate() before the split.
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

use OCA\Shillinq\Controller\BudgetScenarioController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetScenarioDefaultPromoter;
use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use OCA\Shillinq\Service\BudgetScenarioReader;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exact outcomes of promote() and evaluate().
 */
final class BudgetScenarioGoldenTest extends TestCase {
	/** @var array<string, mixed> Request parameters. */
	private array $params = [];

	/** @var bool Whether a user is logged in. */
	private bool $loggedIn = true;

	/** @var bool Result of canAccess. */
	private bool $canAccess = true;

	/** @var list<string> Calls made, in order. */
	private array $calls = [];

	/** @var list<array{0: string, 1: array}> Logged errors. */
	private array $logged = [];

	/** @var array<string, \Throwable> Failure per called method. */
	private array $failures = [];

	/** @var array<string, mixed> Context loaded by the reader. */
	private array $loaded = [];

	/**
	 * Record a call and throw its configured failure.
	 *
	 * @param string $call The call label.
	 * @param string $key  The failure key.
	 *
	 * @return void
	 */
	private function record(string $call, string $key): void {
		$this->calls[] = $call;
		if (isset($this->failures[$key]) === true) {
			throw $this->failures[$key];
		}
	}//end record()

	/**
	 * Build the controller over recording doubles.
	 *
	 * @return BudgetScenarioController
	 */
	private function controller(): BudgetScenarioController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => $this->params[$name] ?? $default
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->loggedIn === true ? $this->createMock(IUser::class) : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(function (string $administrationId) {
			$this->calls[] = 'canAccess:'.$administrationId;
			return $this->canAccess;
		});
		$promoter = $this->createMock(BudgetScenarioDefaultPromoter::class);
		$promoter->method('promote')->willReturnCallback(function (string $scenarioId) {
			$this->record('promote:'.$scenarioId, 'promote');
			return ['administrationId' => 'adm-9', 'scenarioId' => $scenarioId];
		});
		$reader = $this->createMock(BudgetScenarioReader::class);
		$reader->method('resolveAnnualBudgetIds')->willReturnCallback(function (string $administrationId, int $fiscalYear) {
			$this->record('resolve:'.$administrationId.':'.$fiscalYear, 'resolve');
			return ['ab-1'];
		});
		$reader->method('loadContext')->willReturnCallback(function (string $administrationId, array $annualBudgetIds) {
			$this->record('load:'.$administrationId.':'.implode(',', $annualBudgetIds), 'load');
			return $this->loaded;
		});
		$evaluator = $this->createMock(BudgetScenarioEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(function (...$args) {
			$this->record('evaluate:'.json_encode($args), 'evaluate');
			return ['k1' => ['cell' => 1], 'k2' => ['cell' => 2]];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) {
			$this->logged[] = [$message, $context];
		});

		return new BudgetScenarioController(
			request: $request,
			promoter: $promoter,
			reader: $reader,
			evaluator: $evaluator,
			context: $context,
			userSession: $session,
			logger: $logger,
		);
	}//end controller()

	/**
	 * The context the reader loads in the happy path.
	 *
	 * @return array<string, mixed>
	 */
	private static function loadedContext(): array {
		return [
			'scenarios' => [['@self' => ['id' => 'other']], ['@self' => ['id' => 'scn-1']]],
			'budgetLines' => ['bl'],
			'ledgerGroups' => ['lg'],
			'cashflowRecurringRows' => ['cr'],
			'modifiersByScenarioId' => ['scn-1' => ['m1']],
		];
	}//end loadedContext()

	/**
	 * Promote refusals, success and failures.
	 *
	 * @return void
	 */
	public function testPromote(): void {
		$this->loggedIn = false;
		$response = $this->controller()->promote(scenarioId: 'scn-1');
		$this->assertSame([401, ['error' => 'Authentication required']], [$response->getStatus(), $response->getData()]);

		$this->loggedIn = true;
		$response = $this->controller()->promote(scenarioId: '  ');
		$this->assertSame([400, ['error' => 'scenarioId must be a valid identifier']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->calls);

		$response = $this->controller()->promote(scenarioId: ' scn-1 ');
		$this->assertSame([200, ['data' => ['administrationId' => 'adm-9', 'scenarioId' => 'scn-1']]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['promote:scn-1', 'canAccess:adm-9'], $this->calls);

		$this->canAccess = false;
		$response = $this->controller()->promote(scenarioId: 'scn-1');
		$this->assertSame([404, ['error' => 'Scenario not found']], [$response->getStatus(), $response->getData()]);

		$this->calls = [];
		$this->failures['promote'] = new \RuntimeException('missing');
		$response = $this->controller()->promote(scenarioId: 'scn-1');
		$this->assertSame([404, ['error' => 'Scenario not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['promote:scn-1'], $this->calls);
		$this->assertSame([], $this->logged);

		$this->failures['promote'] = new \LogicException('broken');
		$response = $this->controller()->promote(scenarioId: 'scn-1');
		$this->assertSame([500, ['error' => 'An unexpected error occurred']], [$response->getStatus(), $response->getData()]);
		$this->assertSame('BudgetScenarioController: promote failed', $this->logged[0][0]);
		$this->assertSame(['exceptionClass', 'exception', 'origin'], array_keys($this->logged[0][1]));
		$this->assertSame([\LogicException::class, 'broken'], [$this->logged[0][1]['exceptionClass'], $this->logged[0][1]['exception']]);
	}//end testPromote()

	/**
	 * Evaluate refusals, in check order.
	 *
	 * @return array<string, array>
	 */
	public static function evaluateRefusals(): array {
		return [
			'anonymous' => [false, true, 'scn-1', [], 401, 'Authentication required', []],
			'bad scenario' => [true, true, 'sc n', ['administration_id' => '!'], 400, 'scenarioId must be a valid identifier', []],
			'no administration' => [true, true, 'scn-1', [], 400, 'administration_id is required', []],
			'bad administration' => [true, true, 'scn-1', ['administration_id' => 'a b'], 400, 'administration_id is required', []],
			'no access' => [true, false, 'scn-1', ['administration_id' => ' adm-1 ', 'fiscal_year' => '1'], 404, 'Scenario not found', ['canAccess:adm-1']],
			'year low' => [true, true, 'scn-1', ['administration_id' => 'adm-1', 'fiscal_year' => '1999'], 400, 'fiscal_year must be a plausible year', ['canAccess:adm-1']],
			'year high' => [true, true, 'scn-1', ['administration_id' => 'adm-1', 'fiscal_year' => '2101'], 400, 'fiscal_year must be a plausible year', ['canAccess:adm-1']],
		];
	}//end evaluateRefusals()

	/**
	 * Each evaluate refusal answers its exact body and stops before the store.
	 *
	 * @dataProvider evaluateRefusals
	 *
	 * @param bool                 $loggedIn  Logged in.
	 * @param bool                 $canAccess Access result.
	 * @param string               $scenario  Scenario id.
	 * @param array<string, mixed> $params    Request parameters.
	 * @param int                  $status    Expected status.
	 * @param string               $error     Expected error.
	 * @param list<string>         $calls     Expected calls.
	 *
	 * @return void
	 */
	public function testEvaluateRefusals(bool $loggedIn, bool $canAccess, string $scenario, array $params, int $status, string $error, array $calls): void {
		$this->loggedIn = $loggedIn;
		$this->canAccess = $canAccess;
		$this->params = $params;
		$response = $this->controller()->evaluate(scenarioId: $scenario);
		$this->assertSame([$status, ['error' => $error]], [$response->getStatus(), $response->getData()]);
		$this->assertSame($calls, $this->calls);
	}//end testEvaluateRefusals()

	/**
	 * The happy path: calls, arguments and the response envelope.
	 *
	 * @return void
	 */
	public function testEvaluateAllowed(): void {
		$this->params = ['administration_id' => 'adm-1', 'fiscal_year' => '2026'];
		$this->loaded = self::loadedContext();
		$response = $this->controller()->evaluate(scenarioId: ' scn-1 ');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['data' => ['scenarioId' => 'scn-1', 'fiscalYear' => 2026, 'cells' => [['cell' => 1], ['cell' => 2]]]], $response->getData());
		$this->assertSame(
			['canAccess:adm-1', 'resolve:adm-1:2026', 'load:adm-1:ab-1', 'evaluate:'.json_encode([['bl'], ['lg'], ['cr'], ['m1'], 2026])],
			$this->calls
		);

		$this->calls = [];
		$this->params = ['administration_id' => 'adm-1'];
		$this->loaded['scenarios'] = [['id' => 'scn-1']];
		$this->loaded['modifiersByScenarioId'] = [];
		$this->controller()->evaluate(scenarioId: 'scn-1');
		$year = (int)date('Y');
		$this->assertSame('evaluate:'.json_encode([['bl'], ['lg'], ['cr'], [], $year]), $this->calls[3]);
	}//end testEvaluateAllowed()

	/**
	 * An unknown scenario is a 404 after the store read; failures are 500s.
	 *
	 * @return void
	 */
	public function testEvaluateUnknownAndFailures(): void {
		$this->params = ['administration_id' => 'adm-1', 'fiscal_year' => '2026'];
		$this->loaded = self::loadedContext();
		$response = $this->controller()->evaluate(scenarioId: 'scn-x');
		$this->assertSame([404, ['error' => 'Scenario not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['canAccess:adm-1', 'resolve:adm-1:2026', 'load:adm-1:ab-1'], $this->calls);

		foreach (['resolve' => 3, 'load' => 3, 'evaluate' => 4] as $key => $count) {
			$this->calls = [];
			$this->logged = [];
			$this->failures = [$key => new \RuntimeException($key.' down')];
			$response = $this->controller()->evaluate(scenarioId: 'scn-1');
			$this->assertSame([500, ['error' => 'An unexpected error occurred']], [$response->getStatus(), $response->getData()]);
			$this->assertSame('BudgetScenarioController: evaluate failed', $this->logged[0][0]);
			$this->assertSame($key.' down', $this->logged[0][1]['exception']);
			$this->assertCount($count - (int)($key === 'resolve'), $this->calls);
		}
	}//end testEvaluateUnknownAndFailures()
}//end class
