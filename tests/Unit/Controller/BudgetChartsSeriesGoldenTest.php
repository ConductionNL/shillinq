<?php

/**
 * Golden tests for BudgetChartsController::series outcomes.
 *
 * Pins the exact refusal bodies, their order, the service arguments and the
 * failure log entries before the action is split.
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

use OCA\Shillinq\Controller\BudgetChartsController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetChartSeriesService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exact outcomes of the chart-series endpoint.
 */
final class BudgetChartsSeriesGoldenTest extends TestCase {
	/** @var array<string, mixed> Request parameters. */
	private array $params = [];

	/** @var string|null Current user. */
	private ?string $user = 'alice';

	/** @var bool|\Throwable Result of canAccess. */
	private bool|\Throwable $canAccess = true;

	/** @var \Throwable|null Failure of the service. */
	private ?\Throwable $failure = null;

	/** @var list<string> Calls made, in order. */
	private array $calls = [];

	/** @var list<array{0: string, 1: array}> Logged errors. */
	private array $logged = [];

	/**
	 * Build the controller over recording doubles.
	 *
	 * @return BudgetChartsController
	 */
	private function controller(): BudgetChartsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => $this->params[$name] ?? $default
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(fn () => $this->user);
		$context->method('canAccess')->willReturnCallback(function (string $administrationId) {
			$this->calls[] = 'canAccess:'.$administrationId;
			if ($this->canAccess instanceof \Throwable) {
				throw $this->canAccess;
			}
			return $this->canAccess;
		});
		$service = $this->createMock(BudgetChartSeriesService::class);
		$service->method('resolveSeries')->willReturnCallback(function (string $a, string $f, string $t, ?string $b = null) {
			$this->calls[] = 'series:'.$a.':'.$f.':'.$t.':'.var_export($b, true);
			if ($this->failure !== null) {
				throw $this->failure;
			}
			return ['months' => []];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) {
			$this->logged[] = [$message, $context];
		});

		return new BudgetChartsController(request: $request, service: $service, context: $context, logger: $logger);
	}//end controller()

	/**
	 * Refusals in check order.
	 *
	 * @return array<string, array>
	 */
	public static function refusals(): array {
		$ok = ['administrationId' => ' adm-1 ', 'from' => '2026-01', 'to' => '2026-03'];
		return [
			'anonymous' => [null, true, ['from' => 'x'], 401, 'Not authenticated', []],
			'no administration' => ['alice', true, ['from' => 'x'], 400, 'administrationId is required', []],
			'bad administration' => ['alice', true, ['administrationId' => 'a b'], 400, 'administrationId is required', []],
			'no access' => ['alice', false, ['administrationId' => 'adm-1', 'from' => 'x'], 404, 'Administration not found', ['canAccess:adm-1']],
			'bad from' => ['alice', true, ['from' => '2026-1'] + $ok, 400, 'from and to must be YYYY-MM', ['canAccess:adm-1']],
			'bad to' => ['alice', true, ['to' => ''] + $ok, 400, 'from and to must be YYYY-MM', ['canAccess:adm-1']],
			'bad budget' => ['alice', true, ['annualBudgetId' => 'a b'] + $ok, 400, 'annualBudgetId must be a valid identifier', ['canAccess:adm-1']],
		];
	}//end refusals()

	/**
	 * Each refusal answers its exact body.
	 *
	 * @dataProvider refusals
	 *
	 * @param string|null          $user      Current user.
	 * @param bool                 $canAccess Access result.
	 * @param array<string, mixed> $params    Request parameters.
	 * @param int                  $status    Expected status.
	 * @param string               $error     Expected error.
	 * @param list<string>         $calls     Expected calls.
	 *
	 * @return void
	 */
	public function testRefusals(?string $user, bool $canAccess, array $params, int $status, string $error, array $calls): void {
		$this->user = $user;
		$this->canAccess = $canAccess;
		$this->params = $params;
		$response = $this->controller()->series();
		$this->assertSame([$status, ['error' => $error]], [$response->getStatus(), $response->getData()]);
		$this->assertSame($calls, $this->calls);
	}//end testRefusals()

	/**
	 * Allowed requests pass trimmed values; an empty budget becomes null.
	 *
	 * @return void
	 */
	public function testAllowed(): void {
		$this->params = ['administrationId' => ' adm-1 ', 'from' => ' 2026-01 ', 'to' => '2026-03', 'annualBudgetId' => ' ab-2 '];
		$response = $this->controller()->series();
		$this->assertSame([200, ['months' => []]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['canAccess:adm-1', "series:adm-1:2026-01:2026-03:'ab-2'"], $this->calls);

		$this->calls = [];
		unset($this->params['annualBudgetId']);
		$this->controller()->series();
		$this->assertSame(['canAccess:adm-1', 'series:adm-1:2026-01:2026-03:NULL'], $this->calls);
	}//end testAllowed()

	/**
	 * Access-check failure, service refusal and service failure.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$this->params = ['administrationId' => 'adm-1', 'from' => '2026-01', 'to' => '2026-03'];
		$this->canAccess = new \RuntimeException('db');
		$response = $this->controller()->series();
		$this->assertSame([500, ['error' => 'Authorization failure']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['BudgetChartsController: administration access check failed', ['exception' => 'db']]], $this->logged);

		$this->canAccess = true;
		$this->logged = [];
		$this->failure = new \InvalidArgumentException('range too wide');
		$response = $this->controller()->series();
		$this->assertSame([400, ['error' => 'range too wide']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->logged);

		$this->failure = new \LogicException('boom');
		$response = $this->controller()->series();
		$this->assertSame([500, ['error' => 'Failed to compute chart series']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['BudgetChartsController: failed to compute chart series', ['administrationId' => 'adm-1', 'exception' => 'boom']]], $this->logged);
	}//end testFailures()
}//end class
