<?php

/**
 * Characterisation tests for the outcomes of InvoiceApiController.
 *
 * Pins the success bodies, the error mapping of each action and the queries
 * each read action issues, next to the access cases InvoiceApiControllerTest
 * already pins.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\InvoiceApiController;
use OCA\Shillinq\Kernel\Exception\UnratedHoursException;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BillableLinesService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins InvoiceApiController's outcomes.
 */
final class InvoiceApiControllerOutcomesTest extends TestCase {

	/**
	 * Request stub.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Generation service mock.
	 *
	 * @var InvoiceGenerationService&MockObject
	 */
	private InvoiceGenerationService&MockObject $service;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Billable lines mock.
	 *
	 * @var BillableLinesService&MockObject
	 */
	private BillableLinesService&MockObject $billableLines;

	/**
	 * Every schema the object service was scoped to.
	 *
	 * @var array<int,string>
	 */
	private array $schemas = [];

	/**
	 * Controller under test.
	 *
	 * @var InvoiceApiController
	 */
	private InvoiceApiController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(InvoiceGenerationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->billableLines = $this->createMock(BillableLinesService::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnCallback(
			function (string $schema): ObjectServiceInterface {
				$this->schemas[] = $schema;
				return $this->objectService;
			}
		);

		$this->controller = new InvoiceApiController(
			$this->request,
			$this->service,
			$this->createMock(InvoicePdfGenerator::class),
			$session,
			$context,
			$this->logger,
			$this->objectService,
			$this->billableLines,
		);

	}//end setUp()

	/**
	 * A valid generation request.
	 *
	 * @return void
	 */
	private function validGeneration(): void {
		$this->request->method('getParams')->willReturn(
			[
				'billingModel' => 't_and_m',
				'customerId' => 'cust-1',
				'fromDate' => '2026-09-01',
				'toDate' => '2026-09-30',
				'rateCardId' => 'rc-1',
				'timeEntryIds' => [7, 't-2'],
				'expenseIds' => [],
			]
		);
		$this->billableLines->method('foreignLineIds')->willReturn([]);

	}//end validGeneration()

	/**
	 * A drafted invoice is returned as is, after the line ownership check.
	 *
	 * @return void
	 */
	public function testGenerateReturnsTheDraft(): void {
		$this->validGeneration();
		$this->billableLines->expects(self::once())->method('foreignLineIds')
			->with('adm-1', 'cust-1', ['7', 't-2'], []);
		$this->service->expects(self::once())->method('draftInvoice')->willReturn(['id' => 'inv-9']);

		$response = $this->controller->generate();

		self::assertSame([200, ['id' => 'inv-9']], [$response->getStatus(), $response->getData()]);

	}//end testGenerateReturnsTheDraft()

	/**
	 * Draft failures and the response they map to.
	 *
	 * @return array<string,array{0:\Throwable,1:int,2:array<string,string>,3:string}>
	 */
	public static function draftFailures(): array {
		return [
			'conflict' => [
				new \RuntimeException('Conflict: t-2 invoiced'),
				409,
				['message' => 'One or more time entries, expenses or meter readings are already invoiced.', 'error' => 'invoice-generate-conflict'],
				'InvoiceApiController.generate: request could not be completed: Conflict: t-2 invoiced',
			],
			'other runtime' => [
				new \RuntimeException('no rate'),
				400,
				['message' => 'Unable to generate the invoice.', 'error' => 'invoice-generate-failed'],
				'InvoiceApiController.generate: request could not be completed: no rate',
			],
			'invalid' => [
				new \InvalidArgumentException('bad'),
				422,
				['message' => 'The invoice generation request is invalid.', 'error' => 'invoice-generate-invalid'],
				'InvoiceApiController.generate: invalid request: bad',
			],
			'error' => [
				new \Error('boom'),
				500,
				['error' => 'Internal error'],
				'InvoiceApiController.generate failed: boom',
			],
		];

	}//end draftFailures()

	/**
	 * A failing draft is logged and mapped to its response.
	 *
	 * @param \Throwable $failure The failure.
	 * @param int $status The expected status.
	 * @param array<string,string> $data The expected body.
	 * @param string $logged The expected log line.
	 *
	 * @return void
	 *
	 * @dataProvider draftFailures
	 */
	public function testGenerateFailureMapping(\Throwable $failure, int $status, array $data, string $logged): void {
		$this->validGeneration();
		$this->service->method('draftInvoice')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with($logged);

		$response = $this->controller->generate();

		self::assertSame([$status, $data], [$response->getStatus(), $response->getData()]);

	}//end testGenerateFailureMapping()

	/**
	 * A draft holding an hour without a rate is refused with 422, naming the
	 * person and the date (REQ-BRPH-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	public function testAnUnratedHourIsRefusedWith422(): void {
		$this->validGeneration();
		$hours = [['person' => 'Dana', 'date' => '2026-10-14', 'reason' => 'Dana is not assigned to this project']];
		$this->service->method('draftInvoice')->willThrowException(new UnratedHoursException(hours: $hours));
		$this->logger->expects(self::never())->method('error');

		$response = $this->controller->generate();

		self::assertSame(
			[
				422,
				['message' => 'No rate for Dana on 14 October 2026.', 'error' => 'invoice-generate-unrated-hours', 'hours' => $hours],
			],
			[$response->getStatus(), $response->getData()]
		);

	}//end testAnUnratedHourIsRefusedWith422()

	/**
	 * Index filters on the administration and the optional model and status.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>}>
	 */
	public static function indexFilters(): array {
		return [
			'none' => [[], ['administrationId' => 'adm-1']],
			'model' => [['billingModel' => 'fixed'], ['administrationId' => 'adm-1', 'billingModel' => 'fixed']],
			'both' => [['billingModel' => 'fixed', 'status' => 'draft'], ['administrationId' => 'adm-1', 'billingModel' => 'fixed', 'status' => 'draft']],
			'status' => [['status' => 'posted'], ['administrationId' => 'adm-1', 'status' => 'posted']],
		];

	}//end indexFilters()

	/**
	 * Index queries the BillableInvoice schema with the filters.
	 *
	 * @param array<string,string> $params The query parameters.
	 * @param array<string,string> $filters The expected filters.
	 *
	 * @return void
	 *
	 * @dataProvider indexFilters
	 */
	public function testIndexFilters(array $params, array $filters): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => $filters])->willReturn([['id' => 'i']]);

		$response = $this->controller->index();

		self::assertSame([200, [['id' => 'i']]], [$response->getStatus(), $response->getData()]);
		self::assertSame(['BillableInvoice'], $this->schemas);

	}//end testIndexFilters()

	/**
	 * A failing index is a logged 500.
	 *
	 * @return void
	 */
	public function testIndexFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('db'));
		$this->logger->expects(self::once())->method('error')->with('InvoiceApiController.index failed: db');

		$response = $this->controller->index();

		self::assertSame([500, ['error' => 'Internal error']], [$response->getStatus(), $response->getData()]);

	}//end testIndexFailure()

	/**
	 * Serve an own invoice from find().
	 *
	 * @return array<string,mixed>
	 */
	private function ownInvoice(): array {
		$invoice = ['id' => 'inv-1', 'administrationId' => 'adm-1'];
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($invoice);
		$this->objectService->method('find')->willReturn($entity);
		return $invoice;

	}//end ownInvoice()

	/**
	 * Show returns the invoice, its lines and an empty audit trail.
	 *
	 * @return void
	 */
	public function testShowBody(): void {
		$invoice = $this->ownInvoice();
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => ['invoiceId' => 'inv-1']])->willReturn([['line' => 1]]);

		$response = $this->controller->show('inv-1');

		self::assertSame(
			[200, ['invoice' => $invoice, 'lines' => [['line' => 1]], 'auditTrail' => []]],
			[$response->getStatus(), $response->getData()]
		);
		self::assertSame(['BillableInvoice', 'BillableInvoiceLine'], $this->schemas);

	}//end testShowBody()

	/**
	 * A failing show is a logged 500.
	 *
	 * @return void
	 */
	public function testShowFailure(): void {
		$this->ownInvoice();
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('db'));
		$this->logger->expects(self::once())->method('error')->with('InvoiceApiController.show failed: db');

		$response = $this->controller->show('inv-1');

		self::assertSame([500, ['error' => 'Internal error']], [$response->getStatus(), $response->getData()]);

	}//end testShowFailure()

	/**
	 * Post failures and the response they map to.
	 *
	 * @return array<string,array{0:\Throwable,1:int,2:array<string,string>,3:string}>
	 */
	public static function postFailures(): array {
		return [
			'other runtime' => [
				new \RuntimeException('locked period'),
				400,
				['message' => 'Unable to post the invoice.', 'error' => 'invoice-post-failed'],
				'InvoiceApiController.post: request could not be completed: locked period',
			],
			'already posted' => [
				new \RuntimeException('Invoice already posted'),
				409,
				['message' => 'The invoice is already posted.', 'error' => 'invoice-post-conflict'],
				'InvoiceApiController.post: request could not be completed: Invoice already posted',
			],
			'error' => [
				new \Error('boom'),
				500,
				['error' => 'Internal error'],
				'InvoiceApiController.post failed: boom',
			],
		];

	}//end postFailures()

	/**
	 * A failing post is logged and mapped to its response.
	 *
	 * @param \Throwable $failure The failure.
	 * @param int $status The expected status.
	 * @param array<string,string> $data The expected body.
	 * @param string $logged The expected log line.
	 *
	 * @return void
	 *
	 * @dataProvider postFailures
	 */
	public function testPostFailureMapping(\Throwable $failure, int $status, array $data, string $logged): void {
		$this->ownInvoice();
		$this->service->method('postInvoice')->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with($logged);

		$response = $this->controller->post('inv-1');

		self::assertSame([$status, $data], [$response->getStatus(), $response->getData()]);

	}//end testPostFailureMapping()

	/**
	 * A failing lookup while resolving the invoice is a logged 500 for pdf.
	 *
	 * @return void
	 */
	public function testPdfLookupFailure(): void {
		$this->objectService->method('find')->willThrowException(new \Error('db'));
		$this->logger->expects(self::once())->method('error')->with('InvoiceApiController.pdf failed: db');

		$response = $this->controller->pdf('inv-1');

		self::assertSame([500, ['error' => 'Internal error']], [$response->getStatus(), $response->getData()]);

	}//end testPdfLookupFailure()
}//end class
