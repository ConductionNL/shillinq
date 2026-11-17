<?php

/**
 * Tests for InvoiceBatchController (sales-invoice-sending task 3.3).
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
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\InvoiceBatchController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceBatchService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The administration is checked first; the service's refusals become 400 and 404.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-batch-creates-one-invoice-per-customer-req-sis-004
 */
#[CoversClass(InvoiceBatchController::class)]
class InvoiceBatchControllerTest extends TestCase {

	private InvoiceBatchService&MockObject $batches;

	private InvoiceBatchController $controller;

	protected function setUp(): void {
		$this->batches = $this->createMock(InvoiceBatchService::class);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bookkeeper');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new InvoiceBatchController(
			request: $this->createMock(IRequest::class),
			batches: $this->batches,
			context: $context,
			session: $session,
			logger: new NullLogger()
		);
	}//end setUp()

	public function testABatchIsCreatedForTheSignedInUser(): void {
		$charge = ['invoiceDate' => '2026-10-01', 'lines' => [['description' => 'Onderhoud', 'unitPrice' => 250, 'vatRate' => 21]]];
		$batch = ['id' => 'b-1', 'batchId' => 'BATCH-1', 'kind' => 'create', 'state' => 'done', 'outcomes' => []];
		$this->batches->expects($this->once())->method('create')
			->with(
				'adm-1',
				['batchId' => 'BATCH-1', 'customerIds' => ['c-1', 'c-2'], 'charge' => $charge, 'issue' => true, 'send' => true],
				'bookkeeper'
			)
			->willReturn($batch);

		$response = $this->controller->create(administrationId: ' adm-1 ', batchId: 'BATCH-1', customerIds: ['c-1', 'c-2'], charge: $charge, issue: true, send: true);

		$this->assertSame([Http::STATUS_CREATED, $batch], [$response->getStatus(), $response->getData()]);
	}//end testABatchIsCreatedForTheSignedInUser()

	public function testAnotherAdministrationIsNotFoundAndNothingIsWritten(): void {
		$this->batches->expects($this->never())->method('create');

		$response = $this->controller->create(administrationId: 'adm-2', customerIds: ['c-1']);

		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Administration not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(administrationId: '../adm')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(administrationId: '')->getStatus());
	}//end testAnotherAdministrationIsNotFoundAndNothingIsWritten()

	public function testARefusedBatchIsABadRequestWithTheReason(): void {
		$this->batches->method('create')->willThrowException(new InvalidArgumentException('Select at least one customer.'));

		$response = $this->controller->create(administrationId: 'adm-1');

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'Select at least one customer.']], [$response->getStatus(), $response->getData()]);
	}//end testARefusedBatchIsABadRequestWithTheReason()

	public function testAServiceRefusalOfTheAdministrationIsNotFound(): void {
		$this->batches->method('create')->willThrowException(new OutOfBoundsException('Administration not found'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->create(administrationId: 'adm-1')->getStatus());
	}//end testAServiceRefusalOfTheAdministrationIsNotFound()

	public function testAnUnexpectedFailureIsAServerErrorWithoutItsDetail(): void {
		$this->batches->method('create')->willThrowException(new RuntimeException('database password wrong'));

		$response = $this->controller->create(administrationId: 'adm-1');

		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'The batch could not be created.']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnexpectedFailureIsAServerErrorWithoutItsDetail()
}//end class
