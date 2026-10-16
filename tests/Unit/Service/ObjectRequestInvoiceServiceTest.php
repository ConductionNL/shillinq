<?php

/**
 * Tests for ObjectRequestInvoiceService through the payment-request leaf.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCA\Shillinq\Service\ContributionDebtorResolver;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\ObjectRequestInvoiceService;
use OCA\Shillinq\Service\PaymentActionAppGrant;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An invoice asked for at create stands behind the request (REQ-ORS-006).
 */
final class ObjectRequestInvoiceServiceTest extends TestCase {

	/**
	 * A customer uuid as OpenRegister answers one.
	 *
	 * @var string
	 */
	private const UUID = '5b0e6f8c-2d4a-4c1e-9a7b-3f2d1c0e9b8a';

	/**
	 * The store the leaf and the invoice service write to.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The leaf with the real invoice service and debtor resolver over one store.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Schema => rows.
	 * @param array<string, float> $vatRates Request type => VAT rate.
	 *
	 * @return PaymentRequestLeafProvider The provider.
	 */
	private function leaf(array $data = [], array $vatRates = []): PaymentRequestLeafProvider {
		$this->store = new InMemoryObjectServiceStub(data: $data);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($vatRates): string {
				if ($key === ObjectRequestInvoiceService::VAT_CONFIG_KEY && $vatRates !== []) {
					return json_encode($vatRates, JSON_THROW_ON_ERROR);
				}

				return ($key === 'register' ? 'shillinq' : $default);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$invoices = new ObjectRequestInvoiceService(
			objectService: $this->store,
			debtors: new ContributionDebtorResolver(
				objectService: $this->store,
				dispatcher: $this->createMock(IEventDispatcher::class),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			appConfig: $appConfig,
		);

		return new PaymentRequestLeafProvider(
			objectService: $this->store,
			validator: new ObjectPaymentRequestValidator(),
			appConfig: $appConfig,
			authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
			feeSchedules: new FeeScheduleService(
				objectService: $this->store,
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			settlements: new PaymentSettlementService(),
			appGrant: new PaymentActionAppGrant(appConfig: $appConfig, appManager: $this->createMock(IAppManager::class)),
			invoices: $invoices,
		);
	}//end leaf()

	/**
	 * Sanne's registration fee, as larpinq sends it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The leaf payload.
	 */
	private function payload(array $overrides = []): array {
		return array_merge(
			[
				'requestType' => 'event-fee',
				'amount' => 85.0,
				'subjectType' => 'registration',
				'description' => 'Winter Camp 2026',
				'paymentReference' => 'WC26-0042',
				'invoiceRequested' => true,
				'administrationId' => 'adm-larp-1',
				'debtor' => ['name' => 'Sanne de Boer', 'email' => 'sanne@example.nl'],
			],
			$overrides
		);
	}//end payload()

	/**
	 * The saves the store recorded for one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The saved objects.
	 */
	private function savedIn(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end savedIn()

	/**
	 * A player who asked for an invoice gets one issued invoice for 85.00,
	 * billed to her customer, and the request names it (REQ-ORS-006).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testInvoiceStandsBehindTheRequest(): void {
		$created = $this->leaf()->create('larpinq', 'Registration', 'reg-42', $this->payload());

		$customers = $this->savedIn(schema: 'CustomerMaster');
		self::assertCount(1, $customers);
		self::assertSame('sanne@example.nl', $customers[0]['email']);

		$invoices = $this->savedIn(schema: 'ARInvoice');
		self::assertCount(1, $invoices);
		self::assertSame('issued', $invoices[0]['lifecycleState']);
		self::assertSame(85.0, $invoices[0]['grossAmount']);
		self::assertSame($customers[0]['id'], $invoices[0]['customerId']);
		self::assertSame('Winter Camp 2026', $invoices[0]['invoiceLines'][0]['itemName']);

		self::assertSame($invoices[0]['id'], $created['invoiceReference']);
		self::assertSame('adm-larp-1', $created['administrationId']);

		// The in-memory store numbers its objects (`obj-1`); OpenRegister
		// answers a uuid, which is what `customerId` declares.
		$invoice = array_merge($invoices[0], ['customerId' => self::UUID]);
		foreach (['CustomerMaster' => $customers[0], 'ARInvoice' => $invoice, 'PaymentRequest' => $created] as $schema => $object) {
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}
	}//end testInvoiceStandsBehindTheRequest()

	/**
	 * A known customer is billed as named; no second customer is made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testANamedCustomerIsBilledAsIs(): void {
		$seed = ['CustomerMaster' => [['id' => 'cm-7', 'customerId' => 'G-7', 'email' => 'sanne@example.nl', 'administrationId' => 'adm-larp-1']]];
		$created = $this->leaf(data: $seed)->create('larpinq', 'Registration', 'reg-42', $this->payload(['debtor' => ['customerMasterId' => 'cm-7']]));

		self::assertSame([], $this->savedIn(schema: 'CustomerMaster'));
		self::assertSame('cm-7', $this->savedIn(schema: 'ARInvoice')[0]['customerId']);
		self::assertNotSame('', (string)$created['invoiceReference']);
	}//end testANamedCustomerIsBilledAsIs()

	/**
	 * A VAT rate mapped to the type splits the gross the payer was asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testAMappedVatRateSplitsTheGross(): void {
		$this->leaf(vatRates: ['event-fee' => 21])->create('larpinq', 'Registration', 'reg-42', $this->payload());

		$invoice = $this->savedIn(schema: 'ARInvoice')[0];
		self::assertSame(85.0, $invoice['grossAmount']);
		self::assertSame(70.25, $invoice['netAmount']);
		self::assertSame(14.75, $invoice['vatAmount']);
		self::assertSame('S', $invoice['invoiceLines'][0]['vatCategory']);
		self::assertSame([], RegisterSchema::errors(slug: 'ARInvoice', object: array_merge($invoice, ['customerId' => self::UUID])));
	}//end testAMappedVatRateSplitsTheGross()

	/**
	 * Without the flag no invoice is issued and the request carries none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testNoFlagNoInvoice(): void {
		$created = $this->leaf()->create('larpinq', 'Registration', 'reg-42', $this->payload(['invoiceRequested' => false]));

		self::assertSame([], $this->savedIn(schema: 'ARInvoice'));
		self::assertArrayNotHasKey('invoiceReference', $created);
	}//end testNoFlagNoInvoice()

	/**
	 * An invoice without an administration is refused and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testAnInvoiceWithoutAnAdministrationWritesNothing(): void {
		$leaf = $this->leaf();
		try {
			$leaf->create('larpinq', 'Registration', 'reg-42', $this->payload(['administrationId' => '']));
			self::fail('An invoice without an administration was issued');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('administrationId', $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testAnInvoiceWithoutAnAdministrationWritesNothing()
}//end class
