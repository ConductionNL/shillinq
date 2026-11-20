<?php

/**
 * shillinq.period_close.* refuses exactly where its REST twin does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\Shillinq\Controller\PeriodCloseActor;
use OCA\Shillinq\Controller\PeriodCloseController;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseAssistantServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Exception\PeriodCloseException;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpPeriodCloseTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The same situation through the tool and through the controller ends the same way.
 */
#[CoversClass(McpPeriodCloseTools::class)]
#[UsesClass(McpAdministrationGate::class)]
#[UsesClass(McpResult::class)]
#[UsesClass(PeriodCloseController::class)]
#[UsesClass(PeriodCloseActor::class)]
#[UsesClass(PeriodCloseException::class)]
class McpPeriodCloseParityTest extends TestCase {

	private PeriodCloseServiceInterface&MockObject $periods;

	private PeriodCloseAssistantServiceInterface&MockObject $assistant;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->periods   = $this->createMock(PeriodCloseServiceInterface::class);
		$this->assistant = $this->createMock(PeriodCloseAssistantServiceInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function session(bool $signedIn): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('agent-alice');
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	private function context(): AdministrationContextService {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		return $context;
	}//end context()

	private function tools(bool $signedIn): McpPeriodCloseTools {
		return new McpPeriodCloseTools(
			new McpAdministrationGate($this->session(signedIn: $signedIn), $this->context()),
			$this->periods,
			$this->assistant,
			$this->logger
		);
	}//end tools()

	private function controller(bool $signedIn, string $administrationId, string $closeReason = ''): PeriodCloseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => match ($key) {
				'administration_id' => $administrationId,
				'closeReason' => $closeReason,
				default => $default,
			}
		);

		return new PeriodCloseController($request, $this->periods, $this->assistant, $this->context(), $this->session(signedIn: $signedIn), $this->logger);
	}//end controller()

	/**
	 * The tool's outcome code for an HTTP status of its twin.
	 */
	private static function codeFor(int $status): string {
		return match ($status) {
			200 => 'ok',
			401 => 'unauthorised',
			403 => 'forbidden',
			404 => 'not_found',
			409 => 'conflict',
			422 => 'invalid_arguments',
			default => 'failed',
		};
	}//end codeFor()

	private static function outcome(array $result): string {
		if (McpResult::isError(result: $result) === true) {
			return (string)$result['error'];
		}

		return 'ok';
	}//end outcome()

	/**
	 * Situations: [signed in, administration, what the service does].
	 *
	 * @return array<string, array{0: bool, 1: string, 2: string|null}>
	 */
	public static function situations(): array {
		return [
			'anonymous' => [false, 'adm-1', null],
			'unreachable administration' => [true, 'adm-other', null],
			'transition allowed' => [true, 'adm-1', null],
			'service forbids' => [true, 'adm-1', PeriodCloseServiceInterface::ERR_FORBIDDEN],
			'service finds no period' => [true, 'adm-1', PeriodCloseServiceInterface::ERR_NOT_FOUND],
			'service refuses the input' => [true, 'adm-1', PeriodCloseServiceInterface::ERR_VALIDATION],
			'wrong state' => [true, 'adm-1', 'invalid-transition'],
			'unexpected failure' => [true, 'adm-1', 'boom'],
		];
	}//end situations()

	/**
	 * Make every transition of the service behave as the situation says.
	 */
	private function serviceDoes(?string $status): void {
		$answer = static function () use ($status): array {
			if ($status === 'boom') {
				throw new RuntimeException('database gone');
			}

			if ($status !== null) {
				throw new PeriodCloseException('refused: ' . $status, $status);
			}

			return ['periodId' => '2026-10', 'status' => 'closed'];
		};
		foreach (['startClose', 'closePeriod', 'reopenPeriod'] as $method) {
			$this->periods->method($method)->willReturnCallback($answer);
		}
	}//end serviceDoes()

	#[DataProvider('situations')]
	public function testEveryTransitionEndsLikeItsRestTwin(bool $signedIn, string $administrationId, ?string $status): void {
		$this->serviceDoes(status: $status);
		$args  = ['administrationId' => $administrationId, 'periodId' => '2026-10', 'closeReason' => 'Correctie'];
		$tools = $this->tools(signedIn: $signedIn);
		$rest  = $this->controller(signedIn: $signedIn, administrationId: $administrationId, closeReason: 'Correctie');

		foreach (['start' => 'startClose', 'close' => 'close', 'reopen' => 'reopen'] as $tool => $endpoint) {
			$expected = self::codeFor(status: $rest->{$endpoint}('2026-10')->getStatus());
			$this->assertSame($expected, self::outcome(result: $tools->{$tool}($args)), $tool);
		}
	}

	public function testATransitionRunsAsTheSignedInUserAndReturnsThePeriod(): void {
		$this->periods->expects($this->once())->method('reopenPeriod')
			->with('2026-10', 'adm-1', 'Correctie op btw', 'agent-alice')
			->willReturn(['periodId' => '2026-10', 'status' => 'open']);

		$result = $this->tools(signedIn: true)->reopen(['administrationId' => 'adm-1', 'periodId' => '2026-10', 'closeReason' => '  Correctie op btw ']);

		$this->assertSame(['period' => ['periodId' => '2026-10', 'status' => 'open']], $result);
	}

	public function testAReopenWithoutAReasonPassesAnEmptyReasonLikeTheController(): void {
		$this->periods->expects($this->once())->method('reopenPeriod')->with('2026-10', 'adm-1', '', 'agent-alice')->willReturn([]);

		$this->tools(signedIn: true)->reopen(['administrationId' => 'adm-1', 'periodId' => '2026-10', 'closeReason' => null]);
	}

	public function testARefusedCallerNeverReachesTheService(): void {
		$this->periods->expects($this->never())->method('closePeriod');
		$this->periods->expects($this->never())->method('getPeriodForClose');

		$this->assertSame('not_found', $this->tools(signedIn: true)->close(['administrationId' => 'adm-other', 'periodId' => '2026-10'])['error']);
		$this->assertSame('unauthorised', $this->tools(signedIn: false)->status(['administrationId' => 'adm-1', 'periodId' => '2026-10'])['error']);
	}

	public function testTheStatusCarriesTheReadinessFlagsLikeTheController(): void {
		$this->periods->method('getPeriodForClose')->with('2026-10', 'adm-1')
			->willReturn(['periodId' => '2026-10', 'endDate' => '2026-10-31', 'status' => 'open']);
		$this->assistant->expects($this->exactly(2))->method('analyse')->with('adm-1', '2026-10', '2026-10-31')->willReturn([['code' => 'unposted']]);

		$tool = $this->tools(signedIn: true)->status(['administrationId' => 'adm-1', 'periodId' => '2026-10']);
		$rest = $this->controller(signedIn: true, administrationId: 'adm-1')->show('2026-10')->getData()['data'];

		$this->assertSame($rest, $tool['period']);
		$this->assertSame([['code' => 'unposted']], $tool['period']['aiFlags']);
	}

	public function testAnUnknownPeriodIsNotFoundOnBothSurfaces(): void {
		$this->periods->method('getPeriodForClose')->willReturn(null);

		$this->assertSame(404, $this->controller(signedIn: true, administrationId: 'adm-1')->show('2026-10')->getStatus());
		$this->assertSame('not_found', $this->tools(signedIn: true)->status(['administrationId' => 'adm-1', 'periodId' => '2026-10'])['error']);
	}

	public function testAnUnexpectedFailureIsLoggedAndAnsweredGenerically(): void {
		$this->serviceDoes(status: 'boom');
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('period close close failed'));

		$result = $this->tools(signedIn: true)->close(['administrationId' => 'adm-1', 'periodId' => '2026-10']);

		$this->assertSame('failed', $result['error']);
		$this->assertStringNotContainsString('database gone', $result['message']);
	}
}
