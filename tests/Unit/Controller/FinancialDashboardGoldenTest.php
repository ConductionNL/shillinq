<?php

/**
 * Golden tests for FinancialDashboardController response bodies.
 *
 * Pins the exact refusal bodies, the check order, the failure log call and
 * the cash-position administration fallback before the controller is split.
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

use OCA\Shillinq\Controller\FinancialDashboardController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exact outcomes of the series, summary and cash-position endpoints.
 */
final class FinancialDashboardGoldenTest extends TestCase {
	/** @var array<string, mixed> Request parameters. */
	private array $params = [];

	/** @var string|null The current user. */
	private ?string $user = 'alice';

	/** @var list<string> Accessible administrations. */
	private array $accessible = ['adm-1'];

	/** @var bool Result of canAccess. */
	private bool $canAccess = true;

	/** @var array<string, mixed> buildContext result. */
	private array $built = [];

	/** @var list<array{0: string, 1: array}> Logged errors. */
	private array $logged = [];

	/** @var list<string> Calls made on the context and service, in order. */
	private array $calls = [];

	/** @var \Throwable|null Failure thrown by the service. */
	private ?\Throwable $failure = null;

	/**
	 * Build the controller over recording doubles.
	 *
	 * @return FinancialDashboardController
	 */
	private function controller(): FinancialDashboardController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => $this->params[$name] ?? $default
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(function () {
			$this->calls[] = 'currentUserId';
			return $this->user;
		});
		$context->method('accessibleAdministrationIds')->willReturnCallback(function () {
			$this->calls[] = 'accessible';
			return $this->accessible;
		});
		$context->method('canAccess')->willReturnCallback(function (string $administrationId) {
			$this->calls[] = 'canAccess:'.$administrationId;
			return $this->canAccess;
		});
		$context->method('buildContext')->willReturnCallback(function () {
			$this->calls[] = 'buildContext';
			return $this->built;
		});
		$service = $this->createMock(FinancialDashboardService::class);
		foreach (['series', 'summary'] as $method) {
			$service->method($method)->willReturnCallback(function (?string $from, ?string $to) use ($method) {
				$this->calls[] = $method.':'.var_export($from, true).':'.var_export($to, true);
				if ($this->failure !== null) {
					throw $this->failure;
				}
				return ['endpoint' => $method];
			});
		}
		$service->method('cashPosition')->willReturnCallback(function (string $administrationId) {
			$this->calls[] = 'cashPosition:'.$administrationId;
			if ($this->failure !== null) {
				throw $this->failure;
			}
			return ['total' => 5];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) {
			$this->logged[] = [$message, $context];
		});

		return new FinancialDashboardController(request: $request, dashboard: $service, context: $context, logger: $logger);
	}//end controller()

	/**
	 * Refusals of series/summary, in check order.
	 *
	 * @return array<string, array>
	 */
	public static function refusals(): array {
		return [
			'anonymous' => [null, ['adm-1'], ['from' => 'x'], 401, 'Not authenticated', ['currentUserId']],
			'no administrations' => ['alice', [], ['from' => 'x'], 403, 'No accessible administration', ['currentUserId', 'accessible']],
			'one-sided from' => ['alice', ['adm-1'], ['from' => '2026-01-01'], 400, 'from and to must be provided together', ['currentUserId', 'accessible']],
			'one-sided to' => ['alice', ['adm-1'], ['to' => ' 2026-01-01 '], 400, 'from and to must be provided together', ['currentUserId', 'accessible']],
			'bad from and to' => ['alice', ['adm-1'], ['from' => 'x', 'to' => 'y'], 400, 'from must be an ISO-8601 date', ['currentUserId', 'accessible']],
			'bad to' => ['alice', ['adm-1'], ['from' => '2026-01-01', 'to' => 'y'], 400, 'to must be an ISO-8601 date', ['currentUserId', 'accessible']],
		];
	}//end refusals()

	/**
	 * Each refusal answers its exact body and stops before the service.
	 *
	 * @dataProvider refusals
	 *
	 * @param string|null          $user       The current user.
	 * @param list<string>         $accessible Accessible administrations.
	 * @param array<string, mixed> $params     Request parameters.
	 * @param int                  $status     Expected status.
	 * @param string               $error      Expected error text.
	 * @param list<string>         $calls      Expected calls.
	 *
	 * @return void
	 */
	public function testRefusals(?string $user, array $accessible, array $params, int $status, string $error, array $calls): void {
		$this->user = $user;
		$this->accessible = $accessible;
		$this->params = $params;
		foreach (['series', 'summary'] as $endpoint) {
			$this->calls = [];
			$response = $this->controller()->$endpoint();
			$this->assertSame($status, $response->getStatus());
			$this->assertSame(['error' => $error], $response->getData());
			$this->assertSame($calls, $this->calls);
		}
	}//end testRefusals()

	/**
	 * A valid range is trimmed and passed on; an empty one becomes nulls.
	 *
	 * @return void
	 */
	public function testAllowedRanges(): void {
		$this->params = ['from' => ' 2026-01-01T10:00 ', 'to' => '2026-03-31'];
		$response = $this->controller()->series();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['endpoint' => 'series'], $response->getData());
		$this->assertSame(['currentUserId', 'accessible', "series:'2026-01-01T10:00':'2026-03-31'"], $this->calls);

		$this->params = [];
		$this->calls = [];
		$response = $this->controller()->summary();
		$this->assertSame(['endpoint' => 'summary'], $response->getData());
		$this->assertSame(['currentUserId', 'accessible', 'summary:NULL:NULL'], $this->calls);
	}//end testAllowedRanges()

	/**
	 * A failure logs the raw bounds and answers a generic 500.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$this->failure = new \LogicException('boom');
		$this->params = ['from' => '2026-01-01', 'to' => '2026-03-31'];
		foreach (['series', 'summary'] as $endpoint) {
			$this->logged = [];
			$response = $this->controller()->$endpoint();
			$this->assertSame(500, $response->getStatus());
			$this->assertSame(['error' => 'Failed to compute financial '.$endpoint], $response->getData());
			$this->assertSame(
				[['FinancialDashboardController: failed to compute '.$endpoint, ['from' => '2026-01-01', 'to' => '2026-03-31', 'exception' => 'boom']]],
				$this->logged
			);
		}
	}//end testFailures()

	/**
	 * Cash position refusals and the active-administration fallback.
	 *
	 * @return void
	 */
	public function testCashPosition(): void {
		$this->user = null;
		$response = $this->controller()->cashPosition();
		$this->assertSame([401, ['error' => 'Not authenticated']], [$response->getStatus(), $response->getData()]);

		$this->user = 'alice';
		$this->calls = [];
		$this->built = ['activeAdministrationId' => '  '];
		$response = $this->controller()->cashPosition();
		$this->assertSame([400, ['error' => 'No administration selected']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['currentUserId', 'buildContext'], $this->calls);

		$this->calls = [];
		$this->built = ['activeAdministrationId' => ' adm-2 '];
		$this->canAccess = false;
		$response = $this->controller()->cashPosition();
		$this->assertSame([404, ['error' => 'Not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['currentUserId', 'buildContext', 'canAccess:adm-2'], $this->calls);

		$this->calls = [];
		$this->canAccess = true;
		$this->params = ['administrationId' => ' adm-3 '];
		$response = $this->controller()->cashPosition();
		$this->assertSame([200, ['total' => 5]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['currentUserId', 'canAccess:adm-3', 'cashPosition:adm-3'], $this->calls);

		$this->failure = new \RuntimeException('down');
		$response = $this->controller()->cashPosition();
		$this->assertSame([500, ['error' => 'Cash position unavailable']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['FinancialDashboardController: cash position failed', ['exception' => 'down']]], $this->logged);
	}//end testCashPosition()
}//end class
