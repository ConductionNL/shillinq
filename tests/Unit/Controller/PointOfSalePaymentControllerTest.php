<?php

/**
 * Unit tests for PointOfSalePaymentController.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PointOfSalePaymentController;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\Payout\PointOfSalePaymentService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Take payment now: 201 with the mode and the request, or the refusal with its status.
 *
 * @covers \OCA\Shillinq\Controller\PointOfSalePaymentController
 * @uses   \OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */
final class PointOfSalePaymentControllerTest extends TestCase {

	/**
	 * A started payment answers 201 with its mode, request and checkout.
	 *
	 * @return void
	 */
	public function testAStartedPaymentAnswersCreated(): void {
		$payments = $this->createMock(PointOfSalePaymentService::class);
		$payments->expects($this->once())->method('takePayment')->with('inv-1')->willReturn(
			['mode' => 'qr', 'request' => ['id' => 'pr-1'], 'checkoutUrl' => 'https://pay.example/1', 'device' => ['deviceId' => 'term_1']]
		);

		$response = (new PointOfSalePaymentController(appName: 'shillinq', request: $this->createMock(IRequest::class), payments: $payments))
			->takePayment(id: 'inv-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['mode' => 'qr', 'request' => ['id' => 'pr-1'], 'checkoutUrl' => 'https://pay.example/1'], $response->getData());
	}//end testAStartedPaymentAnswersCreated()

	/**
	 * A refusal answers its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatus(): void {
		$payments = $this->createMock(PointOfSalePaymentService::class);
		$payments->method('takePayment')->willThrowException(new FinancialDocumentActionRefusedException(message: 'No device.', status: 409));

		$response = (new PointOfSalePaymentController(appName: 'shillinq', request: $this->createMock(IRequest::class), payments: $payments))
			->takePayment(id: 'inv-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'No device.'], $response->getData());
	}//end testARefusalAnswersItsStatus()
}//end class
