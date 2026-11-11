<?php

/**
 * Golden tests for the access steps and answers of every RequisitionController action.
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

use OCA\Shillinq\Controller\RequisitionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RequisitionConversionService;
use OCA\Shillinq\Service\RequisitionService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every action of RequisitionController.
 */
final class RequisitionActionsGoldenTest extends TestCase {

	/**
	 * Requisition service mock.
	 *
	 * @var RequisitionService&MockObject
	 */
	private RequisitionService&MockObject $requisitions;

	/**
	 * Conversion service mock.
	 *
	 * @var RequisitionConversionService&MockObject
	 */
	private RequisitionConversionService&MockObject $conversion;

	/**
	 * Log lines.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->requisitions = $this->createMock(RequisitionService::class);
		$this->conversion = $this->createMock(RequisitionConversionService::class);

	}//end setUp()

	/**
	 * Every action: method, whether it takes an id, 404 message, failure log, failure error.
	 *
	 * @return array<string,array{0:string,1:bool,2:string,3:string,4:string}>
	 */
	public static function actions(): array {
		$c = 'RequisitionController: failed to ';
		return [
			'create' => ['create', false, 'Administration not found', $c . 'create requisition', 'Could not create requisition'],
			'submit' => ['submit', true, 'Requisition not found', $c . 'submit requisition', 'Could not submit requisition'],
			'approve' => ['approve', true, 'Requisition not found', $c . 'approve requisition', 'Could not approve requisition'],
			'reject' => ['reject', true, 'Requisition not found', $c . 'reject requisition', 'Could not reject requisition'],
			'convert' => ['convert', true, 'Requisition not found', $c . 'convert requisition', 'Could not convert requisition'],
		];

	}//end actions()

	/**
	 * Call an action.
	 *
	 * @param string $method The action.
	 * @param bool $hasId Whether it takes an id.
	 * @param array<string,mixed> $params Parameters.
	 * @param array{0:bool,1:bool,2:string} $setup Logged in, may access, route id.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function call(string $method, bool $hasId, array $params, array $setup): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($setup[0] === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => ($setup[1] === true && $id === 'adm-1'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$exception = ($context['exception'] ?? '');
				if ($exception instanceof \Throwable) {
					$context['exception'] = 'object:' . $exception->getMessage();
				}

				$this->logs[] = $message . '|' . json_encode($context);
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new RequisitionController($request, $this->requisitions, $this->conversion, $context, $session, $logger, $l10n);
		$response = ($hasId === true ? $controller->$method($setup[2]) : $controller->$method());
		return [$response->getStatus(), $response->getData()];

	}//end call()

	/**
	 * Refusals before any service call, in order.
	 *
	 * @param string $method The action.
	 * @param bool $hasId Whether it takes an id.
	 * @param string $notFound The 404 message.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testRefusals(string $method, bool $hasId, string $notFound): void {
		$this->requisitions->expects(self::never())->method(self::anything());
		$this->conversion->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not logged in']], $this->call($method, $hasId, [], [false, true, 'x y']));
		if ($hasId === true) {
			self::assertSame([400, ['error' => 'Invalid requisition id']], $this->call($method, $hasId, ['administrationId' => 'adm-1'], [true, true, 'r/1']));
		}

		self::assertSame([400, ['error' => 'administrationId is required']], $this->call($method, $hasId, ['administrationId' => 'a b'], [true, true, 'r-1']));
		self::assertSame([404, ['error' => $notFound]], $this->call($method, $hasId, ['administrationId' => ' adm-1 '], [true, false, 'r-1']));

	}//end testRefusals()

	/**
	 * Unexpected failures are logged per action and a 500.
	 *
	 * @param string $method The action.
	 * @param bool $hasId Whether it takes an id.
	 * @param string $notFound The 404 message.
	 * @param string $log The failure log.
	 * @param string $error The failure error.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testFailures(string $method, bool $hasId, string $notFound, string $log, string $error): void {
		$this->requisitions->method(self::anything())->willThrowException(new \Error('db'));
		$this->conversion->method(self::anything())->willThrowException(new \Error('db'));

		self::assertSame([500, ['error' => $error]], $this->call($method, $hasId, ['administrationId' => 'adm-1'], [true, true, 'r-1']));
		$context = ['administrationId' => 'adm-1', 'exception' => 'db'];
		if ($hasId === true) {
			$context = ['requisitionId' => 'r-1'] + $context;
		}

		self::assertSame([$log . '|' . json_encode($context)], $this->logs);

	}//end testFailures()

	/**
	 * Refusals of the service: create's validation slug, the others by message.
	 *
	 * @return void
	 */
	public function testServiceRefusals(): void {
		$this->requisitions->method(self::anything())->willThrowException(new \RuntimeException('Requisition r-1 not found'));
		$this->conversion->method(self::anything())->willThrowException(new \RuntimeException('wrong state'));

		self::assertSame([400, ['message' => 'Requisition could not be created; check the required fields', 'error' => 'requisition-create-invalid']], $this->call('create', false, ['administrationId' => 'adm-1'], [true, true, '']));
		foreach (['submit', 'approve', 'reject'] as $method) {
			self::assertSame([404, ['message' => 'Requisition not found', 'error' => 'requisition-not-found']], $this->call($method, true, ['administrationId' => 'adm-1'], [true, true, 'r-1']));
		}

		self::assertSame([409, ['message' => 'Requisition is not in a state that allows this action', 'error' => 'requisition-invalid-state']], $this->call('convert', true, ['administrationId' => 'adm-1'], [true, true, 'r-1']));
		self::assertSame(
			array_merge(
				['RequisitionController.create rejected|{"administrationId":"adm-1","exception":"object:Requisition r-1 not found"}'],
				array_fill(0, 3, 'RequisitionController: requisition action rejected|{"exception":"object:Requisition r-1 not found"}'),
				['RequisitionController: requisition action rejected|{"exception":"object:wrong state"}']
			),
			$this->logs
		);

	}//end testServiceRefusals()

	/**
	 * The arguments each action hands to its service and its status.
	 *
	 * @return void
	 */
	public function testServiceCalls(): void {
		$calls = [];
		$record = static function (string $name) use (&$calls): \Closure {
			return static function (...$args) use ($name, &$calls): array {
				$calls[] = array_merge([$name], $args);
				return [$name => true];
			};
		};
		$this->requisitions->method('createRequisition')->willReturnCallback($record('create'));
		$this->requisitions->method('submitRequisition')->willReturnCallback($record('submit'));
		$this->requisitions->method('approveRequisition')->willReturnCallback($record('approve'));
		$this->requisitions->method('rejectRequisition')->willReturnCallback($record('reject'));
		$this->conversion->method('convertToPurchaseOrder')->willReturnCallback($record('convert'));
		$params = ['administrationId' => 'adm-1', 'programme' => ' P ', 'financialYear' => '2026', 'neededByDate' => ' 2026-02-01 ', 'kind' => 'goods', 'lines' => 'l', 'reason' => ' too much '];

		self::assertSame([201, ['create' => true]], $this->call('create', false, $params, [true, true, '']));
		self::assertSame([200, ['submit' => true]], $this->call('submit', true, $params, [true, true, 'r-1']));
		self::assertSame([200, ['approve' => true]], $this->call('approve', true, $params, [true, true, 'r-1']));
		self::assertSame([200, ['reject' => true]], $this->call('reject', true, $params, [true, true, 'r-1']));
		self::assertSame([200, ['convert' => true]], $this->call('convert', true, $params, [true, true, 'r-1']));
		self::assertSame(
			[
				['create', 'adm-1', ['programme' => 'P', 'financialYear' => 2026, 'neededByDate' => '2026-02-01', 'justification' => '', 'kind' => 'goods', 'preferredSupplierId' => '', 'lines' => ['l']]],
				['submit', 'adm-1', 'r-1'],
				['approve', 'adm-1', 'r-1', 'alice'],
				['reject', 'adm-1', 'r-1', 'alice', 'too much'],
				['convert', 'adm-1', 'r-1'],
			],
			$calls
		);

	}//end testServiceCalls()
}//end class
