<?php

/**
 * Unit tests for PaymentReconciliationService (shared deposits + invoice links).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004, REQ-APL-005)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PaymentReconciliationService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the shared idempotent reconciliation across PaymentRequest +
 * DepositPayment, the captured→invoice settlement handoff, the
 * captured_unapplied exception path (REQ-APL-004/005), and — added by
 * portal-payment-initiation — the subject-safe confirmationSummary write on
 * settlement (REQ-SPPI-005).
 */
final class PaymentReconciliationServiceTest extends TestCase {
	/**
	 * Build a fluent ObjectService stub. findAll() returns the records keyed by
	 * the schema set via setSchema(); saveObject() records into a shared sink.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema slug.
	 * @param array<int, array<string, mixed>> $saved Reference sink for saveObject().
	 *
	 * @return object The stub.
	 */
	private function buildObjectServiceStub(array $bySchema, array &$saved): object {
		return new class($bySchema, $saved) {
			/**
			 * Currently selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Records per schema.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(
				private array $bySchema,
				private array &$saved,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				$offset = (int)($params['offset'] ?? 0);
				if ($offset > 0) {
					return [];
				}

				return ($this->bySchema[$this->schema] ?? []);
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				// The subject reaches this double through the ADR-084 contract,
				// which carries the target schema as a named argument and applies
				// it via setSchema() rather than passing it on positionally here.
				// Fall back to the schema the fluent chain selected so the sink
				// still records where the write landed.
				if ($schema === '') {
					$schema = $this->schema;
				}

				$this->saved[] = ['schema' => $schema, 'object' => $object];
				if (isset($object['id']) === false) {
					$object['id'] = 'saved-' . count($this->saved);
				}

				return $object;
			}
		};
	}//end buildObjectServiceStub()

	/**
	 * Build the service under test with a container yielding the given ObjectService.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return PaymentReconciliationService
	 */
	private function makeService(object $objectService): PaymentReconciliationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PaymentReconciliationService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter(inner: $objectService),
		);
	}//end makeService()

	/**
	 * A captured event on a pending PaymentRequest settles the linked, settleable
	 * ARInvoice and marks the request captured (REQ-APL-005 happy path).
	 *
	 * @return void
	 */
	public function testCaptureSettlesLinkedInvoice(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'pending', 'invoiceReference' => 'inv-1']],
				'ARInvoice' => [['id' => 'inv-1', 'state' => 'issued']],
			],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured', 'settlementReference' => 'po_1']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		self::assertSame('PaymentRequest', $out['schema']);

		// Both the invoice (paid) and the request (captured) are saved.
		$schemas = array_map(static fn (array $s): string => $s['schema'], $saved);
		self::assertContains('ARInvoice', $schemas);
		self::assertContains('PaymentRequest', $schemas);

		$request = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'))[0]['object'];
		self::assertSame('captured', $request['state']);
		self::assertSame('po_1', $request['settlementReference']);
		self::assertArrayHasKey('capturedAt', $request);
	}//end testCaptureSettlesLinkedInvoice()

	/**
	 * A capture against an already-settled invoice becomes captured_unapplied,
	 * never a silent drop (REQ-APL-005 exception path). No confirmation is
	 * written for an unapplied capture — the invoice was NOT actually settled
	 * by this event (portal-payment-initiation REQ-SPPI-005).
	 *
	 * @return void
	 */
	public function testCaptureOnSettledInvoiceBecomesUnapplied(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'pending', 'invoiceReference' => 'inv-1']],
				'ARInvoice' => [['id' => 'inv-1', 'state' => 'paid']],
			],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_UNAPPLIED, $out['result']);
		$request = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'))[0]['object'];
		self::assertSame('captured_unapplied', $request['state']);
		self::assertArrayNotHasKey('confirmationSummary', $request);
	}//end testCaptureOnSettledInvoiceBecomesUnapplied()

	/**
	 * A captured event writes a subject-safe confirmationSummary onto the
	 * PaymentRequest, readable through the invoice's own human number, the
	 * capture date and the settlement reference — never raw PCI/internal
	 * detail (portal-payment-initiation REQ-SPPI-005).
	 *
	 * @return void
	 */
	public function testCaptureWritesConfirmationSummary(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'pending', 'invoiceReference' => 'inv-1']],
				'ARInvoice' => [['id' => 'inv-1', 'state' => 'issued', 'invoiceNumber' => 'INV-2026-0042']],
			],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured', 'settlementReference' => 'po_1']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		$request = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'))[0]['object'];
		self::assertArrayHasKey('confirmationSummary', $request);
		self::assertStringContainsString('INV-2026-0042', $request['confirmationSummary']);
		self::assertStringContainsString('po_1', $request['confirmationSummary']);
	}//end testCaptureWritesConfirmationSummary()

	/**
	 * Replaying a capture webhook on an already-captured request is an
	 * idempotent no-op — the confirmationSummary already written is NEVER
	 * overwritten or double-composed (portal-payment-initiation REQ-SPPI-005).
	 *
	 * @return void
	 */
	public function testReplayedCaptureDoesNotOverwriteConfirmationSummary(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [
					[
						'paymentIntentId' => 'tr_1',
						'state' => 'captured',
						'invoiceReference' => 'inv-1',
						'confirmationSummary' => 'Invoice INV-2026-0042 paid on 2026-07-20, reference po_1.',
					],
				],
			],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_NOOP, $out['result']);
		self::assertCount(0, $saved);
	}//end testReplayedCaptureDoesNotOverwriteConfirmationSummary()

	/**
	 * Replaying a capture webhook on an already-captured request is an idempotent
	 * no-op — no second invoice transition (REQ-APL-004).
	 *
	 * @return void
	 */
	public function testReplayedCaptureIsIdempotent(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			['PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'captured', 'invoiceReference' => 'inv-1']]],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_NOOP, $out['result']);
		self::assertCount(0, $saved);
	}//end testReplayedCaptureIsIdempotent()

	/**
	 * The shared service resolves a DepositPayment when no PaymentRequest matches
	 * — one code path, two record types (REQ-APL-004).
	 *
	 * @return void
	 */
	public function testResolvesDepositPaymentWhenNoPaymentRequest(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [],
				'DepositPayment' => [['paymentIntentId' => 'tr_dep', 'state' => 'pending']],
			],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('stripe', ['paymentIntentId' => 'tr_dep', 'outcome' => 'authorized']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		self::assertSame('DepositPayment', $out['schema']);
	}//end testResolvesDepositPaymentWhenNoPaymentRequest()

	/**
	 * An unknown payment intent returns not-found gracefully (no throw).
	 *
	 * @return void
	 */
	public function testUnknownIntentReturnsNotFound(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [], 'DepositPayment' => []], $saved);
		$service = $this->makeService($stub);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_missing', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_NOT_FOUND, $out['result']);
		self::assertNull($out['schema']);
		self::assertCount(0, $saved);
	}//end testUnknownIntentReturnsNotFound()

	/**
	 * A malformed event (no outcome / no intent) does not throw and is a no-op
	 * or not-found.
	 *
	 * @return void
	 */
	public function testMalformedEventDoesNotThrow(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [], 'DepositPayment' => []], $saved);
		$service = $this->makeService($stub);

		// Unknown outcome → no-op.
		$out1 = $service->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'gibberish']);
		self::assertSame(PaymentReconciliationService::RESULT_NOOP, $out1['result']);

		// Empty intent → not-found.
		$out2 = $service->reconcile('mollie', ['paymentIntentId' => '', 'outcome' => 'captured']);
		self::assertSame(PaymentReconciliationService::RESULT_NOT_FOUND, $out2['result']);

		// Entirely empty event → no-op (missing outcome).
		$out3 = $service->reconcile('mollie', []);
		self::assertSame(PaymentReconciliationService::RESULT_NOOP, $out3['result']);

		self::assertCount(0, $saved);
	}//end testMalformedEventDoesNotThrow()

	/**
	 * A failed outcome records the operator-readable failure reason.
	 *
	 * @return void
	 */
	public function testFailedRecordsFailureReason(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			['PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'pending', 'invoiceReference' => 'inv-1']]],
			$saved
		);
		$service = $this->makeService($stub);

		$out = $service->reconcile('stripe', ['paymentIntentId' => 'tr_1', 'outcome' => 'failed', 'errorMessage' => 'Insufficient funds.']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		$request = $saved[0]['object'];
		self::assertSame('failed', $request['state']);
		self::assertSame('Insufficient funds.', $request['failureReason']);
	}//end testFailedRecordsFailureReason()

	/**
	 * The polling fallback reconciles pending records across both schemas using
	 * the injected status provider (REQ-APL-004 polling fallback).
	 *
	 * @return void
	 */
	public function testPollPendingCoversBothSchemas(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [['paymentIntentId' => 'tr_pr', 'state' => 'pending', 'paymentGateway' => 'mollie', 'invoiceReference' => 'inv-1']],
				'DepositPayment' => [['paymentIntentId' => 'tr_dep', 'state' => 'pending', 'paymentGateway' => 'mollie']],
				'ARInvoice' => [['id' => 'inv-1', 'state' => 'issued']],
			],
			$saved
		);
		$service = $this->makeService($stub);

		// Both intents report authorized/captured; provider returns an outcome.
		$provider = static fn (string $intentId): ?string => ($intentId === 'tr_pr') ? 'captured' : 'authorized';

		$counters = $service->pollPending($provider);

		self::assertSame(2, $counters['scanned']);
		self::assertSame(2, $counters['reconciled']);
	}//end testPollPendingCoversBothSchemas()

	/**
	 * A status-provider exception for one record does not abort the whole poll.
	 *
	 * @return void
	 */
	public function testPollPendingSurvivesProviderError(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			['PaymentRequest' => [['paymentIntentId' => 'tr_pr', 'state' => 'pending', 'paymentGateway' => 'mollie']]],
			$saved
		);
		$service = $this->makeService($stub);

		$provider = static function (string $intentId): ?string {
			throw new \RuntimeException('connector down');
		};

		$counters = $service->pollPending($provider);

		self::assertSame(1, $counters['scanned']);
		self::assertSame(0, $counters['reconciled']);
		self::assertCount(0, $saved);
	}//end testPollPendingSurvivesProviderError()
	/**
	 * Build the service with a revenue-account mapping in app config, so the
	 * object branch has somewhere to book (REQ-SOPR-002).
	 *
	 * @param object $objectService The ObjectService stub.
	 * @param array<string, string> $accounts Request type to account number.
	 * @param ?ObjectTransitionRunner $transitions The transition runner, a double when absent.
	 *
	 * @return PaymentReconciliationService
	 */
	private function makeServiceWithAccounts(object $objectService, array $accounts, ?ObjectTransitionRunner $transitions = null): PaymentReconciliationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return ($key === 'register' ? 'shillinq' : $default);
			}
		);

		$accountConfig = $this->createMock(IAppConfig::class);
		$accountConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($accounts): string {
				if ($key === PaymentRevenueAccountResolver::CONFIG_KEY) {
					return json_encode($accounts, JSON_THROW_ON_ERROR);
				}

				return ($key === 'register' ? 'shillinq' : $default);
			}
		);

		return new PaymentReconciliationService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter(inner: $objectService),
			revenueAccounts: new PaymentRevenueAccountResolver(appConfig: $accountConfig),
			transitions: ($transitions ?? $this->createMock(ObjectTransitionRunner::class)),
		);
	}//end makeServiceWithAccounts()

	/**
	 * A well-formed pending object request standing on a case.
	 *
	 * @param string $requestType The request type.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function objectRequest(string $requestType): array {
		return [
			'id' => 'pr-obj-1',
			'paymentIntentId' => 'tr_obj',
			'state' => 'pending',
			'subjectKind' => 'object',
			'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-7'],
			'requestType' => $requestType,
			'amount' => 250.0,
			'currency' => 'EUR',
		];
	}//end objectRequest()

	/**
	 * A captured dwangsom on a case books ONE GLTransaction with a credit line on
	 * the mapped account, for the request's own amount, carrying the case in the
	 * memo. The request itself reaches captured and stamps the account it used
	 * (REQ-SOPR-002).
	 *
	 * @return void
	 */
	public function testCapturedObjectRequestBooksOnTheMappedRevenueAccount(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [$this->objectRequest('dwangsom')]], $saved);
		$service = $this->makeServiceWithAccounts($stub, ['dwangsom' => '8400', 'clearing' => '1100']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);

		$transactions = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'JournalEntry'));
		self::assertCount(1, $transactions);

		$lines = $transactions[0]['object']['lines'];
		$credit = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertCount(1, $credit);
		self::assertSame('8400', $credit[0]['accountNumber']);
		self::assertSame(250.0, $credit[0]['amount']);
		self::assertStringContainsString('zaak-7', (string)$transactions[0]['object']['description']);

		$request = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'))[0]['object'];
		self::assertSame('captured', $request['state']);
		self::assertSame('8400', $request['revenueAccount']);
	}//end testCapturedObjectRequestBooksOnTheMappedRevenueAccount()

	/**
	 * A captured event fee books against the account mapped to event-fee
	 * (REQ-ORS-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-001)
	 */
	public function testACapturedEventFeeBooksOnItsOwnAccount(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [$this->objectRequest('event-fee')]], $saved);
		$service = $this->makeServiceWithAccounts($stub, ['event-fee' => '8050', 'other' => '8999', 'clearing' => '1100']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		$transactions = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'JournalEntry'));
		$credit = array_values(array_filter($transactions[0]['object']['lines'], static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertSame('8050', $credit[0]['accountNumber']);
	}//end testACapturedEventFeeBooksOnItsOwnAccount()

	/**
	 * An unmapped request type books NOTHING and leaves the request in
	 * captured_unapplied with a reason naming the type. A receipt on a guessed
	 * account is worse than a receipt that waits (REQ-SOPR-002).
	 *
	 * @return void
	 */
	public function testUnmappedRequestTypeDoesNotBook(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [$this->objectRequest('deposit')]], $saved);
		$service = $this->makeServiceWithAccounts($stub, ['dwangsom' => '8400']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_UNAPPLIED, $out['result']);

		$schemas = array_map(static fn (array $s): string => $s['schema'], $saved);
		self::assertNotContains('GLTransaction', $schemas);

		$request = array_values(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'PaymentRequest'))[0]['object'];
		self::assertSame('captured_unapplied', $request['state']);
		self::assertStringContainsString('deposit', (string)$request['failureReason']);
	}//end testUnmappedRequestTypeDoesNotBook()

	/**
	 * An object request never reaches the invoice branch: there is no invoice, and
	 * a capture that fell through to settleLinkedInvoice() would land in
	 * captured_unapplied for the wrong reason. The tell is that the invoice in the
	 * store is untouched (REQ-SOPR-001).
	 *
	 * @return void
	 */
	public function testObjectRequestDoesNotSettleAnInvoice(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [$this->objectRequest('leges')],
				'ARInvoice' => [['id' => 'inv-1', 'state' => 'issued']],
			],
			$saved
		);
		$service = $this->makeServiceWithAccounts($stub, ['leges' => '8300', 'clearing' => '1100']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		$schemas = array_map(static fn (array $s): string => $s['schema'], $saved);
		self::assertNotContains('ARInvoice', $schemas);
	}//end testObjectRequestDoesNotSettleAnInvoice()

	/**
	 * A pending school contribution: an object request with its invoice behind it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function contributionRequest(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pr-ctb-1',
				'paymentIntentId' => 'tr_ctb',
				'state' => 'pending',
				'subjectKind' => 'object',
				'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
				'beneficiary' => ['type' => 'learner', 'id' => 'child-a'],
				'requestType' => 'contribution',
				'invoiceReference' => 'inv-ctb-1',
				'amount' => 60.0,
				'currency' => 'EUR',
			],
			$overrides
		);
	}//end contributionRequest()

	/**
	 * The saved objects of one schema.
	 *
	 * @param array<int, array{schema: string, object: array<string, mixed>}> $saved The sink.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function savedIn(array $saved, string $schema): array {
		return array_values(
			array_map(
				static fn (array $s): array => $s['object'],
				array_filter($saved, static fn (array $s): bool => $s['schema'] === $schema)
			)
		);
	}//end savedIn()

	/**
	 * A captured contribution settles its invoice through `lifecycleState` and
	 * books no object receipt, so the income is booked once (REQ-SCON-006).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-006)
	 */
	public function testAnInvoiceBackedObjectRequestSettlesItsInvoiceAndBooksNoReceipt(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [$this->contributionRequest()],
				'ARInvoice' => [['id' => 'inv-ctb-1', 'lifecycleState' => 'issued', 'invoiceNumber' => 'CTB-2026-1A2B3C4D-0001']],
			],
			$saved
		);
		$service = $this->makeServiceWithAccounts($stub, ['contribution' => '8400', 'clearing' => '1100']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_ctb', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		self::assertSame([], $this->savedIn($saved, 'GLTransaction'));

		$invoice = $this->savedIn($saved, 'ARInvoice')[0];
		self::assertSame('paid', $invoice['lifecycleState']);
		self::assertArrayNotHasKey('state', $invoice);

		$request = $this->savedIn($saved, 'PaymentRequest')[0];
		self::assertSame('captured', $request['state']);
		self::assertStringContainsString('CTB-2026-1A2B3C4D-0001', (string)$request['confirmationSummary']);
		self::assertArrayNotHasKey('revenueAccount', $request);
	}//end testAnInvoiceBackedObjectRequestSettlesItsInvoiceAndBooksNoReceipt()

	/**
	 * A captured event fee that asked for an invoice at create settles that
	 * invoice and books no object receipt, so the income is booked once
	 * (REQ-ORS-006).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function testCaptureSettlesTheInvoiceAndBooksNoObjectReceipt(): void {
		$saved = [];
		$request = array_merge(
			$this->objectRequest('event-fee'),
			['invoiceRequested' => true, 'invoiceReference' => 'inv-req-1', 'paymentReference' => 'WC26-0042']
		);
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [$request],
				'ARInvoice' => [['id' => 'inv-req-1', 'lifecycleState' => 'issued', 'invoiceNumber' => 'REQ-2026-0A1B2C3D4E']],
			],
			$saved
		);
		$service = $this->makeServiceWithAccounts($stub, ['event-fee' => '8050', 'clearing' => '1100']);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		self::assertSame([], $this->savedIn($saved, 'GLTransaction'));
		self::assertSame([], $this->savedIn($saved, 'JournalEntry'));
		self::assertSame('paid', $this->savedIn($saved, 'ARInvoice')[0]['lifecycleState']);
		self::assertSame('captured', $this->savedIn($saved, 'PaymentRequest')[0]['state']);
	}//end testCaptureSettlesTheInvoiceAndBooksNoObjectReceipt()

	/**
	 * A plain invoice request against an invoice that only carries
	 * `lifecycleState` settles it. Before this change the read took `state` and
	 * every such capture landed in captured_unapplied (REQ-SCON-006).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-006)
	 */
	public function testAnInvoiceCarryingOnlyLifecycleStateIsSettled(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [['paymentIntentId' => 'tr_1', 'state' => 'pending', 'invoiceReference' => 'inv-1', 'amount' => 121.0]],
				'ARInvoice' => [['id' => 'inv-1', 'lifecycleState' => 'overdue']],
			],
			$saved
		);

		$out = $this->makeService($stub)->reconcile('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		self::assertSame('paid', $this->savedIn($saved, 'ARInvoice')[0]['lifecycleState']);
	}//end testAnInvoiceCarryingOnlyLifecycleStateIsSettled()

	/**
	 * The first capture writes the settled edge, `settledAt` and
	 * `settledVia = provider`, in the save that moves the request to captured;
	 * a replayed webhook saves nothing, so no second edge (REQ-SCON-009).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-009)
	 */
	public function testACaptureStampsTheSettledEdgeOnce(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [$this->contributionRequest()],
				'ARInvoice' => [['id' => 'inv-ctb-1', 'lifecycleState' => 'issued']],
			],
			$saved
		);

		$this->makeService($stub)->reconcile('mollie', ['paymentIntentId' => 'tr_ctb', 'outcome' => 'captured']);

		$request = $this->savedIn($saved, 'PaymentRequest')[0];
		self::assertSame('provider', $request['settledVia']);
		self::assertSame($request['capturedAt'], $request['settledAt']);

		$replaySaved = [];
		$replayStub = $this->buildObjectServiceStub(['PaymentRequest' => [$request]], $replaySaved);
		$replay = $this->makeService($replayStub)->reconcile('mollie', ['paymentIntentId' => 'tr_ctb', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_NOOP, $replay['result']);
		self::assertSame([], $replaySaved);
	}//end testACaptureStampsTheSettledEdgeOnce()

	/**
	 * A capture that could not settle its invoice is not a settled request, so
	 * no edge is written (REQ-SCON-009).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-009)
	 */
	public function testAnUnappliedCaptureWritesNoSettledEdge(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				'PaymentRequest' => [$this->contributionRequest()],
				'ARInvoice' => [['id' => 'inv-ctb-1', 'lifecycleState' => 'written-off']],
			],
			$saved
		);

		$out = $this->makeService($stub)->reconcile('mollie', ['paymentIntentId' => 'tr_ctb', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_UNAPPLIED, $out['result']);
		self::assertArrayNotHasKey('settledAt', $this->savedIn($saved, 'PaymentRequest')[0]);
	}//end testAnUnappliedCaptureWritesNoSettledEdge()

	/**
	 * The receipt for a captured object request is a JournalEntry the merged
	 * register accepts, posted through its declared `postDirect` transition. A
	 * GLTransaction saved with inline line objects is refused by OpenRegister
	 * (`lines` holds GLLine uuids), so the capture booked nothing live.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function testTheObjectReceiptIsAJournalEntryTheRegisterAccepts(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [$this->objectRequest('dwangsom')]], $saved);
		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$runs = [];
		$transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use (&$runs): void {
				$runs[] = [$objectId, $action];
			}
		);
		$service = $this->makeServiceWithAccounts($stub, ['dwangsom' => '8400', 'clearing' => '1100'], $transitions);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_APPLIED, $out['result']);
		foreach (['GLTransaction', 'JournalEntry'] as $schema) {
			foreach ($this->savedIn($saved, $schema) as $object) {
				self::assertSame([], RegisterSchema::errors($schema, $object), $schema . ' payload refused by the merged register');
			}
		}

		self::assertSame([], $this->savedIn($saved, 'GLTransaction'));
		$journals = $this->savedIn($saved, 'JournalEntry');
		self::assertCount(1, $journals);
		self::assertSame('draft', $journals[0]['state']);
		$journalIndex = array_search('JournalEntry', array_column($saved, 'schema'), true);
		self::assertSame([['saved-' . ($journalIndex + 1), 'postDirect']], $runs);
	}//end testTheObjectReceiptIsAJournalEntryTheRegisterAccepts()

	/**
	 * A posting the ledger refuses leaves the request in captured_unapplied
	 * with the reason, rather than a capture that claims a receipt it never
	 * booked (REQ-SOPR-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function testARefusedPostingLeavesTheCaptureUnapplied(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(['PaymentRequest' => [$this->objectRequest('dwangsom')]], $saved);
		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$transitions->method('run')->willThrowException(new \RuntimeException('period 2026-10 is closed'));
		$service = $this->makeServiceWithAccounts($stub, ['dwangsom' => '8400', 'clearing' => '1100'], $transitions);

		$out = $service->reconcile('mollie', ['paymentIntentId' => 'tr_obj', 'outcome' => 'captured']);

		self::assertSame(PaymentReconciliationService::RESULT_UNAPPLIED, $out['result']);
		$request = $this->savedIn($saved, 'PaymentRequest')[0];
		self::assertSame('captured_unapplied', $request['state']);
		self::assertStringContainsString('period 2026-10 is closed', (string)($request['failureReason'] ?? ''));
	}//end testARefusedPostingLeavesTheCaptureUnapplied()
}//end class
