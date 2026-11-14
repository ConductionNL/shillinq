<?php

/**
 * Tests for InvoiceSendingController (sales-invoice-sending task 2.3).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\InvoiceSendingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceSendingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The administration is checked first; the service's refusals become 404 and 409.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-issued-invoice-is-sent-by-email-with-its-pdf-attached-req-sis-001
 */
class InvoiceSendingControllerTest extends TestCase {

	private InvoiceSendingService&MockObject $sending;

	private AdministrationContextService&MockObject $context;

	private InvoiceSendingController $controller;

	protected function setUp(): void {
		$this->sending = $this->createMock(InvoiceSendingService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$this->controller = new InvoiceSendingController(
			request: $this->createMock(IRequest::class),
			sending: $this->sending,
			context: $this->context,
			logger: new NullLogger()
		);
	}//end setUp()

	public function testASendAnswersTheOutcome(): void {
		$outcome = ['status' => 'sent', 'sending' => ['sentVia' => 'email', 'sentTo' => 'a@b.example', 'sentAt' => '2026-10-01T08:30:00Z'], 'error' => ''];
		$this->sending->expects($this->once())->method('send')->with('adm-1', 'inv-1', 'email')->willReturn($outcome);

		$response = $this->controller->send(id: 'inv-1', administrationId: 'adm-1', via: 'email');

		$this->assertSame([Http::STATUS_OK, $outcome], [$response->getStatus(), $response->getData()]);
	}//end testASendAnswersTheOutcome()

	public function testAnotherAdministrationIsNotFoundAndNothingIsSent(): void {
		$this->sending->expects($this->never())->method('send');

		$response = $this->controller->send(id: 'inv-1', administrationId: 'adm-2');

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'ARInvoice not found']], [$response->getStatus(), $response->getData()]);
	}//end testAnotherAdministrationIsNotFoundAndNothingIsSent()

	public function testAMissingAdministrationOrAnUnknownChannelIsABadRequest(): void {
		$this->sending->expects($this->never())->method('send');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->send(id: 'inv-1', administrationId: '')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->send(id: 'inv-1', administrationId: '../adm')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->send(id: 'inv-1', administrationId: 'adm-1', via: 'fax')->getStatus());
	}//end testAMissingAdministrationOrAnUnknownChannelIsABadRequest()

	public function testAnInvoiceTheServiceDoesNotFindIsNotFound(): void {
		$this->sending->method('send')->willThrowException(new OutOfBoundsException('ARInvoice not found'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->send(id: 'inv-9', administrationId: 'adm-1')->getStatus());
	}//end testAnInvoiceTheServiceDoesNotFindIsNotFound()

	public function testADraftIsAConflictWithTheReason(): void {
		$this->sending->method('send')->willThrowException(new DomainException('Only an issued invoice can be sent.'));

		$response = $this->controller->send(id: 'inv-1', administrationId: 'adm-1');

		$this->assertSame([Http::STATUS_CONFLICT, ['error' => 'Only an issued invoice can be sent.']], [$response->getStatus(), $response->getData()]);
	}//end testADraftIsAConflictWithTheReason()

	public function testAnUnexpectedFailureIsAServerErrorWithoutItsDetail(): void {
		$this->sending->method('send')->willThrowException(new RuntimeException('database password wrong'));

		$response = $this->controller->send(id: 'inv-1', administrationId: 'adm-1');

		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'The invoice could not be sent.']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnexpectedFailureIsAServerErrorWithoutItsDetail()
}//end class
