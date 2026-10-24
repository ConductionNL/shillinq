<?php

/**
 * Unit tests for FinancialDocumentLeafProvider.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Integration\FinancialDocumentLeafProvider;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCA\Shillinq\Listener\PaymentRequestLeafRegistrationListener;
use OCA\Shillinq\Service\FinancialDocumentFinder;
use OCA\Shillinq\Service\FinancialDocumentRows;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * An in-memory store per schema that answers a system read in full and a
 * caller read with only what the caller may see, as OpenRegister does.
 */
final class FinancialDocumentStore {
	/**
	 * The schema the next read is for.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Every findAll call, with the schema and whether RBAC applied.
	 *
	 * @var array<int, array{schema: string, config: array<string, mixed>, rbac: bool}>
	 */
	public array $reads = [];

	/**
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 * @param array<int, string> $hidden Ids the caller may not read.
	 */
	public function __construct(public array $rows, public array $hidden = []) {
	}

	public function setRegister(string|int $register): static {
		return $this;
	}

	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}

	/**
	 * @param array<string, mixed> $config The query.
	 * @param bool $_rbac Whether the caller's rights apply.
	 * @param bool $_multitenancy Whether the caller's organisation applies.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->reads[] = ['schema' => $this->schema, 'config' => $config, 'rbac' => $_rbac];
		$rows = ($this->rows[$this->schema] ?? []);
		if ($_rbac === true) {
			$rows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['id'], $this->hidden, true) === false));
		}

		return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($rows)));
	}
}//end class

/**
 * One leaf lists every financial document on an object.
 */
final class FinancialDocumentLeafProviderTest extends TestCase {
	private const CASE_ID = '7d9a2c4e-1b3f-4a5d-8e6f-0a1b2c3d4e5f';

	private const OTHER_ID = '11111111-2222-4333-8444-555555555555';

	/**
	 * A link to a case.
	 *
	 * @param string $id The case id.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function on(string $id = self::CASE_ID): array {
		return [['app' => 'dossiq', 'type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => $id]];
	}//end on()

	/**
	 * One document of each kind on the case, and one invoice on another case.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function rows(): array {
		return [
			'Quote' => [['id' => 'q-1', 'quoteNumber' => 'OFF-2026-001', 'status' => 'sent', 'validUntil' => '2026-11-30', 'currency' => 'EUR', 'linkedObjects' => self::on()]],
			'ARInvoice' => [
				['id' => 'i-1', 'invoiceNumber' => 'F-2026-0042', 'invoiceType' => 'standard', 'lifecycleState' => 'issued', 'grossAmount' => 120.0, 'amountDue' => 120.0, 'currency' => 'EUR', 'invoiceDate' => '2026-10-01', 'dueDate' => '2026-10-31', 'linkedObjects' => self::on()],
				['id' => 'i-2', 'invoiceNumber' => 'C-2026-0003', 'invoiceType' => 'credit-note', 'lifecycleState' => 'issued', 'grossAmount' => -20.0, 'currency' => 'EUR', 'invoiceDate' => '2026-10-05', 'linkedObjects' => self::on()],
				['id' => 'i-9', 'invoiceNumber' => 'F-2026-0099', 'invoiceType' => 'standard', 'lifecycleState' => 'issued', 'grossAmount' => 50.0, 'currency' => 'EUR', 'linkedObjects' => self::on(self::OTHER_ID)],
			],
			'PaymentRequest' => [['id' => 'p-1', 'subjectKind' => 'object', 'state' => 'captured', 'amount' => 85.0, 'currency' => 'EUR', 'description' => 'Leges', 'dueAt' => '2026-10-15', 'linkedObjects' => self::on()]],
			'InboundPayable' => [['id' => 'o-1', 'reference' => 'DWS-2026-0042', 'state' => 'approved', 'amount' => 357.0, 'currency' => 'EUR', 'dueOn' => '2026-11-01', 'requestedAt' => '2026-10-02T09:00:00Z', 'linkedObjects' => self::on()]],
		];
	}//end rows()

	/**
	 * The provider over a store.
	 *
	 * @param FinancialDocumentStore $store The store.
	 *
	 * @return FinancialDocumentLeafProvider
	 */
	private function provider(FinancialDocumentStore $store, array $actions = []): FinancialDocumentLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($actions): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return (string)json_encode(array_fill_keys($actions, ['finance']));
				}

				return 'shillinq';
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);

		return new FinancialDocumentLeafProvider(
			finder: new FinancialDocumentFinder(objectService: new DuckObjectServiceAdapter(inner: $store), appConfig: $appConfig),
			rows: new FinancialDocumentRows(),
			authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
		);
	}//end provider()

	/**
	 * The list for the case, keyed by document id.
	 *
	 * @param FinancialDocumentStore $store The store.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listed(FinancialDocumentStore $store): array {
		$items = $this->provider($store)->list('dossiq', 'case', self::CASE_ID)['items'];

		return array_column($items, null, 'id');
	}//end listed()

	public function testTheLeafNamesItself(): void {
		$provider = $this->provider(new FinancialDocumentStore([]));

		self::assertSame('shillinq-financial-documents', $provider->getId());
		self::assertSame(FinancialDocumentLeafProvider::LEAF_ID, $provider->getId());
		self::assertSame('Finance', $provider->getGroup());
		self::assertSame('shillinq', $provider->getRequiredApp());
		self::assertSame('app-local', $provider->getStorageStrategy());
	}//end testTheLeafNamesItself()

	public function testEveryKindOnTheObjectIsListedAndNothingElse(): void {
		$listed = $this->listed(new FinancialDocumentStore(self::rows()));

		self::assertSame(['quote', 'invoice', 'credit-note', 'payment-request', 'payout'], array_values(array_map(static fn (array $row): string => $row['kind'], [$listed['q-1'], $listed['i-1'], $listed['i-2'], $listed['p-1'], $listed['o-1']])));
		self::assertArrayNotHasKey('i-9', $listed, 'an invoice on another case is not listed');
	}//end testEveryKindOnTheObjectIsListedAndNothingElse()

	public function testARowCarriesTheFieldsThePanelReads(): void {
		$listed = $this->listed(new FinancialDocumentStore(self::rows()));

		self::assertSame(
			['id' => 'i-1', 'kind' => 'invoice', 'number' => 'F-2026-0042', 'status' => 'issued', 'amount' => 120.0, 'currency' => 'EUR', 'issuedOn' => '2026-10-01', 'dueOn' => '2026-10-31', 'openAmount' => 120.0, 'paymentState' => 'outstanding', 'path' => '/apps/shillinq/bookkeeping/accounts-receivable/i-1', 'restricted' => false],
			$listed['i-1']
		);
		self::assertSame('paid', $listed['p-1']['paymentState']);
		self::assertSame('outstanding', $listed['o-1']['paymentState']);
		self::assertSame('DWS-2026-0042', $listed['o-1']['number']);
		self::assertNull($listed['q-1']['amount'], 'a quote has no amount of its own; its lines do');
		self::assertSame('none', $listed['q-1']['paymentState']);
		self::assertSame('none', $listed['i-2']['paymentState']);
		self::assertSame('', $listed['p-1']['path'], 'a payment request has no page of its own');
	}//end testARowCarriesTheFieldsThePanelReads()

	public function testTheReadIsNarrowedByTheObjectsUuid(): void {
		$store = new FinancialDocumentStore(self::rows());
		$this->listed($store);

		foreach ($store->reads as $read) {
			self::assertSame(self::CASE_ID, ($read['config']['filters']['_relations_contains'] ?? null), $read['schema']);
		}
	}//end testTheReadIsNarrowedByTheObjectsUuid()

	public function testADocumentTheCallerMayNotReadIsCountedWithoutItsAmountOrNumber(): void {
		$listed = $this->listed(new FinancialDocumentStore(self::rows(), hidden: ['i-1']));

		self::assertCount(5, $listed, 'the count includes what the caller may not read');
		self::assertSame(
			['id' => 'i-1', 'kind' => 'invoice', 'number' => '', 'status' => 'issued', 'amount' => null, 'currency' => '', 'issuedOn' => '', 'dueOn' => '', 'openAmount' => null, 'paymentState' => 'outstanding', 'path' => '', 'restricted' => true],
			$listed['i-1']
		);
	}//end testADocumentTheCallerMayNotReadIsCountedWithoutItsAmountOrNumber()

	public function testTheListSaysWhichActsTheCallerHolds(): void {
		$none = $this->provider(new FinancialDocumentStore([]))->list('dossiq', 'case', self::CASE_ID)['acts'];
		$some = $this->provider(new FinancialDocumentStore([]), ['invoice.issue', 'payment.administer'])->list('dossiq', 'case', self::CASE_ID)['acts'];

		self::assertSame(['issueInvoice' => false, 'requestPayment' => false, 'administerPayments' => false], $none);
		self::assertSame(['issueInvoice' => true, 'requestPayment' => false, 'administerPayments' => true], $some);
	}//end testTheListSaysWhichActsTheCallerHolds()

	public function testGetAnswersAReadableRowAndRefusesTheRest(): void {
		$provider = $this->provider(new FinancialDocumentStore(self::rows(), hidden: ['i-1']));

		self::assertSame('payout', $provider->get('dossiq', 'case', self::CASE_ID, 'o-1')['kind']);
		foreach (['i-1', 'i-9', 'nope'] as $id) {
			try {
				$provider->get('dossiq', 'case', self::CASE_ID, $id);
				self::fail($id . ' was answered');
			} catch (RuntimeException $e) {
				self::assertStringStartsWith('404', $e->getMessage(), $id);
			}
		}
	}//end testGetAnswersAReadableRowAndRefusesTheRest()

	public function testTheLeafWritesNothingYet(): void {
		$provider = $this->provider(new FinancialDocumentStore(self::rows()));

		foreach ([
			static fn () => $provider->create('dossiq', 'case', self::CASE_ID, []),
			static fn () => $provider->update('dossiq', 'case', self::CASE_ID, 'i-1', []),
			static fn () => $provider->delete('dossiq', 'case', self::CASE_ID, 'i-1'),
		] as $write) {
			try {
				$write();
				self::fail('a write went through');
			} catch (RuntimeException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}//end testTheLeafWritesNothingYet()

	public function testTheLeafIsRegisteredBesideThePaymentRequests(): void {
		$event = new RegisterLeafProvidersEvent();
		$listener = new PaymentRequestLeafRegistrationListener(
			provider: (new ReflectionClass(PaymentRequestLeafProvider::class))->newInstanceWithoutConstructor(),
			contracts: (new ReflectionClass(ContractLeafProvider::class))->newInstanceWithoutConstructor(),
			documents: $this->provider(new FinancialDocumentStore([])),
		);
		$listener->handle($event);

		$registered = array_map(static fn (array $leaf): string => $leaf['descriptor']->getId(), $event->getLeaves());
		self::assertContains(FinancialDocumentLeafProvider::LEAF_ID, $registered);
		self::assertContains('shillinq-financial-documents-panel', $registered, 'the panel half (REQ-FDO-003)');
		self::assertContains(PaymentRequestLeafProvider::LEAF_ID, $registered, 'the payment request leaf stays (REQ-FDO-006)');
	}//end testTheLeafIsRegisteredBesideThePaymentRequests()
}//end class
