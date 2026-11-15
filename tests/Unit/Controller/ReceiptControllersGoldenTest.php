<?php

/**
 * Golden tests for the access steps and answers of the goods-receipt and
 * service-receipt controllers.
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

use OCA\Shillinq\Controller\GoodsReceiptNoteController;
use OCA\Shillinq\Controller\ServiceReceiptController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCA\Shillinq\Service\ServiceReceiptService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every action of both receipt controllers.
 */
final class ReceiptControllersGoldenTest extends TestCase {

	/**
	 * The service mock.
	 *
	 * @var MockObject
	 */
	private MockObject $service;

	/**
	 * Log lines.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Every action: controller, method, whether it takes an id, service method,
	 * 404 message, invalid-id message, failure log, failure error, id log key.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function actions(): array {
		$grn = 'GoodsReceiptNoteController: ';
		$svc = 'ServiceReceiptController: ';
		return [
			'grn create' => ['grn', 'create', false, 'createGRN', 'Administration not found', '', $grn . 'failed to create GRN', 'Could not create goods receipt note', ''],
			'grn addLine' => ['grn', 'addLine', true, 'addGRNLine', 'Goods receipt note not found', 'Invalid GRN id', $grn . 'failed to add GRN line', 'Could not add goods receipt line', 'grnId'],
			'grn qualityCheck' => ['grn', 'qualityCheck', true, 'qualityCheckPass', 'Goods receipt note not found', 'Invalid GRN id', $grn . 'failed quality-check transition', 'Could not transition GRN', 'grnId'],
			'grn accept' => ['grn', 'accept', true, 'acceptGRN', 'Goods receipt note not found', 'Invalid GRN id', $grn . 'failed to accept GRN', 'Could not accept GRN', 'grnId'],
			'grn uploadPhotos' => ['grn', 'uploadPhotos', true, 'uploadPhotos', 'Goods receipt note not found', 'Invalid GRN id', $grn . 'failed to attach GRN photos', 'Could not attach photos', 'grnId'],
			'svc create' => ['svc', 'create', false, 'createServiceReceipt', 'Administration not found', '', $svc . 'failed to create service receipt', 'Could not create service receipt', ''],
			'svc addLine' => ['svc', 'addLine', true, 'addServiceReceiptLine', 'Service receipt not found', 'Invalid service receipt id', $svc . 'failed to add service receipt line', 'Could not add service receipt line', 'receiptId'],
			'svc confirm' => ['svc', 'confirm', true, 'confirmServiceReceipt', 'Service receipt not found', 'Invalid service receipt id', $svc . 'failed confirm transition', 'Could not confirm service receipt', 'receiptId'],
			'svc accept' => ['svc', 'accept', true, 'acceptServiceReceipt', 'Service receipt not found', 'Invalid service receipt id', $svc . 'failed to accept service receipt', 'Could not accept service receipt', 'receiptId'],
		];

	}//end actions()

	/**
	 * Call an action.
	 *
	 * @param array<int,mixed> $action The action row.
	 * @param array<string,mixed> $params Request parameters.
	 * @param array{0:bool,1:bool,2:string} $setup Logged in, may access, route id.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function call(array $action, array $params, array $setup): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($setup[0] === true ? $this->createMock(IUser::class) : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => ($setup[1] === true && $id === 'adm-1'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . json_encode($context);
			}
		);
		if ($action[0] === 'grn') {
			$controller = new GoodsReceiptNoteController($request, $this->service, $context, $session, $logger);
		} else {
			$controller = new ServiceReceiptController($request, $this->service, $context, $session, $logger);
		}

		$method = $action[1];
		$response = ($action[2] === true ? $controller->$method($setup[2]) : $controller->$method());
		return [$response->getStatus(), $response->getData()];

	}//end call()

	/**
	 * Build the service mock of a controller.
	 *
	 * @param string $kind grn or svc.
	 *
	 * @return void
	 */
	private function service(string $kind): void {
		$this->service = $this->createMock($kind === 'grn' ? GoodsReceiptNoteService::class : ServiceReceiptService::class);

	}//end service()

	/**
	 * Refusals before the service, in order.
	 *
	 * @param string $kind Controller.
	 * @param string $method Action.
	 * @param bool $hasId Whether it takes an id.
	 * @param string $serviceMethod Service method.
	 * @param string $notFound 404 message.
	 * @param string $invalidId Invalid-id message.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testRefusals(string $kind, string $method, bool $hasId, string $serviceMethod, string $notFound, string $invalidId): void {
		$this->service($kind);
		$this->service->expects(self::never())->method(self::anything());
		$action = func_get_args();

		self::assertSame([401, ['error' => 'Not logged in']], $this->call($action, [], [false, true, 'bad id']));
		if ($hasId === true) {
			self::assertSame([400, ['error' => $invalidId]], $this->call($action, ['administrationId' => 'adm-1'], [true, true, 'g 1']));
		}

		self::assertSame([400, ['error' => 'administrationId is required']], $this->call($action, ['administrationId' => 'adm 1'], [true, true, 'g-1']));
		self::assertSame([400, ['error' => 'administrationId is required']], $this->call($action, [], [true, true, 'g-1']));
		self::assertSame([404, ['error' => $notFound]], $this->call($action, ['administrationId' => ' adm-1 '], [true, false, 'g-1']));

	}//end testRefusals()

	/**
	 * Runtime refusals of the service and the status they map to.
	 *
	 * Goods receipts also treat a terminal state and an excess as conflicts;
	 * service receipts do not.
	 *
	 * @return array<string,array{0:string,1:int,2:int}>
	 */
	public static function runtimeRefusals(): array {
		return [
			'not found' => ['GRN g-1 not found', 404, 404],
			'terminal' => ['GRN is in a terminal state', 409, 400],
			'status code' => ['transition requires statusCode', 409, 409],
			'exceed' => ['accepted may not exceed received', 409, 400],
			'other' => ['bad payload', 400, 400],
		];

	}//end runtimeRefusals()

	/**
	 * Every action maps a runtime refusal by its message.
	 *
	 * @param string $message The refusal message.
	 * @param int $grnStatus The expected status for a goods receipt.
	 * @param int $svcStatus The expected status for a service receipt.
	 *
	 * @return void
	 *
	 * @dataProvider runtimeRefusals
	 */
	public function testRuntimeRefusals(string $message, int $grnStatus, int $svcStatus): void {
		foreach (self::actions() as $action) {
			$status = ($action[0] === 'grn' ? $grnStatus : $svcStatus);
			$this->service($action[0]);
			$this->service->method($action[3])->willThrowException(new \RuntimeException($message));
			self::assertSame([$status, ['error' => $message]], $this->call($action, ['administrationId' => 'adm-1'], [true, true, 'g-1']), $action[1]);
		}

		self::assertSame([], $this->logs);

	}//end testRuntimeRefusals()

	/**
	 * Every action logs another failure under its own message and answers 500.
	 *
	 * @param string $kind Controller.
	 * @param string $method Action.
	 * @param bool $hasId Whether it takes an id.
	 * @param string $serviceMethod Service method.
	 * @param string $notFound 404 message.
	 * @param string $invalidId Invalid-id message.
	 * @param string $log The failure log.
	 * @param string $error The failure error.
	 * @param string $idKey The id's log key.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testFailures(string $kind, string $method, bool $hasId, string $serviceMethod, string $notFound, string $invalidId, string $log, string $error, string $idKey): void {
		$this->service($kind);
		$this->service->method($serviceMethod)->willThrowException(new \Error('db'));

		self::assertSame([500, ['error' => $error]], $this->call(func_get_args(), ['administrationId' => 'adm-1'], [true, true, 'g-1']));
		$context = ['administrationId' => 'adm-1', 'exception' => 'db'];
		if ($idKey !== '') {
			$context = [$idKey => 'g-1'] + $context;
		}

		self::assertSame([$log . '|' . json_encode($context)], $this->logs);

	}//end testFailures()

	/**
	 * The payloads and arguments handed to the services, and the statuses.
	 *
	 * @return void
	 */
	public function testServiceCalls(): void {
		$params = [
			'administrationId' => 'adm-1', 'poIds' => 'po-1', 'receivedAt' => ' 2026-01-01 ', 'carrier' => ' DHL ', 'deliveryNoteReference' => 'DN',
			'costCenter' => ' CC ', 'projectCode' => 'P', 'lotNumbers' => 'L', 'photos' => ['f1'], 'poLineId' => ' pl-1 ', 'quantityReceived' => '5',
			'rejectionReason' => ' wet ', 'batchReference' => 'B', 'periodStart' => ' 2026-01 ', 'notes' => ' n ', 'percentageComplete' => 50,
		];
		$calls = [];
		$this->service('grn');
		$this->service->method('createGRN')->willReturnCallback(static function (string $a, array $p) use (&$calls): array {
			$calls[] = ['createGRN', $a, $p];
			return ['grn' => 1];
		});
		$this->service->method('addGRNLine')->willReturnCallback(static function (string $a, string $g, array $p) use (&$calls): array {
			$calls[] = ['addGRNLine', $a, $g, $p];
			return ['line' => 1];
		});
		$this->service->method('uploadPhotos')->willReturnCallback(static function (string $a, string $g, array $f) use (&$calls): array {
			$calls[] = ['uploadPhotos', $a, $g, $f];
			return ['photos' => 1];
		});
		$this->service->method('acceptGRN')->willReturn(['accepted' => 1]);

		self::assertSame([201, ['grn' => 1]], $this->call(['grn', 'create', false], $params, [true, true, '']));
		self::assertSame([201, ['line' => 1]], $this->call(['grn', 'addLine', true], $params, [true, true, 'g-1']));
		self::assertSame([200, ['photos' => 1]], $this->call(['grn', 'uploadPhotos', true], $params, [true, true, 'g-1']));
		self::assertSame([200, ['accepted' => 1]], $this->call(['grn', 'accept', true], $params, [true, true, 'g-1']));

		$this->service('svc');
		$this->service->method('createServiceReceipt')->willReturnCallback(static function (string $a, array $p) use (&$calls): array {
			$calls[] = ['createServiceReceipt', $a, $p];
			return ['sr' => 1];
		});
		$this->service->method('addServiceReceiptLine')->willReturnCallback(static function (string $a, string $r, array $p) use (&$calls): array {
			$calls[] = ['addServiceReceiptLine', $a, $r, $p];
			return ['line' => 2];
		});
		$this->service->method('confirmServiceReceipt')->willReturn(['confirmed' => 1]);

		self::assertSame([201, ['sr' => 1]], $this->call(['svc', 'create', false], $params, [true, true, '']));
		self::assertSame([201, ['line' => 2]], $this->call(['svc', 'addLine', true], $params, [true, true, 's-1']));
		self::assertSame([200, ['confirmed' => 1]], $this->call(['svc', 'confirm', true], $params, [true, true, 's-1']));

		self::assertSame(
			[
				['createGRN', 'adm-1', ['poIds' => ['po-1'], 'receivedAt' => '2026-01-01', 'carrier' => 'DHL', 'deliveryNoteReference' => 'DN', 'costCenter' => 'CC', 'projectCode' => 'P', 'lotNumbers' => ['L'], 'serialNumbers' => [], 'temperatureLog' => [], 'photos' => ['f1']]],
				['addGRNLine', 'adm-1', 'g-1', ['poLineId' => 'pl-1', 'quantityReceived' => '5', 'quantityAccepted' => null, 'quantityRejected' => 0, 'rejectionReason' => 'wet', 'batchReference' => 'B']],
				['uploadPhotos', 'adm-1', 'g-1', ['f1']],
				['createServiceReceipt', 'adm-1', ['poIds' => ['po-1'], 'periodStart' => '2026-01', 'periodEnd' => '', 'notes' => 'n', 'costCenter' => 'CC', 'projectCode' => 'P']],
				['addServiceReceiptLine', 'adm-1', 's-1', ['poLineId' => 'pl-1', 'percentageComplete' => 50, 'quantityConfirmed' => null, 'amountConfirmedCents' => null, 'periodStart' => ' 2026-01 ', 'periodEnd' => null, 'notes' => ' n ']],
			],
			$calls
		);

	}//end testServiceCalls()
}//end class
