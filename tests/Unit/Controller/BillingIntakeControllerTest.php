<?php

/**
 * Unit tests for BillingIntakeController.
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
 * @spec openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\BillingIntakeController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the anon-401 path, server-resolved administration id + personId
 * (ADR-005, ignoring any client-supplied administrationId), and the
 * 400/409/422/500 exception-to-status mapping.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BillingIntakeControllerTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var TimeIntakeService&MockObject
	 */
	private TimeIntakeService&MockObject $service;

	/**
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * @var BillingIntakeController
	 */
	private BillingIntakeController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TimeIntakeService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => $text);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->administrationContext->method('buildContext')->willReturn(
			['userId' => 'alice', 'administrations' => [], 'activeAdministrationId' => 'adm-1']
		);

		$this->request->method('getParams')->willReturn([]);

		$this->controller = new BillingIntakeController(
			request: $this->request,
			service: $this->service,
			administrationContext: $this->administrationContext,
			session: $this->session,
			logger: $this->logger,
			l10n: $this->l10n,
		);

	}//end setUp()

	/**
	 * An anonymous request returns 401 and never calls the service.
	 *
	 * @return void
	 */
	public function testAnonymousReturns401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new BillingIntakeController(
			request: $this->request,
			service: $this->service,
			administrationContext: $this->administrationContext,
			session: $session,
			logger: $this->logger,
			l10n: $this->l10n,
		);

		$this->service->expects(self::never())->method('ingest');

		$response = $controller->timeIntake();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousReturns401()

	/**
	 * A valid batch delegates to the service with the SERVER-resolved
	 * administrationId (ADR-005) — a client-supplied administrationId in the
	 * body is ignored — and returns the service's response verbatim.
	 *
	 * @return void
	 */
	public function testValidBatchDelegatesAndReturns200(): void {
		$this->service->expects(self::once())
			->method('ingest')
			->with('adm-1', 'alice', self::isType('array'))
			->willReturn(['invoiceId' => 'inv-1', 'invoiceNumber' => 'BIL-2026-0001', 'status' => 'draft', 'lines' => 2, 'duplicated' => false]);

		$body = json_encode(['batchId' => 'B1', 'administrationId' => 'adm-999', 'entries' => []]);
		self::assertIsString($body);
		$this->withRawBody($body);

		$response = $this->controller->timeIntake();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame('inv-1', $data['invoiceId']);
		self::assertFalse($data['duplicated']);

	}//end testValidBatchDelegatesAndReturns200()

	/**
	 * An InvalidArgumentException from the service maps to HTTP 400.
	 *
	 * @return void
	 */
	public function testInvalidArgumentMapsTo400(): void {
		$this->service->method('ingest')->willThrowException(new InvalidArgumentException('batchId is required.'));

		$response = $this->controller->timeIntake();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testInvalidArgumentMapsTo400()

	/**
	 * A "Conflict: ..." RuntimeException maps to HTTP 409.
	 *
	 * @return void
	 */
	public function testConflictRuntimeExceptionMapsTo409(): void {
		$this->service->method('ingest')->willThrowException(new RuntimeException('Conflict: batchId already used.'));

		$response = $this->controller->timeIntake();
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testConflictRuntimeExceptionMapsTo409()

	/**
	 * Any other RuntimeException maps to HTTP 422.
	 *
	 * @return void
	 */
	public function testOtherRuntimeExceptionMapsTo422(): void {
		$this->service->method('ingest')->willThrowException(new RuntimeException('billingModel must be t_and_m.'));

		$response = $this->controller->timeIntake();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testOtherRuntimeExceptionMapsTo422()

	/**
	 * An unexpected Throwable maps to HTTP 500 with a generic message (no
	 * internal detail leakage) — ADR-050: a stable kebab-case slug and a
	 * localized message, never the raw exception text ('boom').
	 *
	 * @return void
	 */
	public function testUnexpectedThrowableMapsTo500(): void {
		$this->service->method('ingest')->willThrowException(new \Error('boom'));

		$response = $this->controller->timeIntake();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('billing-time-intake-failed', $response->getData()['error']);
		self::assertStringNotContainsString('boom', (string)$response->getData()['message']);

	}//end testUnexpectedThrowableMapsTo500()

	/**
	 * Configure the request mock to deliver a raw php://input-style body.
	 *
	 * decodeBody() reads php://input directly, which cannot be mocked via
	 * IRequest — instead we rely on the getParams() fallback path by
	 * decoding the JSON ourselves and feeding it through getParams().
	 *
	 * @param string $json JSON-encoded body.
	 *
	 * @return void
	 */
	private function withRawBody(string $json): void {
		$decoded = json_decode($json, true);
		self::assertIsArray($decoded);
		$this->request->method('getParams')->willReturn($decoded);

	}//end withRawBody()
}//end class
