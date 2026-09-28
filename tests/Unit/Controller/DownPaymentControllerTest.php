<?php

/**
 * Unit tests for DownPaymentController.
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
 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DownPaymentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\DownPaymentRefusedException;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SDP-001, REQ-SDP-003, REQ-SDP-005 at the HTTP edge: the administration is checked first.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DownPaymentControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param DownPaymentService  $service The service.
	 * @param bool                $member  Whether the caller may access the administration.
	 * @param array<string,mixed> $params  Request params.
	 *
	 * @return DownPaymentController
	 */
	private function controller(DownPaymentService $service, bool $member, array $params = []): DownPaymentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
		$request->method('getParams')->willReturn($params);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($member);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => 'NL:' . vsprintf($text, $p));

		return new DownPaymentController(
			request: $request,
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end controller()

	/**
	 * Raising a down payment in another administration is refused before anything is read.
	 *
	 * @return void
	 */
	public function testRaisingInAForeignAdministrationIsForbidden(): void {
		$service = $this->createMock(DownPaymentService::class);
		$service->expects(self::never())->method('raise');

		$response = $this->controller($service, false, ['administrationId' => 'adm-other', 'customerId' => 'c', 'orderReference' => 'o', 'percentage' => 30])->create();

		self::assertSame(403, $response->getStatus());

	}//end testRaisingInAForeignAdministrationIsForbidden()

	/**
	 * A raised down payment answers 201 with the draft invoice.
	 *
	 * @return void
	 */
	public function testRaisingAnswersTheDraft(): void {
		$service = $this->createMock(DownPaymentService::class);
		$service->expects(self::once())->method('raise')
			->with(self::callback(static fn (array $request): bool => $request['percentage'] === 30 && $request['administrationId'] === 'adm-kvl'))
			->willReturn(['id' => 'ar-dp', 'grossAmount' => 5445.0]);

		$response = $this->controller($service, true, ['administrationId' => 'adm-kvl', 'customerId' => 'c', 'orderReference' => 'o', 'percentage' => 30])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('ar-dp', $response->getData()['id']);

	}//end testRaisingAnswersTheDraft()

	/**
	 * A refusal answers 422 with the translated message.
	 *
	 * @return void
	 */
	public function testARefusalIsTranslated(): void {
		$service = $this->createMock(DownPaymentService::class);
		$service->method('raise')->willThrowException(new DownPaymentRefusedException(template: 'Enter a percentage or an amount above zero.'));

		$response = $this->controller($service, true, ['administrationId' => 'adm-kvl'])->create();

		self::assertSame(422, $response->getStatus());
		self::assertSame('NL:Enter a percentage or an amount above zero.', $response->getData()['message']);

	}//end testARefusalIsTranslated()

	/**
	 * Another administration's invoice is masked as absent on every invoice route.
	 *
	 * @return void
	 */
	public function testAForeignInvoiceIsMaskedAsNotFound(): void {
		$service = $this->createMock(DownPaymentService::class);
		$service->method('findInvoice')->willReturn(['id' => 'ar-x', 'administrationId' => 'adm-other']);
		$service->expects(self::never())->method('deductOnto');
		$service->expects(self::never())->method('position');

		self::assertSame(404, $this->controller($service, false)->show('ar-x')->getStatus());
		self::assertSame(404, $this->controller($service, false, ['orderReference' => 'o'])->deduct('ar-x')->getStatus());

	}//end testAForeignInvoiceIsMaskedAsNotFound()

	/**
	 * The panel data: the order's position and the orders with open down payments.
	 *
	 * @return void
	 */
	public function testShowAnswersPositionAndOpenOrders(): void {
		$invoice = ['id' => 'ar-kitchen', 'administrationId' => 'adm-kvl', 'lifecycleState' => 'draft'];
		$service = $this->createMock(DownPaymentService::class);
		$service->method('findInvoice')->willReturn($invoice);
		$service->method('position')->willReturn([]);
		$service->method('openDownPayments')->willReturn(
			[
				['id' => 'ar-dp', 'invoiceNumber' => '2026-0412', 'grossAmount' => 5445.0, 'lifecycleState' => 'paid', 'downPayment' => ['orderReference' => 'order-117', 'orderLabel' => 'Keuken Eiland 2026-117']],
			]
		);

		$data = $this->controller($service, true)->show('ar-kitchen')->getData();

		self::assertTrue($data['canDeduct']);
		self::assertSame('order-117', $data['openOrders'][0]['orderReference']);
		self::assertSame('Keuken Eiland 2026-117', $data['openOrders'][0]['orderLabel']);
		self::assertSame(5445.0, $data['openOrders'][0]['grossAmount']);

	}//end testShowAnswersPositionAndOpenOrders()
}//end class
