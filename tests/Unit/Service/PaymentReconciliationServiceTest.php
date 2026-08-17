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
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
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
}//end class
