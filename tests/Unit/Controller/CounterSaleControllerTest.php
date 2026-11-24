<?php

/**
 * Unit tests for CounterSaleController.
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
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTime;
use OCA\Shillinq\Controller\CounterSaleController;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\Payout\CounterSaleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A counter sale: 201 with the invoice and the payment, or the refusal with its status.
 *
 * @covers \OCA\Shillinq\Controller\CounterSaleController
 * @uses   \OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
final class CounterSaleControllerTest extends TestCase {

	/**
	 * The sales service.
	 *
	 * @var CounterSaleService&MockObject
	 */
	private CounterSaleService $sales;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sales = $this->createMock(CounterSaleService::class);
	}//end setUp()

	/**
	 * The controller on 17 November 2026.
	 *
	 * @return CounterSaleController
	 */
	private function controller(): CounterSaleController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-17 10:15:00'));

		return new CounterSaleController(appName: 'shillinq', request: $this->createMock(IRequest::class), sales: $this->sales, time: $time);
	}//end controller()

	/**
	 * A sale answers 201 with the draft invoice and the started payment, dated today.
	 *
	 * @return void
	 */
	public function testASaleAnswersCreated(): void {
		$lines = [['description' => 'Theeblik groot', 'unitPrice' => 12.5, 'vatRate' => 21]];
		$this->sales->expects($this->once())->method('start')->with('THV', $lines, null, '2026-11-17')->willReturn(
			['invoice' => ['id' => 'inv-cs-1'], 'mode' => 'device', 'request' => ['id' => 'pr-1'], 'checkoutUrl' => '', 'device' => ['deviceId' => 'term_1']]
		);

		$response = $this->controller()->create(administrationId: 'THV', lines: $lines);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(
			['invoice' => ['id' => 'inv-cs-1'], 'mode' => 'device', 'request' => ['id' => 'pr-1'], 'checkoutUrl' => ''],
			$response->getData()
		);
	}//end testASaleAnswersCreated()

	/**
	 * A body without lines is a 400 before the service runs; a refusal answers its status.
	 *
	 * @return void
	 */
	public function testMissingLinesAndRefusalsAnswerTheirStatus(): void {
		$this->sales->expects($this->once())->method('start')
			->willThrowException(new FinancialDocumentActionRefusedException(message: 'No device.', status: 409));
		$controller = $this->controller();

		$missing = $controller->create(administrationId: 'THV', lines: 'tea');
		$refused = $controller->create(administrationId: 'THV', lines: []);

		$this->assertSame(400, $missing->getStatus());
		$this->assertSame(['error' => 'A counter sale needs its lines.'], $missing->getData());
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(['error' => 'No device.'], $refused->getData());
	}//end testMissingLinesAndRefusalsAnswerTheirStatus()

	/**
	 * Cancel answers the cancelled invoice, or the refusal with its status.
	 *
	 * @return void
	 */
	public function testCancelAnswersTheInvoiceOrTheRefusal(): void {
		$this->sales->method('abandon')->willReturnCallback(static function (string $invoiceId): array {
			if ($invoiceId === 'inv-paid') {
				throw new FinancialDocumentActionRefusedException(message: 'Only a counter sale that is not paid yet can be cancelled.', status: 409);
			}

			return ['id' => $invoiceId, 'lifecycleState' => 'cancelled'];
		});
		$controller = $this->controller();

		$cancelled = $controller->cancel(id: 'inv-cs-1');
		$refused = $controller->cancel(id: 'inv-paid');

		$this->assertSame(200, $cancelled->getStatus());
		$this->assertSame(['invoice' => ['id' => 'inv-cs-1', 'lifecycleState' => 'cancelled']], $cancelled->getData());
		$this->assertSame(409, $refused->getStatus());
	}//end testCancelAnswersTheInvoiceOrTheRefusal()
}//end class
