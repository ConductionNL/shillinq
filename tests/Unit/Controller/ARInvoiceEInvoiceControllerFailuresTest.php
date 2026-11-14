<?php

/**
 * Unit tests for how ARInvoiceEInvoiceController answers a failing send.
 *
 * A RuntimeException from the e-invoice service carries a user-facing reason
 * (404 when it names a missing invoice, else 400); anything else is a logged
 * 500 that never echoes the internal message.
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
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ARInvoiceEInvoiceController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Failure mapping of ARInvoiceEInvoiceController::send().
 */
final class ARInvoiceEInvoiceControllerFailuresTest extends TestCase {

	/**
	 * E-invoice service mock.
	 *
	 * @var EInvoiceServiceInterface&MockObject
	 */
	private EInvoiceServiceInterface&MockObject $service;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var ARInvoiceEInvoiceController
	 */
	private ARInvoiceEInvoiceController $controller;

	/**
	 * Set up a signed-in member of adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'administrationId' ? ' adm-1 ' : $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		$this->service = $this->createMock(EInvoiceServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$this->controller = new ARInvoiceEInvoiceController(
			$request,
			$this->service,
			$session,
			$context,
			$this->logger,
		);

	}//end setUp()

	/**
	 * A RuntimeException naming a missing invoice is a 404 with its message.
	 *
	 * @return void
	 */
	public function testAMissingInvoiceIsA404(): void {
		$this->service->expects(self::once())->method('sendEInvoice')
			->with(administrationId: 'adm-1', invoiceNumber: '2026-0042')
			->willThrowException(new \RuntimeException('ARInvoice 2026-0042 not found'));

		$response = $this->controller->send('2026-0042');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'ARInvoice 2026-0042 not found'], $response->getData());

	}//end testAMissingInvoiceIsA404()

	/**
	 * Any other RuntimeException is a 400 with its reason.
	 *
	 * @return void
	 */
	public function testAValidationFailureIsA400(): void {
		$this->service->method('sendEInvoice')
			->willThrowException(new \RuntimeException('Only an issued invoice can be sent'));

		$response = $this->controller->send('2026-0042');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Only an issued invoice can be sent'], $response->getData());

	}//end testAValidationFailureIsA400()

	/**
	 * Any other failure is a logged 500 that does not echo the internal message.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsALogged500(): void {
		$this->service->method('sendEInvoice')->willThrowException(new \LogicException('internal state broken'));
		$this->logger->expects(self::once())->method('error')->with(
			'ARInvoiceEInvoiceController: send-einvoice failed',
			['invoiceNumber' => '2026-0042', 'administrationId' => 'adm-1', 'exception' => 'internal state broken']
		);

		$response = $this->controller->send('2026-0042');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not send e-invoice'], $response->getData());

	}//end testAnUnexpectedFailureIsALogged500()

	/**
	 * A successful send returns the service result as-is with 200.
	 *
	 * @return void
	 */
	public function testASuccessfulSendReturnsTheResult(): void {
		$result = ['deliveryStatus' => 'sent', 'transmissionId' => 'tx-1', 'fallback' => false, 'warnings' => []];
		$this->service->method('sendEInvoice')->willReturn($result);

		$response = $this->controller->send('2026-0042');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());

	}//end testASuccessfulSendReturnsTheResult()
}//end class
