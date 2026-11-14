<?php

/**
 * Golden tests for TaxReportController: check order, bounds, service arguments, answers and log lines.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-36
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TaxReportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxReportService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins both statements, allowed and refused, and what reaches the service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class TaxReportControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), admin, member (bool), fail (Throwable).
	 *
	 * @return TaxReportController
	 */
	private function controller(array $setup=[]): TaxReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['admin'] ?? 'adm');
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail    = ($setup['fail'] ?? null);
		$service = $this->createMock(TaxReportService::class);
		$service->method('computeQuarter')->willReturnCallback(
			function (string $administrationId, int $fiscalYear, int $quarter) use ($fail): array {
				$this->calls[] = ['computeQuarter', $administrationId, $fiscalYear, $quarter];
				if ($fail !== null) {
					throw $fail;
				}

				return ['q' => $quarter, 'extra' => [1]];
			}
		);
		$service->method('computeAnnual')->willReturnCallback(
			function (string $administrationId, int $fiscalYear) use ($fail): array {
				$this->calls[] = ['computeAnnual', $administrationId, $fiscalYear];
				if ($fail !== null) {
					throw $fail;
				}

				return ['y' => $fiscalYear];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new TaxReportController($request, $service, $session, $context, $logger);
	}//end controller()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Both actions refuse in order: 401, the administration (400, 400, masked 404), then the period.
	 *
	 * @return void
	 */
	public function testRefusalOrder(): void {
		foreach (['quarter', 'annual'] as $action) {
			$this->calls = [];
			$call = fn (TaxReportController $controller): JSONResponse => $controller->quarter('0', '9');
			if ($action === 'annual') {
				$call = fn (TaxReportController $controller): JSONResponse => $controller->annual('0');
			}

			$actual = self::answer($call($this->controller(setup: ['user' => false])));
			self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: $actual);
			$actual = self::answer($call($this->controller(setup: ['admin' => '  '])));
			self::assertSame(expected: [400, ['error' => 'administration_id is required']], actual: $actual);
			$malformed = [400, ['error' => 'administration_id must be a valid administration identifier']];
			$actual = self::answer($call($this->controller(setup: ['admin' => str_repeat('a', 65)])));
			self::assertSame(expected: $malformed, actual: $actual);
			self::assertSame(expected: [], actual: array_filter($this->calls, fn (array $c): bool => $c[0] !== 'getParam'));
			self::assertSame(expected: ['getParam', 'administration_id', ''], actual: $this->calls[0]);
			$this->calls = [];
			$actual = self::answer($call($this->controller(setup: ['admin' => ' adm-x ', 'member' => false])));
			self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: $actual);
			self::assertSame(expected: [['getParam', 'administration_id', ''], ['canAccess', 'adm-x']], actual: $this->calls);
		}
	}//end testRefusalOrder()

	/**
	 * Quarter: year bounds 1900–2200 before quarter bounds 1–4; values are cast from the path.
	 *
	 * @return void
	 */
	public function testQuarterBoundsAndCall(): void {
		$badYear    = [400, ['error' => 'year must be a valid fiscal year']];
		$badQuarter = [400, ['error' => 'quarter must be between 1 and 4']];
		self::assertSame(expected: $badYear, actual: self::answer($this->controller()->quarter('1899', '0')));
		self::assertSame(expected: $badYear, actual: self::answer($this->controller()->quarter('2201', '1')));
		self::assertSame(expected: $badQuarter, actual: self::answer($this->controller()->quarter('1900', '0')));
		self::assertSame(expected: $badQuarter, actual: self::answer($this->controller()->quarter('2200', '5')));
		self::assertSame(expected: [], actual: array_filter($this->calls, fn (array $c): bool => $c[0] === 'computeQuarter'));
		self::assertSame(expected: [200, ['q' => 3, 'extra' => [1]]], actual: self::answer($this->controller()->quarter('2026x', '3rd')));
		self::assertSame(expected: ['computeQuarter', 'adm', 2026, 3], actual: end($this->calls));
		$actual = self::answer($this->controller(setup: ['fail' => new \RuntimeException('gl')])->quarter('2026', '4'));
		self::assertSame(expected: [500, ['error' => 'Failed to compute tax statement']], actual: $actual);
		self::assertSame(
			expected: [
				'error',
				'TaxReportController: failed to compute quarterly tax statement',
				['administrationId' => 'adm', 'fiscalYear' => 2026, 'quarter' => 4, 'exception' => 'gl'],
			],
			actual: end($this->calls)
		);
	}//end testQuarterBoundsAndCall()

	/**
	 * Annual: year bounds, the call and the failure line.
	 *
	 * @return void
	 */
	public function testAnnual(): void {
		self::assertSame(expected: [400, ['error' => 'year must be a valid fiscal year']], actual: self::answer($this->controller()->annual('abc')));
		self::assertSame(expected: [200, ['y' => 1900]], actual: self::answer($this->controller()->annual('1900')));
		self::assertSame(expected: ['computeAnnual', 'adm', 1900], actual: end($this->calls));
		$actual = self::answer($this->controller(setup: ['fail' => new \LogicException('x')])->annual('2200'));
		self::assertSame(expected: [500, ['error' => 'Failed to compute tax summary']], actual: $actual);
		self::assertSame(
			expected: [
				'error',
				'TaxReportController: failed to compute annual tax summary',
				['administrationId' => 'adm', 'fiscalYear' => 2200, 'exception' => 'x'],
			],
			actual: end($this->calls)
		);
	}//end testAnnual()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
