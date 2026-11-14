<?php

/**
 * Unit tests for the signed pay link path of PortalPaymentSessionService
 * (receivables-payment-links task 3.1) and the checkout link a portal pay
 * stores on its request (task 1.3).
 *
 * The provider is a mock (no test contacts a payment provider); the pay
 * tokens are real ones signed by PayLinkService over a test instance secret.
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
 *
 * @covers \OCA\Shillinq\Service\Payment\PortalPaymentSessionService
 * @uses   \OCA\Shillinq\Service\Payment\PayLinkService
 * @uses   \OCA\Shillinq\Service\Payment\PaymentSessionRequest
 * @uses   \OCA\Shillinq\Service\Payment\PaymentSessionResult
 * @uses   \OCA\Shillinq\Service\Payment\PaymentTargetLookup
 * @uses   \OCA\Shillinq\Service\Payment\PortalPaymentTarget
 * @uses   \OCA\Shillinq\Kernel\Model\PortalPaymentSessionResult
 * @uses   \OCA\Shillinq\Portal\PortalSubjectResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Payment;

use OCA\Shillinq\Service\Payment\PayLinkService;
use OCA\Shillinq\Service\Payment\PaymentProviderInterface;
use OCA\Shillinq\Service\Payment\PaymentSessionRequest;
use OCA\Shillinq\Service\Payment\PaymentSessionResult;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duck-typed ObjectService over in-memory rows. Parameter names mirror
 * OpenRegister's ObjectService, which the service calls with named arguments.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class PayLinkObjectServiceStub {

	/**
	 * Selected register.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * Selected schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Rows by register and schema.
	 *
	 * @var array<string, array<string, list<array<string, mixed>>>>
	 */
	public array $data = [];

	/**
	 * Every saveObject() call.
	 *
	 * @var list<array{schema: mixed, object: array<string, mixed>, uuid: string|null}>
	 */
	public array $saved = [];

	public function setRegister(string $register): static {
		$this->register = $register;
		return $this;
	}//end setRegister()

	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Filters and limit.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters = ($config['filters'] ?? []);
		$rows    = ($this->data[$this->register][$this->schema] ?? []);

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
	 * @param int|string $id The uuid.
	 * @param array|null $_extend Unused.
	 * @param bool $files Unused.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
		foreach (($this->data[(string)($register ?? $this->register)][(string)($schema ?? $this->schema)] ?? []) as $row) {
			if (($row['id'] ?? null) === (string)$id) {
				return $row;
			}
		}

		throw new DoesNotExistException('not found');
	}//end find()

	/**
	 * @param array|object $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array|object $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$row           = (array)$object;
		$this->saved[] = ['schema' => $schema, 'object' => $row, 'uuid' => $uuid];
		$row['id']     = ($uuid ?? ('generated-' . count($this->saved)));

		return $row;
	}//end saveObject()
}//end class

/**
 * Tests for the pay link path and the stored checkout link.
 */
final class PortalPaymentPayLinkTest extends TestCase {

	private const INVOICE_ID = 'inv-2026-0412';

	private const ADMINISTRATION = 'adm-kade';

	/**
	 * The ObjectService stub.
	 *
	 * @var PayLinkObjectServiceStub
	 */
	private PayLinkObjectServiceStub $objectService;

	/**
	 * The mocked provider.
	 *
	 * @var PaymentProviderInterface&MockObject
	 */
	private PaymentProviderInterface&MockObject $provider;

	/**
	 * The pay link service over a test secret.
	 *
	 * @var PayLinkService
	 */
	private PayLinkService $payLinks;

	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new PayLinkObjectServiceStub();
		$this->objectService->data['shillinq']['ARInvoice'] = [
			[
				'id' => self::INVOICE_ID,
				'customerId' => 'cust-1',
				'lifecycleState' => 'issued',
				'grossAmount' => 302.5,
				'currency' => 'EUR',
				'invoiceNumber' => '2026-0412',
				'administrationId' => self::ADMINISTRATION,
			],
		];
		$this->objectService->data['shillinq']['PaymentRequest'] = [];
		$this->objectService->data['portaliq']['portalAccount'] = [
			['subjectRef' => 'sub-1', 'audience' => 'customer', 'claims' => ['shillinq' => ['customerMasterId' => 'cust-1']]],
		];

		$this->provider = $this->createMock(PaymentProviderInterface::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'secret' ? 'instance-secret-of-test' : $default);
		$linkConfig = $this->createMock(IAppConfig::class);
		$linkConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$this->payLinks = new PayLinkService(config: $config, appConfig: $linkConfig);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param bool $withPayLinks Whether the pay link service is wired.
	 *
	 * @return PortalPaymentSessionService
	 */
	private function service(bool $withPayLinks = true): PortalPaymentSessionService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($this->objectService);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://instance.example/webhook');
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://instance.example/');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$payLinks = null;
		if ($withPayLinks === true) {
			$payLinks = $this->payLinks;
		}

		return new PortalPaymentSessionService(
			container: $container,
			provider: $this->provider,
			urlGenerator: $urlGenerator,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			payLinks: $payLinks,
		);
	}//end service()

	/**
	 * A live session for the provider mock to answer.
	 *
	 * @param string $id The provider payment id.
	 *
	 * @return PaymentSessionResult
	 */
	private static function live(string $id): PaymentSessionResult {
		return new PaymentSessionResult(dormant: false, checkoutUrl: 'https://www.mollie.com/checkout/' . $id, paymentIntentId: $id);
	}//end live()

	/**
	 * The last PaymentRequest the service saved.
	 *
	 * @return array<string, mixed>
	 */
	private function lastSavedRequest(): array {
		$saves = array_values(array_filter($this->objectService->saved, static fn (array $save): bool => $save['schema'] === 'PaymentRequest'));
		self::assertNotEmpty($saves);
		return end($saves)['object'];
	}//end lastSavedRequest()

	/**
	 * A valid token opens a checkout for the invoice amount and stores the link on the request.
	 *
	 * @return void
	 */
	public function testAValidTokenOpensACheckoutAndStoresTheLink(): void {
		$this->provider->expects($this->once())->method('createSession')
			->with($this->callback(static fn (PaymentSessionRequest $request): bool => $request->amount === 302.5 && $request->description === 'Invoice 2026-0412' && $request->metadata['invoiceId'] === self::INVOICE_ID))
			->willReturn(self::live(id: 'tr_example0412'));

		$before = time();
		$result = $this->service()->initiateForPayToken(token: (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID));

		self::assertSame('ok', $result->status);
		self::assertSame('https://www.mollie.com/checkout/tr_example0412', $result->checkoutUrl);
		$saved = $this->lastSavedRequest();
		self::assertSame('tr_example0412', $saved['paymentIntentId']);
		self::assertSame('tr_example0412', $saved['providerPaymentId']);
		self::assertSame('https://www.mollie.com/checkout/tr_example0412', $saved['paymentLink']);
		self::assertSame(self::INVOICE_ID, $saved['invoiceReference']);
		$expires = strtotime($saved['checkoutExpiresAt']);
		self::assertGreaterThanOrEqual($before + 900, $expires);
		self::assertLessThanOrEqual(time() + 900, $expires);
	}//end testAValidTokenOpensACheckoutAndStoresTheLink()

	/**
	 * A token with a changed character opens nothing.
	 *
	 * @return void
	 */
	public function testATamperedTokenIsForbidden(): void {
		$this->provider->expects($this->never())->method('createSession');
		$token    = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);
		$last     = substr($token, -1);
		$tampered = substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');

		self::assertSame('forbidden', $this->service()->initiateForPayToken(token: $tampered)->status);
		self::assertSame([], $this->objectService->saved);
	}//end testATamperedTokenIsForbidden()

	/**
	 * A token signed for another administration does not open this administration's invoice.
	 *
	 * @return void
	 */
	public function testATokenOfAnotherAdministrationIsForbidden(): void {
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: 'adm-other', invoiceId: self::INVOICE_ID);

		self::assertSame('forbidden', $this->service()->initiateForPayToken(token: $token)->status);
		self::assertSame([], $this->objectService->saved);
	}//end testATokenOfAnotherAdministrationIsForbidden()

	/**
	 * A token for an invoice that does not exist gets the same forbidden.
	 *
	 * @return void
	 */
	public function testATokenForAMissingInvoiceIsForbidden(): void {
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: 'inv-gone');

		self::assertSame('forbidden', $this->service()->initiateForPayToken(token: $token)->status);
	}//end testATokenForAMissingInvoiceIsForbidden()

	/**
	 * A paid invoice says so and opens no checkout.
	 *
	 * @return void
	 */
	public function testAPaidInvoiceAnswersPaid(): void {
		$this->objectService->data['shillinq']['ARInvoice'][0]['lifecycleState'] = 'paid';
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		$result = $this->service()->initiateForPayToken(token: $token);

		self::assertSame('paid', $result->status);
		self::assertNull($result->checkoutUrl);
		self::assertSame([], $this->objectService->saved);
	}//end testAPaidInvoiceAnswersPaid()

	/**
	 * A written-off invoice also opens no checkout.
	 *
	 * @return void
	 */
	public function testAWrittenOffInvoiceAnswersPaid(): void {
		$this->objectService->data['shillinq']['ARInvoice'][0]['lifecycleState'] = 'written-off';
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		self::assertSame('paid', $this->service()->initiateForPayToken(token: $token)->status);
	}//end testAWrittenOffInvoiceAnswersPaid()

	/**
	 * A draft invoice is not payable from a link.
	 *
	 * @return void
	 */
	public function testADraftInvoiceIsForbidden(): void {
		$this->objectService->data['shillinq']['ARInvoice'][0]['lifecycleState'] = 'draft';
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		self::assertSame('forbidden', $this->service()->initiateForPayToken(token: $token)->status);
	}//end testADraftInvoiceIsForbidden()

	/**
	 * A pending request whose checkout has not expired is reused without asking the provider again.
	 *
	 * @return void
	 */
	public function testAnUnexpiredCheckoutIsReused(): void {
		$this->objectService->data['shillinq']['PaymentRequest'][] = [
			'id' => 'pr-1',
			'invoiceReference' => self::INVOICE_ID,
			'state' => 'pending',
			'amount' => 302.5,
			'paymentLink' => 'https://www.mollie.com/checkout/tr_live',
			'checkoutExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
		];
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		$result = $this->service()->initiateForPayToken(token: $token);

		self::assertSame('ok', $result->status);
		self::assertSame('https://www.mollie.com/checkout/tr_live', $result->checkoutUrl);
		self::assertSame([], $this->objectService->saved);
	}//end testAnUnexpiredCheckoutIsReused()

	/**
	 * A pending request whose checkout expired gets a new checkout on the same request.
	 *
	 * @return void
	 */
	public function testAnExpiredCheckoutOpensANewOne(): void {
		$this->objectService->data['shillinq']['PaymentRequest'][] = [
			'id' => 'pr-1',
			'invoiceReference' => self::INVOICE_ID,
			'state' => 'pending',
			'amount' => 302.5,
			'paymentLink' => 'https://www.mollie.com/checkout/tr_old',
			'checkoutExpiresAt' => '2026-10-01T09:27:00Z',
		];
		$this->provider->expects($this->once())->method('createSession')->willReturn(self::live(id: 'tr_new'));
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		$result = $this->service()->initiateForPayToken(token: $token);

		self::assertSame('https://www.mollie.com/checkout/tr_new', $result->checkoutUrl);
		self::assertCount(1, $this->objectService->saved);
		self::assertSame('pr-1', $this->objectService->saved[0]['uuid']);
		self::assertSame('https://www.mollie.com/checkout/tr_new', $this->objectService->saved[0]['object']['paymentLink']);
		self::assertSame('tr_new', $this->objectService->saved[0]['object']['providerPaymentId']);
	}//end testAnExpiredCheckoutOpensANewOne()

	/**
	 * A dormant provider defers and stores no link.
	 *
	 * @return void
	 */
	public function testADormantProviderDefersWithoutALink(): void {
		$this->provider->method('createSession')->willReturn(new PaymentSessionResult(dormant: true, checkoutUrl: '', paymentIntentId: ''));
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		self::assertSame('deferred', $this->service()->initiateForPayToken(token: $token)->status);
		$saved = $this->lastSavedRequest();
		self::assertArrayNotHasKey('paymentLink', $saved);
		self::assertArrayNotHasKey('providerPaymentId', $saved);
		self::assertArrayNotHasKey('checkoutExpiresAt', $saved);
	}//end testADormantProviderDefersWithoutALink()

	/**
	 * Without the pay link service no token is accepted.
	 *
	 * @return void
	 */
	public function testWithoutThePayLinkServiceATokenIsForbidden(): void {
		$this->provider->expects($this->never())->method('createSession');
		$token = (string)$this->payLinks->tokenFor(administrationId: self::ADMINISTRATION, invoiceId: self::INVOICE_ID);

		self::assertSame('forbidden', $this->service(withPayLinks: false)->initiateForPayToken(token: $token)->status);
	}//end testWithoutThePayLinkServiceATokenIsForbidden()

	/**
	 * The portal's own Pay now stores the checkout link on the request too (task 1.3).
	 *
	 * @return void
	 */
	public function testThePortalPayStoresTheCheckoutLink(): void {
		$this->provider->method('createSession')->willReturn(self::live(id: 'tr_portal'));

		$result = $this->service()->initiate(claims: ['sub' => 'sub-1', 'audience' => 'customer'], target: self::INVOICE_ID);

		self::assertSame('ok', $result->status);
		$saved = $this->lastSavedRequest();
		self::assertSame('https://www.mollie.com/checkout/tr_portal', $saved['paymentLink']);
		self::assertSame('tr_portal', $saved['providerPaymentId']);
		self::assertIsString($saved['checkoutExpiresAt']);
	}//end testThePortalPayStoresTheCheckoutLink()
}//end class
