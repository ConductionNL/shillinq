<?php

/**
 * Golden tests for GRIRReconciliationController::saldo answers and log lines.
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
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\GRIRReconciliationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every saldo answer, the checks made before the service and the two
 * failure log lines before the action is split into named steps.
 */
final class GRIRReconciliationGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params  Request params.
	 * @param mixed                $outcome The service answer, or a Throwable it throws.
	 * @param bool                 $access  canAccess() answer.
	 *
	 * @return GRIRReconciliationController
	 */
	private function controller(array $params, mixed $outcome, bool $access = true): GRIRReconciliationController {
		$service = $this->createMock(GRIRClearingService::class);
		$service->method('reconcileGRIRSaldoForPeriod')->willReturnCallback(
			function (string $administrationId, string $periodId) use ($outcome): array {
				$this->calls[] = 'saldo:' . $administrationId . ':' . $periodId;
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($access): bool {
				$this->calls[] = 'canAccess:' . $administrationId;
				return $access;
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(isset($params['anonymous']) === true ? null : $this->createMock(IUser::class));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'error:' . $message . json_encode($context);
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);

		return new GRIRReconciliationController($request, $service, $context, $session, $logger, $l10n);
	}//end controller()

	/**
	 * Call saldo and return status and body.
	 *
	 * @param GRIRReconciliationController $controller The controller.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private static function answer(GRIRReconciliationController $controller): array {
		$response = $controller->saldo();
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * The refusals before the service, with the checks made.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame([401, ['error' => 'Not logged in']], self::answer($this->controller(['anonymous' => 1], [])));
		$this->assertSame([400, ['error' => 'administrationId is required']], self::answer($this->controller(['administrationId' => 'a b'], [])));
		$this->assertSame([], $this->calls);
		$this->assertSame([404, ['error' => 'Administration not found']], self::answer($this->controller(['administrationId' => 'adm-1'], [], false)));
		$this->assertSame([400, ['error' => 'periodId is required']], self::answer($this->controller(['administrationId' => 'adm-1'], [])));
		$this->assertSame(['canAccess:adm-1', 'canAccess:adm-1'], $this->calls);
	}//end testRefusals()

	/**
	 * The service answer, a not-found refusal, an invalid request and a failure.
	 *
	 * @return void
	 */
	public function testOutcomes(): void {
		$params = ['administrationId' => ' adm-1 ', 'periodId' => ' 2026-Q1 '];
		$this->assertSame([200, ['saldo' => 5]], self::answer($this->controller($params, ['saldo' => 5])));
		$this->assertSame(
			[404, ['error' => 'Administration not found']],
			self::answer($this->controller($params, new \RuntimeException('Period not found')))
		);
		$this->assertSame(
			[400, ['message' => 'nl:Invalid request for the GR/IR saldo.', 'error' => 'grir-saldo-invalid-request']],
			self::answer($this->controller($params, new \RuntimeException('bad period')))
		);
		$this->assertSame(
			[500, ['error' => 'Could not compute the GR/IR saldo']],
			self::answer($this->controller($params, new \LogicException('db')))
		);

		$context = static fn (string $e): string => json_encode(['administrationId' => 'adm-1', 'periodId' => '2026-Q1', 'exception' => $e]);
		$this->assertSame(
			[
				'canAccess:adm-1',
				'saldo:adm-1:2026-Q1',
				'canAccess:adm-1',
				'saldo:adm-1:2026-Q1',
				'canAccess:adm-1',
				'saldo:adm-1:2026-Q1',
				'error:GRIRReconciliationController: invalid request for the GR/IR saldo' . $context('bad period'),
				'canAccess:adm-1',
				'saldo:adm-1:2026-Q1',
				'error:GRIRReconciliationController: failed to compute the GR/IR saldo' . $context('db'),
			],
			$this->calls
		);
	}//end testOutcomes()
}//end class
