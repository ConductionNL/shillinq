<?php

/**
 * Golden tests for BillingIntakeController::timeIntake: body sources, scope, answers and log lines.
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
 * @spec openspec/specs/time-expense-invoice-intake/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BillingIntakeController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Serves a fixed body for `php://input` while registered.
 */
final class BillingIntakePhpInput {

	/**
	 * The body served.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * Stream context, assigned by the stream layer.
	 *
	 * @var resource|null
	 */
	public $context = null;

	/**
	 * Read cursor.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Open.
	 *
	 * @param string      $path       Path.
	 * @param string      $mode       Mode.
	 * @param int         $options    Options.
	 * @param string|null $openedPath Opened path.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;
	}//end stream_open()

	/**
	 * Read.
	 *
	 * @param int $count Bytes.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * End of body.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * Stat.
	 *
	 * @return array<int|string,int>
	 */
	public function stream_stat(): array {
		return [];
	}//end stream_stat()
}//end class

/**
 * Pins what the action reads, what reaches the service and every answer.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BillingIntakeControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call timeIntake once with $body as php://input.
	 *
	 * @param string                    $body    Raw request body.
	 * @param mixed                     $params  What getParams() answers.
	 * @param array<string,mixed>|\Throwable $outcome Service answer, or what it throws.
	 * @param mixed                     $context What buildContext() answers, or throws.
	 *
	 * @return JSONResponse
	 */
	private function intake(
		string $body,
		mixed $params=[],
		array|\Throwable $outcome=['ok' => 1],
		mixed $context=['activeAdministrationId' => 'adm-1']
	): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(
			function () use ($params): mixed {
				$this->calls[] = ['getParams'];
				return $params;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(
			function (): string {
				$this->calls[] = ['getUID'];
				return 'alice';
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$admin = $this->createMock(AdministrationContextService::class);
		$admin->method('buildContext')->willReturnCallback(
			function () use ($context): array {
				$this->calls[] = ['buildContext'];
				if ($context instanceof \Throwable) {
					throw $context;
				}

				return $context;
			}
		);
		$service = $this->createMock(TimeIntakeService::class);
		$service->method('ingest')->willReturnCallback(
			function (string $administrationId, string $personId, array $body) use ($outcome): array {
				$this->calls[] = ['ingest', $administrationId, $personId, $body];
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			function (string $text): string {
				$this->calls[] = ['t', $text];
				return 'T:' . $text;
			}
		);
		$controller = new BillingIntakeController($request, $service, $admin, $session, $logger, $l10n);

		BillingIntakePhpInput::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', BillingIntakePhpInput::class);
		try {
			return $controller->timeIntake();
		} finally {
			stream_wrapper_restore('php');
		}
	}//end intake()

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
	 * A JSON object body wins; scope, person and body reach the service in order.
	 *
	 * @return void
	 */
	public function testJsonBodyAndCallOrder(): void {
		self::assertSame(expected: [200, ['ok' => 1]], actual: self::answer($this->intake(body: '{"entries":[1]}', params: ['ignored' => true])));
		self::assertSame(expected: [['buildContext'], ['getUID'], ['ingest', 'adm-1', 'alice', ['entries' => [1]]]], actual: $this->calls);
	}//end testJsonBodyAndCallOrder()

	/**
	 * Empty, scalar-JSON or malformed bodies fall back to the request params.
	 *
	 * @return void
	 */
	public function testBodyFallbacks(): void {
		$this->intake(body: '', params: ['a' => 1]);
		$this->intake(body: '"text"', params: ['b' => 2]);
		$this->intake(body: '{bad', params: []);
		$ingested = array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'ingest'));
		self::assertSame(expected: [['a' => 1], ['b' => 2], []], actual: array_column($ingested, 3));
		self::assertCount(expectedCount: 3, haystack: array_filter($this->calls, fn (array $c): bool => $c[0] === 'getParams'));
	}//end testBodyFallbacks()

	/**
	 * A blank or failing scope resolution falls back to 'default'.
	 *
	 * @return void
	 */
	public function testScopeFallback(): void {
		$this->intake(body: '{}', context: ['activeAdministrationId' => '']);
		$this->intake(body: '{}', context: new \RuntimeException('no ctx'));
		$ingested = array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'ingest'));
		self::assertSame(expected: ['default', 'default'], actual: array_column($ingested, 1));
		self::assertSame(expected: [], actual: array_filter($this->calls, fn (array $c): bool => $c[0] === 'error'));
	}//end testScopeFallback()

	/**
	 * Each failure class logs the same line, then answers its own status and translated body.
	 *
	 * @return void
	 */
	public function testFailureAnswers(): void {
		$cases = [
			[new \InvalidArgumentException('bad'), 400, 'Invalid time-intake batch', 'billing-time-intake-invalid-input'],
			[new \RuntimeException('Conflict: seen'), 409, 'This batch has already been processed', 'billing-time-intake-conflict'],
			[new \UnexpectedValueException('conflict: lower'), 422, 'Time-intake batch could not be processed', 'billing-time-intake-unprocessable'],
			[new \LogicException('x'), 500, 'Unable to process time-intake batch', 'billing-time-intake-failed'],
			[new \TypeError('y'), 500, 'Unable to process time-intake batch', 'billing-time-intake-failed'],
		];
		foreach ($cases as [$error, $status, $text, $code]) {
			$this->calls = [];
			$response = $this->intake(body: '{}', outcome: $error);
			self::assertSame(expected: [$status, ['message' => 'T:' . $text, 'error' => $code]], actual: self::answer($response));
			$logged = ['error', 'BillingIntakeController.timeIntake failed', ['exception' => $error]];
			self::assertSame(expected: [$logged, ['t', $text]], actual: array_slice($this->calls, -2));
		}
	}//end testFailureAnswers()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
