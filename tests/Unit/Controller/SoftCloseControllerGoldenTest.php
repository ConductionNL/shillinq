<?php

/**
 * Golden tests for SoftCloseController: check order, parameters, service arguments, answers and log lines.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Controller\SoftCloseController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FluxService;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the three actions, allowed and refused, and everything they pass on.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SoftCloseControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), member (bool), params, report, fail (Throwable).
	 *
	 * @return SoftCloseController
	 */
	private function controller(array $setup=[]): SoftCloseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['params'][$key] ?? $default);
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
		$fail     = ($setup['fail'] ?? null);
		$executor = $this->createMock(SoftCloseExecutor::class);
		$executor->method('execute')->willReturnCallback(
			function (string $administrationId, string $periodId, DateTimeImmutable $asOf) use ($setup, $fail): array {
				$this->calls[] = ['execute', $administrationId, $periodId, $asOf::class];
				if ($fail !== null) {
					throw $fail;
				}

				return ($setup['report'] ?? ['status' => 'ok']);
			}
		);
		$flux = $this->createMock(FluxService::class);
		$flux->method('run')->willReturnCallback(
			function (array $inputs) use ($fail): array {
				$inputs['runTimestamp'] = $inputs['runTimestamp']::class;
				$this->calls[] = ['run', $inputs];
				if ($fail !== null) {
					throw $fail;
				}

				return ['summary' => 1];
			}
		);
		$flux->method('buildNarrative')->willReturnCallback(
			function (array $items, string $periodId) use ($fail): array {
				$this->calls[] = ['buildNarrative', $items, $periodId];
				if ($fail !== null) {
					throw $fail;
				}

				return ['n' => $periodId];
			}
		);
		$flux->method('renderNarrativeMarkdown')->willReturnCallback(fn (array $narrative): string => 'md:' . json_encode($narrative));
		$flux->method('renderNarrativePdfBody')->willReturnCallback(fn (array $narrative): string => 'pdf:' . json_encode($narrative));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new SoftCloseController($request, $executor, $flux, $context, $session, $logger);
	}//end controller()

	/**
	 * Status, body and content type.
	 *
	 * @param Response $response The response.
	 *
	 * @return array{0:int,1:mixed,2:string|null}
	 */
	private static function answer(Response $response): array {
		return [$response->getStatus(), $response->getData(), ($response->getHeaders()['Content-Type'] ?? null)];
	}//end answer()

	/**
	 * Execute-now refuses in order: 401, 400 id, masked 404, 400 period; then runs with the trimmed ids.
	 *
	 * @return void
	 */
	public function testExecuteNow(): void {
		$json = 'application/json; charset=utf-8';
		$actual = self::answer($this->controller(setup: ['user' => false])->executeNow('a'));
		self::assertSame(expected: [401, ['error' => 'Authentication required'], $json], actual: $actual);
		$actual = self::answer($this->controller()->executeNow(' a/b '));
		self::assertSame(expected: [400, ['error' => 'administration_id must be a valid identifier'], $json], actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$actual = self::answer($this->controller(setup: ['member' => false])->executeNow(' adm '));
		self::assertSame(expected: [404, ['error' => 'Administration not found'], $json], actual: $actual);
		self::assertSame(expected: [['canAccess', 'adm']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->controller(setup: ['params' => ['periodId' => '2026-1']])->executeNow('adm'));
		self::assertSame(expected: [400, ['error' => 'period_id must match yyyy-mm'], $json], actual: $actual);
		self::assertSame(expected: [['canAccess', 'adm'], ['getParam', 'periodId']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->controller(setup: ['params' => ['periodId' => ' 2026-02 ']])->executeNow('adm'));
		self::assertSame(expected: [200, ['data' => ['status' => 'ok']], $json], actual: $actual);
		self::assertSame(expected: ['execute', 'adm', '2026-02', DateTimeImmutable::class], actual: end($this->calls));
		$this->controller()->executeNow('adm');
		self::assertSame(expected: (new DateTimeImmutable())->format('Y-m'), actual: end($this->calls)[2]);
		$actual = self::answer($this->controller(setup: ['report' => ['status' => 'failed', 'x' => 1]])->executeNow('adm'));
		self::assertSame(expected: [500, ['data' => ['status' => 'failed', 'x' => 1]], $json], actual: $actual);
		$actual = self::answer($this->controller(setup: ['fail' => new \RuntimeException('x1')])->executeNow('adm'));
		self::assertSame(expected: [500, ['error' => 'An unexpected error occurred'], $json], actual: $actual);
		self::assertSame(expected: ['error', 'SoftCloseController: executeNow failed', ['exception' => 'x1']], actual: end($this->calls));
	}//end testExecuteNow()

	/**
	 * Execute-flux refuses in order and passes the inputs in their fixed order with defaults.
	 *
	 * @return void
	 */
	public function testExecuteFlux(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => false])->executeFlux()->getStatus());
		$actual = array_slice(self::answer($this->controller()->executeFlux()), 0, 2);
		self::assertSame(expected: [400, ['error' => 'administration_id is required']], actual: $actual);
		self::assertSame(expected: [['getParam', 'administrationId']], actual: $this->calls);
		$this->calls = [];
		$actual = $this->controller(setup: ['member' => false, 'params' => ['administrationId' => ' adm ']])->executeFlux()->getStatus();
		self::assertSame(expected: 404, actual: $actual);
		self::assertSame(expected: [['getParam', 'administrationId'], ['canAccess', 'adm']], actual: $this->calls);
		$actual = array_slice(self::answer($this->controller(setup: ['params' => ['administrationId' => 'adm']])->executeFlux()), 0, 2);
		self::assertSame(expected: [400, ['error' => 'period_id must match yyyy-mm']], actual: $actual);
		$this->calls = [];
		$params = ['administrationId' => 'adm', 'periodId' => '2026-03', 'accounts' => 'x', 'materialityPolicy' => ['p' => 1]];
		$actual = array_slice(self::answer($this->controller(setup: ['params' => $params])->executeFlux()), 0, 2);
		self::assertSame(expected: [200, ['data' => ['summary' => 1]]], actual: $actual);
		self::assertSame(
			expected: [
				['getParam', 'administrationId'],
				['canAccess', 'adm'],
				['getParam', 'periodId'],
				['getParam', 'accounts'],
				['getParam', 'materialityPolicy'],
				['getParam', 'scope'],
				['getParam', 'comparisonBasis'],
				[
					'run',
					[
						'administrationId' => 'adm',
						'periodId' => '2026-03',
						'scope' => 'administration',
						'comparisonBasis' => 'budget',
						'accounts' => ['x'],
						'materialityPolicy' => ['p' => 1],
						'runTimestamp' => DateTimeImmutable::class,
					],
				],
			],
			actual: $this->calls
		);
		$actual = $this->controller(setup: ['params' => $params, 'fail' => new \RuntimeException('x2')])->executeFlux()->getStatus();
		self::assertSame(expected: 500, actual: $actual);
		self::assertSame(expected: ['error', 'SoftCloseController: executeFlux failed', ['exception' => 'x2']], actual: end($this->calls));
	}//end testExecuteFlux()

	/**
	 * The narrative is built from the posted items, then rendered by format; an unknown format is a 400 after building.
	 *
	 * @return void
	 */
	public function testNarrative(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => false])->narrative('r1')->getStatus());
		$actual = array_slice(self::answer($this->controller()->narrative(' ')), 0, 2);
		self::assertSame(expected: [400, ['error' => 'flux_run_id must be a valid identifier']], actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$params = ['format' => 'MarkDown', 'items' => 'i', 'periodId' => ' 2026-04 '];
		$actual = self::answer($this->controller(setup: ['params' => $params])->narrative('r1'));
		self::assertSame(expected: [200, 'md:{"n":"2026-04"}', 'text/markdown; charset=utf-8'], actual: $actual);
		self::assertSame(
			expected: [['getParam', 'format'], ['getParam', 'items'], ['getParam', 'periodId'], ['buildNarrative', ['i'], '2026-04']],
			actual: $this->calls
		);
		$actual = self::answer($this->controller(setup: ['params' => ['format' => 'pdf']])->narrative('r1'));
		self::assertSame(expected: [200, 'pdf:{"n":""}', 'application/pdf'], actual: $actual);
		self::assertSame(expected: [200, ['data' => ['n' => '']]], actual: array_slice(self::answer($this->controller()->narrative('r1')), 0, 2));
		$this->calls = [];
		$actual = array_slice(self::answer($this->controller(setup: ['params' => ['format' => 'csv']])->narrative('r1')), 0, 2);
		self::assertSame(expected: [400, ['error' => 'format must be json, markdown or pdf']], actual: $actual);
		self::assertSame(expected: 'buildNarrative', actual: end($this->calls)[0]);
		self::assertSame(expected: 500, actual: $this->controller(setup: ['fail' => new \RuntimeException('x3')])->narrative('r1')->getStatus());
		self::assertSame(expected: ['error', 'SoftCloseController: narrative failed', ['exception' => 'x3']], actual: end($this->calls));
	}//end testNarrative()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
