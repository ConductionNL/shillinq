<?php

/**
 * Golden tests for ManualMatchController::match: check order, the line-administration guard, calls and answers.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ManualMatchController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of the match action, allowed and refused, and every call behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ManualMatchControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call match once.
	 *
	 * @param string              $lineId The path id.
	 * @param array<string,mixed> $setup  user (bool), line (array|Throwable), member (bool), params, fail (Throwable).
	 *
	 * @return JSONResponse
	 */
	private function match(string $lineId, array $setup=[]): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['params'][$key] ?? null);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturnCallback(
				function (): string {
					$this->calls[] = ['getUID'];
					return 'u1';
				}
			);
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
		$service = $this->createMock(ManualMatchService::class);
		$service->method('findLine')->willReturnCallback(
			function (string $id) use ($setup): array {
				$this->calls[] = ['findLine', $id];
				$line = ($setup['line'] ?? ['id' => $id, 'administrationId' => 'adm-1']);
				if ($line instanceof \Throwable) {
					throw $line;
				}

				return $line;
			}
		);
		$service->method('bookToLedger')->willReturnCallback(
			function (array $line, array $ledger, string $actor) use ($fail): array {
				$this->calls[] = ['bookToLedger', $line, $ledger, $actor];
				if ($fail !== null) {
					throw $fail;
				}

				return ['booked' => true];
			}
		);
		$service->method('matchInvoices')->willReturnCallback(
			function (array $line, array $targetIds, string $actor) use ($fail): array {
				$this->calls[] = ['matchInvoices', $line, $targetIds, $actor];
				if ($fail !== null) {
					throw $fail;
				}

				return ['matched' => $targetIds];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			function (string $text, array $parameters=[]): string {
				$this->calls[] = ['t', $text, $parameters];
				return 'T:' . $text;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return (new ManualMatchController($request, $service, $context, $session, $l10n, $logger))->match($lineId);
	}//end match()

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
	 * 401 first; an unknown line and a foreign line are the same masked 404, before any parameter is read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$actual = self::answer($this->match(lineId: 'l1', setup: ['user' => false]));
		self::assertSame(expected: [401, ['message' => 'T:Not logged in']], actual: $actual);
		self::assertSame(expected: [['t', 'Not logged in', []]], actual: $this->calls);
		$notFound = [404, ['message' => 'T:Bank line not found']];
		$this->calls = [];
		self::assertSame(expected: $notFound, actual: self::answer($this->match(lineId: 'l1', setup: ['line' => new OutOfBoundsException('none')])));
		self::assertSame(expected: [['findLine', 'l1'], ['t', 'Bank line not found', []]], actual: $this->calls);
		$this->calls = [];
		self::assertSame(expected: $notFound, actual: self::answer($this->match(lineId: 'l1', setup: ['line' => ['id' => 'l1'], 'member' => false])));
		self::assertSame(expected: [['findLine', 'l1'], ['canAccess', ''], ['t', 'Bank line not found', []]], actual: $this->calls);
	}//end testRefusals()

	/**
	 * A ledger account wins over targets; without either the answer is a 422; targets are matched.
	 *
	 * @return void
	 */
	public function testAllowedPaths(): void {
		$line = ['id' => 'l1', 'administrationId' => 'adm-1'];
		$actual = self::answer($this->match(lineId: 'l1', setup: ['params' => ['targets' => ['i1'], 'ledgerAccount' => ['gl' => '4000']]]));
		self::assertSame(expected: [200, ['booked' => true]], actual: $actual);
		self::assertSame(
			expected: [[
				'findLine',
				'l1'], ['canAccess',
				'adm-1'], ['getParam',
				'targets'], ['getParam',
				'ledgerAccount'], ['getUID'], ['bookToLedger',
				$line,
				['gl' => '4000'],
				'u1',
			]],
			actual: $this->calls
		);
		$this->calls = [];
		$actual = self::answer($this->match(lineId: 'l1', setup: ['params' => ['ledgerAccount' => [], 'targets' => 'x']]));
		self::assertSame(expected: [422, ['message' => 'T:Select at least one invoice.']], actual: $actual);
		$actual = array_filter($this->calls, fn (array $c): bool => in_array($c[0], ['getUID', 'bookToLedger', 'matchInvoices'], true));
		self::assertSame(expected: [], actual: $actual);
		$this->calls = [];
		$actual = self::answer($this->match(lineId: 'l1', setup: ['params' => ['targets' => ['i1', 'i2'], 'ledgerAccount' => 'x']]));
		self::assertSame(expected: [200, ['matched' => ['i1', 'i2']]], actual: $actual);
		self::assertSame(expected: ['matchInvoices', $line, ['i1', 'i2'], 'u1'], actual: end($this->calls));
	}//end testAllowedPaths()

	/**
	 * A refusal from the service is a translated 422; anything else is logged and a 500.
	 *
	 * @return void
	 */
	public function testServiceFailures(): void {
		$refused = new ManualMatchRefusedException(template: 'Line %1$s is closed', parameters: ['l1']);
		$actual = self::answer($this->match(lineId: 'l1', setup: ['params' => ['targets' => []], 'fail' => $refused]));
		self::assertSame(expected: [422, ['message' => 'T:Line %1$s is closed']], actual: $actual);
		self::assertSame(expected: ['t', 'Line %1$s is closed', ['l1']], actual: end($this->calls));
		$this->calls = [];
		$actual = self::answer($this->match(lineId: 'l1', setup: ['params' => ['targets' => []], 'fail' => new \RuntimeException('db')]));
		self::assertSame(expected: [500, ['message' => 'T:The bank line could not be matched.']], actual: $actual);
		self::assertSame(
			expected: [[
				'error',
				'ManualMatchController: match failed',
				['lineId' => 'l1', 'exception' => 'db']], ['t',
				'The bank line could not be matched.',
				[],
			]],
			actual: array_slice($this->calls, -2)
		);
		$this->expectException(\LogicException::class);
		$this->match(lineId: 'l1', setup: ['line' => new \LogicException('bug')]);
	}//end testServiceFailures()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
