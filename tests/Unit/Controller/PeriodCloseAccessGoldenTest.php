<?php

/**
 * Golden tests for the access checks and failure answers of PeriodCloseController.
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

use OCA\Shillinq\Controller\PeriodCloseController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\PeriodCloseException;
use OCA\Shillinq\Service\PeriodCloseService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the steps of every period-close action.
 */
final class PeriodCloseAccessGoldenTest extends TestCase {

	/**
	 * Service mock.
	 *
	 * @var PeriodCloseService&MockObject
	 */
	private PeriodCloseService&MockObject $service;

	/**
	 * Assistant mock.
	 *
	 * @var PeriodCloseAssistantService&MockObject
	 */
	private PeriodCloseAssistantService&MockObject $assistant;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logged errors.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Whether a user is logged in.
	 *
	 * @var boolean
	 */
	private bool $loggedIn = true;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(PeriodCloseService::class);
		$this->assistant = $this->createMock(PeriodCloseAssistantService::class);
		$this->context = $this->createMock(AdministrationContextService::class);

	}//end setUp()

	/**
	 * Call an action.
	 *
	 * @param string $action The action.
	 * @param string $periodId The route period.
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function call(string $action, string $periodId, array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->loggedIn === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');
				return $user;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . ($context['exception'] ?? '');
			}
		);
		$controller = new PeriodCloseController($request, $this->service, $this->assistant, $this->context, $session, $logger);
		$response = $controller->$action($periodId);
		return [$response->getStatus(), $response->getData()];

	}//end call()

	/**
	 * Every action.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function actions(): array {
		return [
			'show' => ['show'],
			'aiFlags' => ['aiFlags'],
			'close' => ['close'],
			'startClose' => ['startClose'],
			'reopen' => ['reopen'],
			'lockAudit' => ['lockAudit'],
		];

	}//end actions()

	/**
	 * Refusals before anything is read, in order: user, period, administration, access.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testRefusals(string $action): void {
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id !== 'adm-x');
		$this->service->expects(self::never())->method(self::anything());
		$this->assistant->expects(self::never())->method(self::anything());

		$this->loggedIn = false;
		self::assertSame([401, ['error' => 'Authentication required']], $this->call($action, 'bad id', []));
		$this->loggedIn = true;
		self::assertSame([400, ['error' => 'period_id must be a valid identifier']], $this->call($action, 'p/1', ['administration_id' => 'bad id']));
		self::assertSame([400, ['error' => 'administration_id is required']], $this->call($action, ' 2026-01 ', ['administration_id' => 'a b']));
		self::assertSame([400, ['error' => 'administration_id is required']], $this->call($action, '2026-01', []));
		self::assertSame([404, ['error' => 'Period not found']], $this->call($action, '2026-01', ['administration_id' => ' adm-x ']));

	}//end testRefusals()

	/**
	 * show and aiFlags read the period and its flags.
	 *
	 * @return void
	 */
	public function testReads(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('getPeriodForClose')->willReturnCallback(
			static fn (string $periodId, string $administrationId): ?array => ($periodId === 'none' ? null : ['periodId' => 'P-' . $periodId, 'endDate' => '2026-01-31', 'aiFlags' => 'old'])
		);
		$this->assistant->method('analyse')->willReturnCallback(
			static fn (string $administrationId, string $periodId, string $endDate): array => [$administrationId, $periodId, $endDate]
		);

		self::assertSame([200, ['data' => ['periodId' => 'P-1', 'endDate' => '2026-01-31', 'aiFlags' => ['adm-1', 'P-1', '2026-01-31']]]], $this->call('show', ' 1 ', ['administration_id' => 'adm-1']));
		self::assertSame([200, ['data' => ['adm-1', 'P-1', '2026-01-31']]], $this->call('aiFlags', '1', ['administration_id' => 'adm-1']));
		self::assertSame([404, ['error' => 'Period not found']], $this->call('show', 'none', ['administration_id' => 'adm-1']));
		self::assertSame([404, ['error' => 'Period not found']], $this->call('aiFlags', 'none', ['administration_id' => 'adm-1']));

	}//end testReads()

	/**
	 * A record without periodId or endDate is analysed with the route period and ''.
	 *
	 * @return void
	 */
	public function testReadDefaults(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('getPeriodForClose')->willReturn([]);
		$this->assistant->expects(self::once())->method('analyse')->with('adm-1', '7', '')->willReturn([]);

		self::assertSame([200, ['data' => ['aiFlags' => []]]], $this->call('show', '7', ['administration_id' => 'adm-1']));

	}//end testReadDefaults()

	/**
	 * Read failures are logged as the action and a 500.
	 *
	 * @return void
	 */
	public function testReadFailures(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('getPeriodForClose')->willThrowException(new \RuntimeException('db'));

		[$showStatus] = $this->call('show', '1', ['administration_id' => 'adm-1']);
		[$flagStatus] = $this->call('aiFlags', '1', ['administration_id' => 'adm-1']);

		self::assertSame([500, 500], [$showStatus, $flagStatus]);
		self::assertSame(['PeriodCloseController: show failed|db', 'PeriodCloseController: aiFlags failed|db'], $this->logs);

	}//end testReadFailures()

	/**
	 * Transition outcomes: the record, a mapped refusal, or a logged 500.
	 *
	 * @return void
	 */
	public function testTransitions(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('closePeriod')->willReturn(['closed' => true]);
		$this->service->method('startClose')->willThrowException(new PeriodCloseException('busy', 'conflict'));
		$this->service->method('lockForAudit')->willThrowException(new \RuntimeException('db'));
		$this->service->method('reopenPeriod')->willReturnCallback(
			static fn (string $periodId, string $administrationId, string $closeReason, string $userId): array => [$periodId, $administrationId, $closeReason, $userId]
		);

		self::assertSame([200, ['data' => ['closed' => true]]], $this->call('close', '1', ['administration_id' => 'adm-1']));
		self::assertSame([409, ['error' => 'busy']], $this->call('startClose', '1', ['administration_id' => 'adm-1']));
		self::assertSame(500, $this->call('lockAudit', '1', ['administration_id' => 'adm-1'])[0]);
		self::assertSame([200, ['data' => ['1', 'adm-1', 'typo', 'alice']]], $this->call('reopen', ' 1 ', ['administration_id' => 'adm-1', 'closeReason' => ' typo ']));
		self::assertSame(['PeriodCloseController: transition failed|db'], $this->logs);

	}//end testTransitions()
}//end class
