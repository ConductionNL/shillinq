<?php

/**
 * Golden tests for the input and access checks of InventoryAdjustmentController.
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

use OCA\Shillinq\Controller\InventoryAdjustmentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\LandedCostAllocationService;
use OCA\Shillinq\Service\NrvWriteDownService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every refusal and the allowed path of both adjustment actions.
 */
final class InventoryAdjustmentGoldenTest extends TestCase {

	/**
	 * Landed-cost service mock.
	 *
	 * @var LandedCostAllocationService&MockObject
	 */
	private LandedCostAllocationService&MockObject $landedCost;

	/**
	 * NRV service mock.
	 *
	 * @var NrvWriteDownService&MockObject
	 */
	private NrvWriteDownService&MockObject $nrv;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->landedCost = $this->createMock(LandedCostAllocationService::class);
		$this->nrv = $this->createMock(NrvWriteDownService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Call an action with parameters.
	 *
	 * @param string $action landedCost or nrvWriteDown.
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function call(string $action, array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$controller = new InventoryAdjustmentController($request, $this->landedCost, $this->nrv, $this->context, $this->logger);
		$response = $controller->$action();
		return [$response->getStatus(), $response->getData()];

	}//end call()

	/**
	 * Inputs refused with a 400, before any access check.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function badInputs(): array {
		$lcRequired = 'administration_id and receipt_reference are required';
		$lcInvalid = 'Invalid administration_id, receipt_reference, landed_cost_cents or basis';
		$lc = ['administration_id' => 'adm-1', 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '100'];
		$nrvRequired = 'administration_id, period_id and a non-empty nrv_by_sku map are required';
		$nrvInvalid = 'Invalid administration_id or period_id';
		$nrvMap = 'nrv_by_sku must map valid SKUs to numeric NRV-per-unit values';
		$nrv = ['administration_id' => 'adm-1', 'period_id' => '2026-01'];
		return [
			'lc no administration' => ['landedCost', ['receipt_reference' => 'GRN/1'], $lcRequired],
			'lc no receipt' => ['landedCost', ['administration_id' => 'adm-1', 'receipt_reference' => ' '], $lcRequired],
			'lc bad administration' => ['landedCost', ['administration_id' => 'adm;1'] + $lc, $lcInvalid],
			'lc bad receipt' => ['landedCost', ['receipt_reference' => str_repeat('r', 129)] + $lc, $lcInvalid],
			'lc zero cost' => ['landedCost', ['landed_cost_cents' => '0'] + $lc, $lcInvalid],
			'lc no cost' => ['landedCost', ['administration_id' => 'adm-1', 'receipt_reference' => 'GRN/1'], $lcInvalid],
			'lc bad basis' => ['landedCost', ['basis' => 'weight'] + $lc, $lcInvalid],
			'nrv no administration' => ['nrvWriteDown', ['period_id' => 'p', 'nrv_by_sku' => ['a' => 1]], $nrvRequired],
			'nrv no period' => ['nrvWriteDown', ['administration_id' => 'adm-1', 'nrv_by_sku' => ['a' => 1]], $nrvRequired],
			'nrv no map' => ['nrvWriteDown', $nrv, $nrvRequired],
			'nrv empty map' => ['nrvWriteDown', $nrv + ['nrv_by_sku' => []], $nrvRequired],
			'nrv map not a list' => ['nrvWriteDown', $nrv + ['nrv_by_sku' => 'a=1'], $nrvRequired],
			'nrv bad administration' => ['nrvWriteDown', ['administration_id' => 'adm;1', 'period_id' => 'p', 'nrv_by_sku' => ['a' => 1]], $nrvInvalid],
			'nrv bad period' => ['nrvWriteDown', ['administration_id' => 'adm-1', 'period_id' => '2026/01', 'nrv_by_sku' => ['a' => 1]], $nrvInvalid],
			'nrv long period' => ['nrvWriteDown', ['administration_id' => 'adm-1', 'period_id' => str_repeat('p', 33), 'nrv_by_sku' => ['a' => 1]], $nrvInvalid],
			'nrv blank sku' => ['nrvWriteDown', $nrv + ['nrv_by_sku' => ['a' => 1, ' ' => 2]], $nrvMap],
			'nrv bad sku' => ['nrvWriteDown', $nrv + ['nrv_by_sku' => ['a;b' => 2]], $nrvMap],
			'nrv non-numeric' => ['nrvWriteDown', $nrv + ['nrv_by_sku' => ['a' => 'cheap']], $nrvMap],
		];

	}//end badInputs()

	/**
	 * A missing or malformed input is a 400 and nothing is read.
	 *
	 * @param string $action The action.
	 * @param array<string,mixed> $params The parameters.
	 * @param string $error The expected error.
	 *
	 * @return void
	 *
	 * @dataProvider badInputs
	 */
	public function testBadInputIsRefused(string $action, array $params, string $error): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->expects(self::never())->method('canAccess');
		$this->landedCost->expects(self::never())->method(self::anything());
		$this->nrv->expects(self::never())->method(self::anything());

		self::assertSame([400, ['error' => $error]], $this->call($action, $params));

	}//end testBadInputIsRefused()

	/**
	 * The two actions.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function validCalls(): array {
		return [
			'landed cost' => ['landedCost', ['administration_id' => ' adm-1 ', 'receipt_reference' => 'GRN/1', 'landed_cost_cents' => '100'], 'InventoryAdjustmentController: landed-cost allocation failed'],
			'nrv' => ['nrvWriteDown', ['administration_id' => ' adm-1 ', 'period_id' => '2026-01', 'nrv_by_sku' => [' S1 ' => '2.5']], 'InventoryAdjustmentController: NRV write-down run failed'],
		];

	}//end validCalls()

	/**
	 * Anonymous callers are refused first; foreign administrations with a 404.
	 *
	 * @param string $action The action.
	 * @param array<string,mixed> $params Valid parameters.
	 *
	 * @return void
	 *
	 * @dataProvider validCalls
	 */
	public function testCallerAndAdministrationAreChecked(string $action, array $params): void {
		$this->context->method('currentUserId')->willReturnOnConsecutiveCalls(null, 'alice');
		$this->context->expects(self::once())->method('canAccess')->with('adm-1')->willReturn(false);
		$this->landedCost->expects(self::never())->method(self::anything());
		$this->nrv->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not authenticated']], $this->call($action, []));
		self::assertSame([404, ['error' => 'Administration not found']], $this->call($action, $params));

	}//end testCallerAndAdministrationAreChecked()

	/**
	 * An allowed call reaches its service with the cleaned input.
	 *
	 * @return void
	 */
	public function testAllowedCalls(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->landedCost->expects(self::once())->method('allocate')->with('adm-1', 'GRN/1', 100, 'quantity')->willReturn(['lc' => 1]);
		$this->nrv->expects(self::once())->method('runForAdministration')->with('adm-1', '2026-01', ['S1' => 2.5, '7' => 3.0])->willReturn(['nrv' => 1]);

		self::assertSame([200, ['lc' => 1]], $this->call('landedCost', ['administration_id' => 'adm-1', 'receipt_reference' => ' GRN/1 ', 'landed_cost_cents' => '100', 'basis' => ' quantity ']));
		self::assertSame([200, ['nrv' => 1]], $this->call('nrvWriteDown', ['administration_id' => 'adm-1', 'period_id' => ' 2026-01 ', 'nrv_by_sku' => [' S1 ' => '2.5', 7 => 3]]));

	}//end testAllowedCalls()

	/**
	 * A failing access check is logged per action and answered with a 500.
	 *
	 * @param string $action The action.
	 * @param array<string,mixed> $params Valid parameters.
	 * @param string $logged The logged message.
	 *
	 * @return void
	 *
	 * @dataProvider validCalls
	 */
	public function testFailingAccessCheckIsA500(string $action, array $params, string $logged): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willThrowException(new \RuntimeException('ctx'));
		$this->logger->expects(self::once())->method('error')->with($logged, ['administrationId' => 'adm-1', 'exception' => 'ctx']);

		[$status, $data] = $this->call($action, $params);

		self::assertSame(500, $status);
		self::assertContains($data['error'], ['Failed to allocate landed cost', 'Failed to run NRV write-down']);

	}//end testFailingAccessCheckIsA500()
}//end class
