<?php

/**
 * Unit tests for the bank match of object payment requests.
 *
 * A bank line is fed to BankLineObjectRequestListener as OpenRegister's real
 * ObjectCreatedEvent; ManualMatchService writes the match and runs `confirm`
 * on a lifecycle-faithful engine, which fires the real
 * ObjectTransitionedEvent back into the same listener, which settles the
 * request. Every write is checked against the merged register schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\BankLineObjectRequestListener;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Bank\ObjectRequestBankMatcher;
use OCA\Shillinq\Service\Bank\ObjectRequestBankSettlement;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-ORS-003, REQ-ORS-004.
 */
final class ObjectRequestBankMatchTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The listener under test, also wired to the engine's confirm.
	 *
	 * @var BankLineObjectRequestListener|null
	 */
	private ?BankLineObjectRequestListener $listener = null;

	/**
	 * The engine.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Build the register with one bank account, one statement and the requests.
	 *
	 * @param array<int, array<string, mixed>> $requests PaymentRequest rows.
	 * @param array<int, array<string, mixed>> $invoices ARInvoice rows.
	 *
	 * @return void
	 */
	private function given(array $requests, array $invoices = []): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(
			[
				'PaymentRequest' => $requests,
				'BankStatementLine' => [],
				'BankStatement' => [
					['id' => 'stmt-1', 'statementId' => 'stmt-1', 'bankAccountIban' => 'NL91BANK0417164300', 'lifecycleState' => 'in-progress', 'administrationId' => 'adm-1'],
				],
				'BankAccount' => [
					['id' => 'ba-1', 'iban' => 'NL91BANK0417164300', 'ledgerAccountNumber' => '1100', 'administrationId' => 'adm-1'],
				],
				'ARInvoice' => $invoices,
				'JournalEntry' => [],
				'ReconciliationMatch' => [],
			],
			$this->saved
		);

		$this->engine = new LifecycleFaithfulTransitionEngine(
			store: $this->store,
			schemas: ['ReconciliationMatch', 'BankStatementLine', 'ARInvoice', 'JournalEntry'],
			onRan: function (array $record, array $object): void {
				if ($record['schema'] === 'JournalEntry' && $record['action'] === 'postDirect') {
					// MaterialiseGlTransactionAction writes the back reference.
					$this->store->setSchema('JournalEntry')->patchObject($record['objectId'], ['glTransactionId' => 'gl-' . $record['objectId']]);
				}

				if ($record['schema'] !== 'ReconciliationMatch') {
					return;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($record['objectId']);
				$entity->setObject(array_merge($object, ['id' => $record['objectId']]));
				$entity->setSchema('ReconciliationMatch');
				$entity->setRegister('shillinq');
				$this->listener->handle(new ObjectTransitionedEvent($entity, $record['action'], $record['from'], $record['to'], 'bookkeeper', 'shillinq', 'ReconciliationMatch'));
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$engine = $this->engine;
		$container->method('get')->willReturnCallback(static fn (): object => $engine);
		$runner = new ObjectTransitionRunner(container: $container);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				PaymentRevenueAccountResolver::CONFIG_KEY => '{"event-fee": "8100"}',
				'register' => 'shillinq',
				default => $default,
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$manual = new ManualMatchService(objectService: $this->store, transitions: $runner, settings: $settings, logger: $logger);
		$settlements = new PaymentSettlementService();
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());

		$this->listener = new BankLineObjectRequestListener(
			matcher: new ObjectRequestBankMatcher(
				finder: new PaymentRequestFinder(objectService: $this->store, validator: new ObjectPaymentRequestValidator(), appConfig: $appConfig),
				settlements: $settlements,
			),
			manualMatch: $manual,
			settlement: new ObjectRequestBankSettlement(
				objectService: $this->store,
				settings: $settings,
				settlements: $settlements,
				revenueAccounts: new PaymentRevenueAccountResolver(appConfig: $appConfig),
				invoices: new InvoiceSettlementService(objectService: $this->store, transitions: $runner, settings: $settings, logger: $logger),
				manualMatch: $manual,
				logger: $logger,
			),
			schemaResolver: $resolver,
			logger: $logger,
		);
	}//end given()

	/**
	 * A pending event-fee request as larpinq raises it.
	 *
	 * @param string $id The request id.
	 * @param string $reference Its transfer reference.
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private static function request(string $id, string $reference, array $overrides = []): array {
		return array_merge(
			[
				'id' => $id,
				'subjectKind' => 'object',
				'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-' . $id],
				'requestType' => 'event-fee',
				'amount' => 85.0,
				'currency' => 'EUR',
				'paymentGateway' => 'mollie',
				'state' => 'pending',
				'description' => 'Winter Court 2026',
				'paymentReference' => $reference,
				'administrationId' => 'adm-1',
			],
			$overrides
		);
	}//end request()

	/**
	 * Save a bank line and fire the created event as OpenRegister does.
	 *
	 * @param float $amount The line amount.
	 * @param string $remittance The remittance text.
	 *
	 * @return void
	 */
	private function bankLine(float $amount, string $remittance): void {
		$line = [
			'id' => 'line-1', 'lineId' => 'L-1', 'statementId' => 'stmt-1', 'lineNumber' => 1, 'valueDate' => '2026-10-05',
			'amount' => $amount, 'currency' => 'EUR', 'remittanceInfo' => $remittance, 'narrative' => $remittance,
			'endToEndRef' => 'E2E-778', 'counterpartyName' => 'A. Jansen',
			'status' => 'unmatched', 'matchState' => 'unmatched', 'administrationId' => 'adm-1',
		];
		$this->store->setSchema('BankStatementLine')->saveObject($line);
		$entity = new ObjectEntity();
		$entity->setUuid('line-1');
		$entity->setSchema('BankStatementLine');
		$entity->setObject($line);
		$this->listener->handle(new ObjectCreatedEvent($entity));
	}//end bankLine()

	/**
	 * The saves to one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The saved payloads.
	 */
	private function savesOf(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end savesOf()

	/**
	 * A stored object.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function stored(string $schema, string $id): array {
		return $this->store->find($id, schema: $schema)->getObject();
	}//end stored()

	/**
	 * The full open amount with the reference as a whole token: a confirmed
	 * payment-request match, a bank-transfer settlement, settledAt, and one
	 * receipt posted through a journal entry with the bank account on the debit side; a second
	 * created event for the same line books nothing more (REQ-ORS-003,
	 * REQ-ORS-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function testConfirmedMatchSettlesAndBooksOnce(): void {
		$this->given([self::request('pr-1', 'WC26-0042')]);

		$this->bankLine(85.0, 'Winter Court wc26-0042 Anna');
		// The same created event again, as a replay would deliver it.
		$entity = new ObjectEntity();
		$entity->setUuid('line-1');
		$entity->setSchema('BankStatementLine');
		$entity->setObject($this->stored('BankStatementLine', 'line-1'));
		$this->listener->handle(new ObjectCreatedEvent($entity));

		$matches = $this->savesOf('ReconciliationMatch');
		self::assertCount(1, array_unique(array_column($matches, 'matchId')));
		self::assertSame('payment-request', $matches[0]['matchType']);
		self::assertSame('pr-1', $matches[0]['matchedObjectId']);
		self::assertSame([], RegisterSchema::errors('ReconciliationMatch', $matches[0]));
		self::assertSame('matched', $this->stored('BankStatementLine', 'line-1')['status']);

		$request = $this->stored('PaymentRequest', 'pr-1');
		self::assertSame('bank-transfer', $request['settledVia']);
		self::assertSame('2026-10-05', substr((string)$request['settledAt'], 0, 10));
		self::assertCount(1, $request['settlements']);
		self::assertSame('bank-transfer', $request['settlements'][0]['method']);
		self::assertSame(85.0, (float)$request['settlements'][0]['amount']);
		self::assertSame('E2E-778', $request['settlements'][0]['reference']);
		self::assertSame([], RegisterSchema::errors('PaymentRequest', $request));

		$journals = array_values(array_filter($this->savesOf('JournalEntry'), static fn (array $j): bool => isset($j['lines'])));
		self::assertCount(1, array_unique(array_column($journals, 'journalNumber')));
		$posted = [];
		foreach ($journals[0]['lines'] as $posting) {
			$posted[$posting['accountNumber']] = [$posting['side'], (float)$posting['amount']];
		}
		self::assertSame(['8100' => ['credit', 85.0], '1100' => ['debit', 85.0]], $posted);
		self::assertSame([], RegisterSchema::errors('JournalEntry', $journals[0]));
		self::assertContains('postDirect', array_column($this->engine->ran, 'action'));
	}//end testConfirmedMatchSettlesAndBooksOnce()

	/**
	 * Another amount waits for the bookkeeper: a pending match, the request
	 * stays open, nothing is booked (REQ-ORS-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function testAPartialAmountWaitsForTheBookkeeper(): void {
		$this->given([self::request('pr-1', 'WC26-0042')]);

		$this->bankLine(50.0, 'Winter Court WC26-0042 Anna');

		$matches = $this->savesOf('ReconciliationMatch');
		self::assertCount(1, $matches);
		$stored = $this->store->setSchema('ReconciliationMatch')->findAll([]);
		self::assertSame('pending', (string)((array)(is_array($stored[0]) ? $stored[0] : $stored[0]->getObject()))['status']);
		self::assertSame([], RegisterSchema::errors('ReconciliationMatch', $matches[0]));
		self::assertSame('unmatched', $this->stored('BankStatementLine', 'line-1')['status']);
		self::assertSame('', (string)($this->stored('PaymentRequest', 'pr-1')['settledAt'] ?? ''));
		self::assertSame([], array_filter($this->savesOf('JournalEntry'), static fn (array $j): bool => isset($j['lines'])));
	}//end testAPartialAmountWaitsForTheBookkeeper()

	/**
	 * Two requests quoted on one line: a candidate for each, nothing settled;
	 * a reference that is only part of a longer token, or no reference, makes
	 * no match at all (REQ-ORS-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function testTwoRequestsOrNoWholeReference(): void {
		$this->given([self::request('pr-1', 'WC26-0042'), self::request('pr-2', 'WC26-0043')]);
		$this->bankLine(85.0, 'WC26-0042 en WC26-0043');
		self::assertCount(2, $this->savesOf('ReconciliationMatch'));
		self::assertSame([], array_filter($this->savesOf('JournalEntry'), static fn (array $j): bool => isset($j['lines'])));

		$this->given([self::request('pr-1', 'WC26-0042')]);
		$this->bankLine(85.0, 'WC26-00421 Anna');
		$this->bankLine(85.0, 'contributie oktober');
		self::assertSame([], $this->savesOf('ReconciliationMatch'));
	}//end testTwoRequestsOrNoWholeReference()

	/**
	 * A request with an invoice behind it settles that invoice and books
	 * nothing on the object (REQ-ORS-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function testARequestWithAnInvoiceSettlesTheInvoice(): void {
		$this->given(
			[self::request('pr-1', 'WC26-0042', ['invoiceReference' => 'ar-1'])],
			[['id' => 'ar-1', 'invoiceNumber' => 'VF-2026-0042', 'lifecycleState' => 'issued', 'grossAmount' => 85.0, 'administrationId' => 'adm-1']]
		);

		$this->bankLine(85.0, 'WC26-0042');

		self::assertSame('paid', $this->stored('ARInvoice', 'ar-1')['lifecycleState']);
		self::assertSame([], array_filter($this->savesOf('JournalEntry'), static fn (array $j): bool => isset($j['lines'])));
		self::assertSame('bank-transfer', $this->stored('PaymentRequest', 'pr-1')['settledVia']);
	}//end testARequestWithAnInvoiceSettlesTheInvoice()

	/**
	 * The app wires the listener on new objects and on transitions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function testTheAppWiresTheListener(): void {
		$registration = (string)file_get_contents(__DIR__ . '/../../../../lib/AppInfo/ObjectRequestSettlementRegistration.php');

		self::assertMatchesRegularExpression('/ObjectCreatedEvent::class,\s*listener: BankLineObjectRequestListener::class/', $registration);
		self::assertMatchesRegularExpression('/ObjectTransitionedEvent::class,\s*listener: BankLineObjectRequestListener::class/', $registration);
	}//end testTheAppWiresTheListener()
}//end class
