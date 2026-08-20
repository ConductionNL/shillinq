<?php

/**
 * Unit tests for PortalPaymentSessionService (portal-payment-initiation).
 *
 * Pins the fail-closed ownership chain end-to-end with a MOCKED
 * PaymentProviderInterface (no test ever contacts a real PSP): the
 * customerMasterId claim resolution against portaliq's own portalAccount
 * register, the uniform not-authorised result for a foreign/non-payable/
 * non-existent/malformed target (no existence oracle), the client-supplied
 * amount being ignored (charged = server invoice amount), idempotent
 * PaymentRequest reuse, and honest degradation when the bound provider is
 * dormant.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payment
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
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Payment;

use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Payment\PaymentSessionRequest;
use OCA\Shillinq\Service\Payment\PaymentSessionResult;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Duck-typed ObjectService stub keyed by register + schema. Parameter names
 * MUST mirror OpenRegister's ObjectService (the service calls with named
 * arguments: `config:`, `_rbac:`, `_multitenancy:`, `object:`, `register:`,
 * `schema:`, `uuid:`).
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class PortalPaymentObjectServiceStub {
	/**
	 * Currently selected register.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * Currently selected schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Rows by register => schema.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	public array $data = [];

	/**
	 * Every saveObject() call, in order.
	 *
	 * @var array<int, array{register: mixed, schema: mixed, object: array<string, mixed>, uuid: string|null}>
	 */
	public array $saved = [];

	/**
	 * Make findAll() throw, simulating an OpenRegister/infra failure.
	 *
	 * @var bool
	 */
	public bool $throwOnFindAll = false;

	/**
	 * When > 0, findAll() throws starting from this call number (1-based) —
	 * lets a test succeed on the claim-resolution read and fail on a LATER
	 * read in the same chain.
	 *
	 * @var int
	 */
	public int $throwOnFindAllFromCall = 0;

	/**
	 * Running count of findAll() invocations.
	 *
	 * @var int
	 */
	public int $findAllCalls = 0;

	/**
	 * Make saveObject() throw.
	 *
	 * @var bool
	 */
	public bool $throwOnSaveObject = false;

	public function setRegister(string $register): static {
		$this->register = $register;
		return $this;
	}//end setRegister()

	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Query params (filters/limit).
	 * @param bool $_rbac Unused (mirrors OR's API).
	 * @param bool $_multitenancy Unused (mirrors OR's API).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->findAllCalls++;

		if ($this->throwOnFindAll === true) {
			throw new RuntimeException('OpenRegister unavailable');
		}

		if ($this->throwOnFindAllFromCall > 0 && $this->findAllCalls >= $this->throwOnFindAllFromCall) {
			throw new RuntimeException('OpenRegister read failed mid-chain');
		}

		$filters = ($config['filters'] ?? []);
		$rows = ($this->data[$this->register][$this->schema] ?? []);

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * @param array|object $object The object data.
	 * @param array|null $extend Unused.
	 * @param mixed $register Register slug.
	 * @param mixed $schema Schema slug.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array|object $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		if ($this->throwOnSaveObject === true) {
			throw new RuntimeException('OpenRegister write failed');
		}

		$arr = (array)$object;
		$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $arr, 'uuid' => $uuid];

		$result = $arr;
		$result['id'] = ($uuid ?? ('generated-' . count($this->saved)));

		return $result;
	}//end saveObject()
}//end class

/**
 * Tests for PortalPaymentSessionService.
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003, REQ-SPPI-004)
 */
final class PortalPaymentSessionServiceTest extends TestCase {
	private const SUBJECT_REF = '10000000-0000-0000-0000-000000000001';
	private const CUSTOMER_MASTER_ID = '20000000-0000-0000-0000-000000000002';
	private const OTHER_CUSTOMER_ID = '20000000-0000-0000-0000-00000000dead';
	private const INVOICE_ID = '30000000-0000-0000-0000-000000000003';

	/**
	 * The ObjectService stub.
	 *
	 * @var PortalPaymentObjectServiceStub
	 */
	private PortalPaymentObjectServiceStub $objectService;

	/**
	 * Mocked container resolving the stub.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mocked payment provider — no test ever contacts a real PSP.
	 *
	 * @var PaymentProviderInterface&MockObject
	 */
	private PaymentProviderInterface&MockObject $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new PortalPaymentObjectServiceStub();
		$this->objectService->data['portaliq']['portalAccount'] = [
			[
				'subjectRef' => self::SUBJECT_REF,
				'audience' => 'customer',
				'claims' => ['shillinq' => ['customerMasterId' => self::CUSTOMER_MASTER_ID]],
			],
		];
		$this->objectService->data['shillinq']['ARInvoice'] = [
			[
				'id' => self::INVOICE_ID,
				'customerId' => self::CUSTOMER_MASTER_ID,
				'state' => 'issued',
				'totalAmount' => 125.5,
				'currency' => 'EUR',
				'invoiceNumber' => 'INV-2026-0001',
				'administrationId' => 'adm-1',
			],
		];
		$this->objectService->data['shillinq']['PaymentRequest'] = [];

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objectService);

		$this->provider = $this->createMock(PaymentProviderInterface::class);
	}//end setUp()

	/**
	 * @param array<string, mixed> $overrides Claim overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function claims(array $overrides = []): array {
		return array_merge(
			[
				'sub' => self::SUBJECT_REF,
				'audience' => 'customer',
				'organisation' => '40000000-0000-0000-0000-000000000004',
				'trust' => 'low',
			],
			$overrides
		);
	}//end claims()

	/**
	 * @return PortalPaymentSessionService
	 */
	private function makeService(): PortalPaymentSessionService {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://instance.example/webhook');
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://instance.example/');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new PortalPaymentSessionService(
			container: $this->container,
			provider: $this->provider,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeService()

	/**
	 * Happy path: a live provider mints a checkout URL for the subject's own
	 * open invoice; the amount charged is the SERVER invoice amount
	 * (REQ-SPPI-004), and the paymentIntentId is persisted.
	 *
	 * @return void
	 */
	public function testHappyPathReturnsCheckoutUrl(): void {
		$this->provider->expects($this->once())
			->method('createSession')
			->with(
				$this->callback(
					static function (PaymentSessionRequest $request): bool {
						return $request->amount === 125.5
							&& $request->currency === 'EUR'
							&& $request->method === 'ideal';
					}
				)
			)
			->willReturn(
				new PaymentSessionResult(
					dormant: false,
					checkoutUrl: 'https://mollie.example/checkout/tr_1',
					paymentIntentId: 'tr_1',
				)
			);

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('ok', $result->status);
		self::assertSame('https://mollie.example/checkout/tr_1', $result->checkoutUrl);

		$paymentRequestSaves = array_values(array_filter($this->objectService->saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'));
		self::assertNotEmpty($paymentRequestSaves);
		$last = end($paymentRequestSaves);
		self::assertSame('tr_1', $last['object']['paymentIntentId']);
	}//end testHappyPathReturnsCheckoutUrl()

	/**
	 * A client-supplied amount is ignored entirely — the charged amount is
	 * ALWAYS the server invoice amount (REQ-SPPI-004). The service has no
	 * amount input from the request at all; this pins that guarantee at the
	 * type level (PaymentSessionRequest can only be built from server data).
	 *
	 * @return void
	 */
	public function testChargedAmountIsAlwaysServerInvoiceAmount(): void {
		$captured = null;
		$this->provider->method('createSession')
			->willReturnCallback(
				function (PaymentSessionRequest $request) use (&$captured): PaymentSessionResult {
					$captured = $request;
					return new PaymentSessionResult(dormant: false, checkoutUrl: 'https://mollie.example/x', paymentIntentId: 'tr_2');
				}
			);

		$this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertNotNull($captured);
		self::assertSame(125.5, $captured->amount, 'the invoice outstanding amount, never a client-supplied one');
	}//end testChargedAmountIsAlwaysServerInvoiceAmount()

	/**
	 * A repeat pay on an invoice with a still-pending PaymentRequest reuses
	 * it rather than minting a duplicate (REQ-SPPI-002 idempotent initiation).
	 *
	 * @return void
	 */
	public function testReusesExistingPendingPaymentRequest(): void {
		$this->objectService->data['shillinq']['PaymentRequest'] = [
			[
				'id' => 'existing-pr-1',
				'invoiceReference' => self::INVOICE_ID,
				'amount' => 125.5,
				'currency' => 'EUR',
				'paymentGateway' => 'mollie',
				'state' => 'pending',
			],
		];

		$this->provider->method('createSession')->willReturn(
			new PaymentSessionResult(dormant: false, checkoutUrl: 'https://mollie.example/x', paymentIntentId: 'tr_3')
		);

		$this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		// Only ONE PaymentRequest save happened — the paymentIntentId update
		// on the REUSED row, never a second minted request.
		$paymentRequestSaves = array_values(array_filter($this->objectService->saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'));
		self::assertCount(1, $paymentRequestSaves);
		self::assertSame('existing-pr-1', $paymentRequestSaves[0]['uuid']);
	}//end testReusesExistingPendingPaymentRequest()

	/**
	 * A debtor cannot pay an invoice belonging to a DIFFERENT CustomerMaster —
	 * uniform forbidden, PSP never called (REQ-SPPI-003).
	 *
	 * @return void
	 */
	public function testForeignInvoiceIsForbiddenAndPspNeverCalled(): void {
		$this->objectService->data['shillinq']['ARInvoice'][0]['customerId'] = self::OTHER_CUSTOMER_ID;
		$this->provider->expects($this->never())->method('createSession');

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('forbidden', $result->status);
		self::assertEmpty(array_filter($this->objectService->saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'));
	}//end testForeignInvoiceIsForbiddenAndPspNeverCalled()

	/**
	 * A settled/non-payable invoice yields the SAME forbidden result as a
	 * foreign one — no existence oracle (REQ-SPPI-003).
	 *
	 * @return void
	 */
	public function testNonPayableInvoiceIsForbidden(): void {
		$this->objectService->data['shillinq']['ARInvoice'][0]['state'] = 'paid';
		$this->provider->expects($this->never())->method('createSession');

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('forbidden', $result->status);
	}//end testNonPayableInvoiceIsForbidden()

	/**
	 * A non-existent invoice id yields the SAME forbidden result (REQ-SPPI-003).
	 *
	 * @return void
	 */
	public function testNonExistentInvoiceIsForbidden(): void {
		$this->provider->expects($this->never())->method('createSession');

		$result = $this->makeService()->initiate(claims: $this->claims(), target: 'ffffffff-ffff-ffff-ffff-ffffffffffff');

		self::assertSame('forbidden', $result->status);
	}//end testNonExistentInvoiceIsForbidden()

	/**
	 * A URL/path-shaped target is rejected before ANY OpenRegister read
	 * (SSRF hardening, REQ-SPPI-003) — the container is never even touched.
	 *
	 * @return void
	 */
	public function testUrlShapedTargetIsRejectedBeforeAnyLookup(): void {
		$this->container->expects($this->never())->method('get');
		$this->provider->expects($this->never())->method('createSession');

		foreach (['https://evil.example/x', '/etc/passwd', '../../secret', ''] as $badTarget) {
			$result = $this->makeService()->initiate(claims: $this->claims(), target: $badTarget);
			self::assertSame('forbidden', $result->status, $badTarget);
		}
	}//end testUrlShapedTargetIsRejectedBeforeAnyLookup()

	/**
	 * A non-customer audience is refused before any lookup.
	 *
	 * @return void
	 */
	public function testNonCustomerAudienceIsForbidden(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->makeService()->initiate(claims: $this->claims(['audience' => 'supplier']), target: self::INVOICE_ID);

		self::assertSame('forbidden', $result->status);
	}//end testNonCustomerAudienceIsForbidden()

	/**
	 * No portalAccount row (or no resolvable customerMasterId claim) fails
	 * closed — the receiver cannot derive ownership (design.md Open Q1).
	 *
	 * @return void
	 */
	public function testUnresolvableCustomerMasterIdIsForbidden(): void {
		$this->objectService->data['portaliq']['portalAccount'] = [];
		$this->provider->expects($this->never())->method('createSession');

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('forbidden', $result->status);
	}//end testUnresolvableCustomerMasterIdIsForbidden()

	/**
	 * The bound provider is dormant — no checkout URL is fabricated; the
	 * endpoint degrades honestly (REQ-SPPI-001).
	 *
	 * @return void
	 */
	public function testDormantProviderYieldsDeferred(): void {
		$this->provider->method('createSession')->willReturn(
			new PaymentSessionResult(dormant: true, checkoutUrl: '', paymentIntentId: 'tr_log_deferred')
		);

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('deferred', $result->status);
		self::assertNull($result->checkoutUrl);
	}//end testDormantProviderYieldsDeferred()

	/**
	 * OpenRegister being entirely unavailable (container throws) is a
	 * downstream error, distinct from the uniform forbidden result.
	 *
	 * @return void
	 */
	public function testObjectServiceUnavailableIsDownstreamError(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));

		$service = new PortalPaymentSessionService(
			container: $container,
			provider: $this->provider,
			urlGenerator: $this->createMock(IURLGenerator::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $service->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('downstream_error', $result->status);
	}//end testObjectServiceUnavailableIsDownstreamError()

	/**
	 * An OpenRegister read failure mid-chain (ARInvoice lookup throws) is a
	 * downstream error — the PSP is never called.
	 *
	 * @return void
	 */
	public function testInvoiceLookupFailureIsDownstreamError(): void {
		// Call 1 = the portalAccount claim resolution (must succeed so the
		// failure is pinned specifically to the LATER ARInvoice read).
		$this->objectService->throwOnFindAllFromCall = 2;

		$this->provider->expects($this->never())->method('createSession');

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('downstream_error', $result->status);
	}//end testInvoiceLookupFailureIsDownstreamError()

	/**
	 * A PSP call failure is a downstream error, never leaked to the caller.
	 *
	 * @return void
	 */
	public function testProviderFailureIsDownstreamError(): void {
		$this->provider->method('createSession')->willThrowException(new RuntimeException('mollie unreachable'));

		$result = $this->makeService()->initiate(claims: $this->claims(), target: self::INVOICE_ID);

		self::assertSame('downstream_error', $result->status);
	}//end testProviderFailureIsDownstreamError()
}//end class
