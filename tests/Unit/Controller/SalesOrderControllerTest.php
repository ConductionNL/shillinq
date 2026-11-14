<?php

/**
 * Unit tests for SalesOrderController.
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
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SalesOrderController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\SalesOrderInvoicingService;
use OCA\Shillinq\Service\Sales\SalesOrderRefusedException;
use OCP\IL10N;
use OCP\IRequest;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * REQ-QTI-001 and REQ-QTI-003 at the HTTP edge: the object's administration is checked before anything is written.
 *
 * @covers \OCA\Shillinq\Controller\SalesOrderController
 * @uses   \OCA\Shillinq\Service\Sales\SalesOrderRefusedException
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SalesOrderControllerTest extends TestCase {
	/**
	 * The administrations the caller asked about, in order.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * Build the controller.
	 *
	 * @param SalesOrderInvoicingService $service The service.
	 * @param bool                       $member  Whether the caller works in the object's administration.
	 *
	 * @return SalesOrderController
	 */
	private function controller(SalesOrderInvoicingService $service, bool $member): SalesOrderController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($member): bool {
				$this->asked[] = $administrationId;
				return $member;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => 'NL:' . vsprintf($text, $p));

		return new SalesOrderController(
			request: $this->createMock(IRequest::class),
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end controller()

	/**
	 * Both endpoints are open to non-admins; the administration check is the guard.
	 *
	 * @return void
	 */
	public function testBothEndpointsAreForNonAdmins(): void {
		foreach (['fromQuote', 'invoice'] as $method) {
			$declared   = (new ReflectionMethod(SalesOrderController::class, $method))->getAttributes();
			$attributes = array_map(static fn ($attribute): string => $attribute->getName(), $declared);
			self::assertSame(['OCP\AppFramework\Http\Attribute\NoAdminRequired'], $attributes, $method);
		}

	}//end testBothEndpointsAreForNonAdmins()

	/**
	 * A quote of another administration answers 404 and no order is written.
	 *
	 * @return void
	 */
	public function testAQuoteOfAnotherAdministrationIsMaskedAsNotFound(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findQuote')->willReturn(['id' => 'q-1', 'administrationId' => 'adm-other']);
		$service->expects(self::never())->method('orderFromQuote');

		$response = $this->controller($service, false)->fromQuote('q-1');

		self::assertSame(404, $response->getStatus());
		self::assertSame('NL:Quote not found', $response->getData()['message']);
		self::assertSame(['adm-other'], $this->asked);

	}//end testAQuoteOfAnotherAdministrationIsMaskedAsNotFound()

	/**
	 * An order of another administration answers 404 and no invoice is written.
	 *
	 * @return void
	 */
	public function testAnOrderOfAnotherAdministrationIsMaskedAsNotFound(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findOrder')->willReturn(['id' => 'o-1', 'administrationId' => 'adm-other']);
		$service->expects(self::never())->method('invoiceFromOrder');

		$response = $this->controller($service, false)->invoice('o-1');

		self::assertSame(404, $response->getStatus());
		self::assertSame('NL:Order not found', $response->getData()['message']);

	}//end testAnOrderOfAnotherAdministrationIsMaskedAsNotFound()

	/**
	 * A missing object, or one without an administration, answers 404 without asking about access.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsNotFound(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findQuote')->willThrowException(new OutOfBoundsException('Quote q-9 not found'));
		$service->method('findOrder')->willReturn(['id' => 'o-1']);
		$service->expects(self::never())->method('orderFromQuote');
		$service->expects(self::never())->method('invoiceFromOrder');

		self::assertSame(404, $this->controller($service, true)->fromQuote('q-9')->getStatus());
		self::assertSame(404, $this->controller($service, true)->invoice('o-1')->getStatus());
		self::assertSame([], $this->asked);

	}//end testAMissingObjectIsNotFound()

	/**
	 * A member's accepted quote becomes an order in the quote's own administration, answered 201.
	 *
	 * @return void
	 */
	public function testAMembersQuoteBecomesAnOrder(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findQuote')->willReturn(['id' => 'q-1', 'administrationId' => 'adm-korenaar']);
		$service->expects(self::once())->method('orderFromQuote')->with('Q-2026-031', 'adm-korenaar')->willReturn(['id' => 'o-1', 'state' => 'draft']);

		$response = $this->controller($service, true)->fromQuote('Q-2026-031');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['id' => 'o-1', 'state' => 'draft'], $response->getData());

	}//end testAMembersQuoteBecomesAnOrder()

	/**
	 * A member's confirmed order is invoiced in the order's own administration, answered 201.
	 *
	 * @return void
	 */
	public function testAMembersOrderIsInvoiced(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findOrder')->willReturn(['id' => 'o-1', 'administrationId' => 'adm-korenaar']);
		$service->expects(self::once())->method('invoiceFromOrder')->with('o-1', 'adm-korenaar')->willReturn(['id' => 'ar-1', 'orderState' => 'invoiced']);

		$response = $this->controller($service, true)->invoice('o-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame('ar-1', $response->getData()['id']);

	}//end testAMembersOrderIsInvoiced()

	/**
	 * A refusal answers 422 with the message in the caller's language.
	 *
	 * @return void
	 */
	public function testARefusalIsTranslated(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findOrder')->willReturn(['id' => 'o-1', 'administrationId' => 'adm-korenaar']);
		$service->method('invoiceFromOrder')->willThrowException(
			new SalesOrderRefusedException(template: 'Every line of order %1$s is already invoiced.', parameters: ['SO-1'])
		);

		$response = $this->controller($service, true)->invoice('o-1');

		self::assertSame(422, $response->getStatus());
		self::assertSame('NL:Every line of order SO-1 is already invoiced.', $response->getData()['message']);

	}//end testARefusalIsTranslated()

	/**
	 * An object that vanishes between the check and the write answers 404; any other failure 500 without its details.
	 *
	 * @return void
	 */
	public function testVanishedAndFailedWritesAreAnswered(): void {
		$service = $this->createMock(SalesOrderInvoicingService::class);
		$service->method('findQuote')->willReturn(['id' => 'q-1', 'administrationId' => 'adm-korenaar']);
		$service->method('findOrder')->willReturn(['id' => 'o-1', 'administrationId' => 'adm-korenaar']);
		$service->method('orderFromQuote')->willThrowException(new OutOfBoundsException('gone'));
		$service->method('invoiceFromOrder')->willThrowException(new RuntimeException('database down'));

		self::assertSame(404, $this->controller($service, true)->fromQuote('q-1')->getStatus());
		$failed = $this->controller($service, true)->invoice('o-1');
		self::assertSame(500, $failed->getStatus());
		self::assertSame('NL:The sales order could not be saved.', $failed->getData()['message']);

	}//end testVanishedAndFailedWritesAreAnswered()
}//end class
