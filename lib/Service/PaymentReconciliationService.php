<?php

/**
 * Shillinq Payment Reconciliation Service (shared deposits + invoice payment links)
 *
 * Idempotently reconciles a payment record against an outcome reported by the
 * gateway — via the shared webhook controller (REQ-APL-004) or the polling
 * fallback for missed webhooks. This is the GENERALIZED superset of
 * DepositReconciliationService: it resolves the record by paymentIntentId across
 * BOTH the PaymentRequest schema (AR invoice payment links) AND the
 * DepositPayment schema (booking deposits), applying the idempotent state flip
 * through one code path (REQ-APL-004 "ONE shared surface — never a fork").
 *
 * On capture of a PaymentRequest the linked ARInvoice is settled through its
 * existing matchPaid lifecycle transition (AR core REQ-AR-004 owns GL settlement
 * posting — this service posts nothing). If that settlement is impossible the
 * request enters captured_unapplied — surfaced, never silently dropped
 * (REQ-APL-005).
 *
 * Per ADR-031 this is the single-method orchestration exception: the declarative
 * x-openregister-lifecycle / -notifications on the schemas own the business
 * outcomes; this service only performs the idempotent, signature-gated
 * lookup-and-state-flip that an async caller requires. It mirrors
 * DepositReconciliationService's idempotency + fail-closed structure exactly.
 *
 * NOTE (consolidation): DepositReconciliationService is intentionally left
 * untouched so the proven deposits path does not regress. This service is the
 * invoice-capable shared superset that coexists with it; a future change merges
 * the two onto this single path once both have soaked. Neither stores any PCI
 * data — only opaque references (REQ-APL-001 / REQ-DP-001).
 *
 * portal-payment-initiation (REQ-SPPI-005) adds one scalar write on the same
 * capture path: a subject-safe `confirmationSummary` on the PaymentRequest, so
 * a debtor who paid through the portal's subject-initiated `pay` action sees a
 * plain-language receipt through the existing read-only `paymentRequests`
 * portal collection — no dedicated inbox schema, no new orchestration path.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004, REQ-APL-005, REQ-APL-006)
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-005)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Idempotent reconciliation of PaymentRequest AND DepositPayment records against
 * gateway outcomes through one shared code path.
 *
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004, REQ-APL-005)
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class PaymentReconciliationService {
	/**
	 * Outcome: the payment was authorized by the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_AUTHORIZED = 'authorized';

	/**
	 * Outcome: the payment was captured/settled at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_CAPTURED = 'captured';

	/**
	 * Outcome: the payment was rejected/failed at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Outcome: the payment was voided/refunded at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_VOIDED = 'voided';

	/**
	 * Result: the outcome was applied (state changed).
	 *
	 * @var string
	 */
	public const RESULT_APPLIED = 'applied';

	/**
	 * Result: the capture applied but the invoice could not be settled — the
	 * request entered captured_unapplied (REQ-APL-005 exception path).
	 *
	 * @var string
	 */
	public const RESULT_UNAPPLIED = 'captured-unapplied';

	/**
	 * Result: idempotent no-op — the record was already in (or beyond) the
	 * target state.
	 *
	 * @var string
	 */
	public const RESULT_NOOP = 'noop';

	/**
	 * Result: no record matched the payment intent in either schema.
	 *
	 * @var string
	 */
	public const RESULT_NOT_FOUND = 'not-found';

	/**
	 * The PaymentRequest schema slug (AR invoice payment links).
	 *
	 * @var string
	 */
	private const SCHEMA_PAYMENT_REQUEST = 'PaymentRequest';

	/**
	 * The DepositPayment schema slug (booking deposits) — resolved through the
	 * SAME path so deposits and invoice links share one reconciliation surface.
	 *
	 * @var string
	 */
	private const SCHEMA_DEPOSIT_PAYMENT = 'DepositPayment';

	/**
	 * Schemas this shared service resolves a payment intent against, in order.
	 * PaymentRequest first (this change's record type), then DepositPayment.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMAS = [
		self::SCHEMA_PAYMENT_REQUEST,
		self::SCHEMA_DEPOSIT_PAYMENT,
	];

	/**
	 * Target lifecycle state per outcome.
	 *
	 * @var array<string, string>
	 */
	private const TARGET_STATE = [
		self::OUTCOME_AUTHORIZED => 'authorized',
		self::OUTCOME_CAPTURED => 'captured',
		self::OUTCOME_FAILED => 'failed',
		self::OUTCOME_VOIDED => 'voided',
	];

	/**
	 * States that are terminal/already-settled and must not be downgraded by a
	 * late or replayed webhook. captured / captured_unapplied / voided are
	 * sticky; a replayed capture on an already-captured record is a no-op
	 * (REQ-APL-004 idempotency).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ALREADY_SETTLED = [
		self::OUTCOME_AUTHORIZED => ['authorized', 'captured', 'captured_unapplied', 'voided'],
		self::OUTCOME_CAPTURED => ['captured', 'captured_unapplied', 'voided'],
		self::OUTCOME_FAILED => ['failed', 'authorized', 'captured', 'captured_unapplied', 'voided'],
		self::OUTCOME_VOIDED => ['captured', 'captured_unapplied', 'voided'],
	];

	/**
	 * Constructor for PaymentReconciliationService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger (never receives raw payment data).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Reconcile a single payment record against a reported outcome.
	 *
	 * Resolves the record by paymentIntentId across BOTH the PaymentRequest and
	 * DepositPayment schemas (REQ-APL-004). Idempotent: if the record is already
	 * in (or beyond) the target state, the call is a no-op and no invoice is
	 * settled twice (REQ-APL-004 replay safety). The lookup is by
	 * paymentIntentId, scoping the write to exactly the matched record (no IDOR —
	 * the gateway never supplies a record id). On capture of a PaymentRequest
	 * the linked ARInvoice is settled through its existing lifecycle; if that is
	 * impossible the request lands in captured_unapplied (REQ-APL-005).
	 *
	 * @param string $gateway The gateway slug ('mollie'|'stripe').
	 * @param array<string, mixed> $event Normalised event with at least
	 *                                    'paymentIntentId' and 'outcome'; optional
	 *                                    'errorCode', 'errorMessage',
	 *                                    'settlementReference', 'gatewayFeeAmount'.
	 *
	 * @return array{result: string, schema: ?string} Result constant + which schema matched (or null).
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004, REQ-APL-005, REQ-APL-006)
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-005)
	 */
	public function reconcile(string $gateway, array $event): array {
		$paymentIntentId = (string)($event['paymentIntentId'] ?? '');
		$outcome = (string)($event['outcome'] ?? '');

		if (isset(self::TARGET_STATE[$outcome]) === false) {
			return ['result' => self::RESULT_NOOP, 'schema' => null];
		}

		if ($paymentIntentId === '') {
			return ['result' => self::RESULT_NOT_FOUND, 'schema' => null];
		}

		$registerSlug = $this->getRegisterSlug();

		// Resolve across both schemas — the shared surface (REQ-APL-004).
		$record = null;
		$schema = null;
		foreach (self::SCHEMAS as $candidateSchema) {
			$matches = $this->objectService
				->setRegister($registerSlug)
				->setSchema($candidateSchema)
				->findAll(
					[
						'filters' => ['paymentIntentId' => $paymentIntentId],
						'limit' => 1,
					]
				);

			if (empty($matches) === false) {
				$record = $matches[0];
				$schema = $candidateSchema;
				break;
			}
		}

		if ($record === null || $schema === null) {
			return ['result' => self::RESULT_NOT_FOUND, 'schema' => null];
		}

		$currentState = (string)($record['state'] ?? 'pending');
		$targetState = self::TARGET_STATE[$outcome];

		// Idempotency / no-downgrade guard: a replayed or late webhook on an
		// already-settled record does nothing (REQ-APL-004 replay safety).
		if (in_array($currentState, self::ALREADY_SETTLED[$outcome], true) === true) {
			$this->logger->debug(
				'Shillinq: payment webhook is an idempotent no-op',
				['gateway' => $gateway, 'schema' => $schema, 'currentState' => $currentState, 'outcome' => $outcome]
			);
			return ['result' => self::RESULT_NOOP, 'schema' => $schema];
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');

		$record['state'] = $targetState;
		$record['paymentGateway'] = $gateway;

		if ($outcome === self::OUTCOME_CAPTURED) {
			$record['capturedAt'] = $now;
			if (isset($event['settlementReference']) === true) {
				$record['settlementReference'] = (string)$event['settlementReference'];
			}

			if (isset($event['gatewayFeeAmount']) === true) {
				$record['gatewayFeeAmount'] = (float)$event['gatewayFeeAmount'];
			}
		} elseif ($outcome === self::OUTCOME_FAILED) {
			// Persist the operator-readable failure reason — never raw card data
			// (REQ-APL-001 / mirrors REQ-DP-011).
			$record['failureReason'] = (string)($event['errorMessage'] ?? 'Payment failed at gateway.');
		}

		// On capture of a PaymentRequest, settle the linked ARInvoice through its
		// existing lifecycle (AR core owns GL posting). If that is impossible the
		// request lands in captured_unapplied — surfaced, never silently dropped
		// (REQ-APL-005). DepositPayment capture handling is unchanged from the
		// deposits path (its own lifecycle owns AR materialisation).
		if ($outcome === self::OUTCOME_CAPTURED && $schema === self::SCHEMA_PAYMENT_REQUEST) {
			$settledInvoice = $this->settleLinkedInvoice(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				request: $record,
			);
			if ($settledInvoice === null) {
				$record['state'] = 'captured_unapplied';
				$this->objectService->saveObject(
					object: $record,
					register: $registerSlug,
					schema: $schema,
				);
				return ['result' => self::RESULT_UNAPPLIED, 'schema' => $schema];
			}

			// Write a subject-safe confirmation the debtor reads through the
			// existing read-only paymentRequests portal collection — no
			// dedicated inbox schema (portal-payment-initiation REQ-SPPI-005).
			$record['confirmationSummary'] = $this->buildConfirmationSummary(invoice: $settledInvoice, request: $record);
		}//end if

		$this->objectService->saveObject(
			object: $record,
			register: $registerSlug,
			schema: $schema,
		);

		return ['result' => self::RESULT_APPLIED, 'schema' => $schema];
	}//end reconcile()

	/**
	 * Settle the ARInvoice linked to a captured PaymentRequest through the AR
	 * lifecycle (REQ-APL-005). AR core's matchPaid transition owns GL settlement
	 * posting, dunning stop, and ageing — this method posts nothing; it triggers
	 * the transition with the PaymentRequest as payment evidence.
	 *
	 * Returns null (so the caller sets captured_unapplied) when the invoice is
	 * missing or already in a non-settleable state (paid / written-off / voided /
	 * credited) — REQ-APL-005's "capture against an already-settled invoice
	 * becomes an exception, not a silent drop". On success, returns the
	 * just-settled invoice so the caller can compose the portal-payment-initiation
	 * REQ-SPPI-005 confirmation summary (invoiceNumber) without a second read.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param array<string, mixed> $request The captured PaymentRequest record.
	 *
	 * @return array<string, mixed>|null The settled ARInvoice, or null to route to captured_unapplied.
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-005)
	 */
	private function settleLinkedInvoice(object $objectService, string $registerSlug, array $request): ?array {
		$invoiceRef = (string)($request['invoiceReference'] ?? '');
		if ($invoiceRef === '') {
			$this->logger->warning('Shillinq: captured PaymentRequest has no invoiceReference; routing to captured_unapplied');
			return null;
		}

		try {
			// The by-id arm was findAll(['filters' => ['id' => …]]), which
			// matches NOTHING — `filters` addresses JSON properties and the
			// entity's `id` is not one — so every reference fell through to
			// the slug arm and an invoice referenced by uuid never reconciled.
			$byId = ObjectIdentifier::findOne(
				scoped: $objectService
					->setRegister($registerSlug)
					->setSchema('ARInvoice'),
				id: $invoiceRef
			);
			$invoices = [];
			if ($byId !== null) {
				$invoices = [$byId];
			}

			if (empty($invoices) === true) {
				$invoices = $objectService
					->setRegister($registerSlug)
					->setSchema('ARInvoice')
					->findAll(
						[
							'filters' => ['slug' => $invoiceRef],
							'limit' => 1,
						]
					);
			}

			if (empty($invoices) === true) {
				$this->logger->warning(
					'Shillinq: captured PaymentRequest references an unknown ARInvoice; routing to captured_unapplied',
					['invoiceReference' => $invoiceRef]
				);
				return null;
			}

			$invoice = $invoices[0];
			$invoiceState = (string)($invoice['state'] ?? '');

			// The AR matchPaid transition only fires from issued / partially-paid
			// / overdue. Any other state (already paid, written-off, voided,
			// disputed) means we cannot settle — surface as an exception.
			if (in_array($invoiceState, ['issued', 'partially-paid', 'overdue'], true) === false) {
				$this->logger->info(
					'Shillinq: ARInvoice is not in a settleable state for the captured payment; routing to captured_unapplied',
					['invoiceReference' => $invoiceRef, 'invoiceState' => $invoiceState]
				);
				return null;
			}

			// Trigger the existing AR lifecycle transition to paid, with the
			// PaymentRequest as payment evidence. AR core owns the GL posting.
			$invoice['state'] = 'paid';
			$invoice['paymentEvidenceRef'] = (string)($request['paymentIntentId'] ?? '');
			$invoice['settlementReference'] = (string)($request['settlementReference'] ?? '');

			$objectService->saveObject(
				object: $invoice,
				register: $registerSlug,
				schema: 'ARInvoice',
			);

			// Post the realised FX gain/loss when the invoice was issued in a
			// foreign currency and settles at a different rate (REQ-MC-010).
			// Fail-open: a realised-FX resolution gap never un-settles a paid
			// invoice — the payment stands, the FX leg is logged and skipped.
			$this->postRealisedFxOnSettlement(invoice: $invoice, request: $request);

			return $invoice;
		} catch (\Throwable $e) {
			// Never silently drop a captured payment (REQ-APL-005). Routing to
			// captured_unapplied surfaces the exception for operator action.
			$this->logger->error(
				'Shillinq: ARInvoice settlement for a captured payment failed; routing to captured_unapplied',
				['invoiceReference' => $invoiceRef, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end settleLinkedInvoice()

	/**
	 * Compose the subject-safe settlement confirmation (portal-payment-initiation,
	 * REQ-SPPI-005) — a plain-language receipt the debtor reads through the
	 * existing read-only paymentRequests portal collection. Never includes any
	 * internal/PCI detail — only the invoice's own human-readable number, the
	 * capture date and the opaque settlement/payment reference.
	 *
	 * @param array<string, mixed> $invoice The just-settled ARInvoice (state=paid).
	 * @param array<string, mixed> $request The captured PaymentRequest record.
	 *
	 * @return string The confirmation summary.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-005)
	 */
	private function buildConfirmationSummary(array $invoice, array $request): string {
		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? ($request['invoiceReference'] ?? ''));

		$capturedAt = (string)($request['capturedAt'] ?? '');
		$date = gmdate('Y-m-d');
		if ($capturedAt !== '') {
			$date = substr($capturedAt, 0, 10);
		}

		$reference = (string)($request['settlementReference'] ?? ($request['paymentIntentId'] ?? ''));

		return sprintf('Invoice %s paid on %s, reference %s.', $invoiceNumber, $date, $reference);
	}//end buildConfirmationSummary()

	/**
	 * Post the settlement-time realised FX gain/loss for a just-paid ARInvoice
	 * through RealisedFxSettlementService (REQ-MC-010). The settlement rate is
	 * taken from the PaymentRequest when the gateway reported one
	 * (`settlementFxRate`), otherwise the service resolves it from the FxRate
	 * register at the payment date. Fail-open: any failure is logged and
	 * swallowed so a foreign-currency invoice still settles.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice that was just marked paid.
	 * @param array<string, mixed> $request The captured PaymentRequest (may carry settlementFxRate/capturedAt).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md (REQ-MC-010)
	 */
	private function postRealisedFxOnSettlement(array $invoice, array $request): void {
		try {
			$service = $this->container->get('OCA\Shillinq\Service\Treasury\RealisedFxSettlementService');

			$settlementRate = null;
			if (isset($request['settlementFxRate']) === true && (float)$request['settlementFxRate'] > 0.0) {
				$settlementRate = (float)$request['settlementFxRate'];
			}

			$settlementDate = null;
			if (isset($request['capturedAt']) === true && (string)$request['capturedAt'] !== '') {
				$settlementDate = substr((string)$request['capturedAt'], 0, 10);
			}

			$service->postRealisedFxOnSettlement(
				invoice: $invoice,
				settlementRate: $settlementRate,
				settlementDate: $settlementDate,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: realised-FX posting on settlement failed (payment still settled)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end postRealisedFxOnSettlement()

	/**
	 * Polling fallback for missed webhooks (REQ-APL-004 polling fallback).
	 *
	 * Queries all PaymentRequest AND DepositPayment records still in `pending`
	 * (the same shared service — no second job) and reconciles each against the
	 * gateway status reported by $statusProvider. The provider is a callable so
	 * the live OpenConnector.getPaymentStatus() call can be injected at runtime
	 * and mocked in tests; it receives the paymentIntentId and MUST return one of
	 * the OUTCOME_* constants, or null/'' to leave the record pending.
	 *
	 * The actual scheduling is declared on the schemas as an
	 * x-openregister-scheduled-workflow (ADR-031) — no app-local TimedJob exists.
	 *
	 * @param callable(string): ?string $statusProvider Maps a paymentIntentId to an OUTCOME_* or null.
	 *
	 * @return array{scanned: int, reconciled: int} Counters for observability.
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004)
	 */
	public function pollPending(callable $statusProvider): array {
		$registerSlug = $this->getRegisterSlug();

		$pageSize = 200;
		$scanned = 0;
		$reconciled = 0;

		foreach (self::SCHEMAS as $schema) {
			$page = 1;
			$batchSize = 0;

			do {
				$batch = $this->objectService
					->setRegister($registerSlug)
					->setSchema($schema)
					->findAll(
						[
							'filters' => ['state' => 'pending'],
							'limit' => $pageSize,
							'offset' => (($page - 1) * $pageSize),
						]
					);
				$batchSize = count($batch);

				foreach ($batch as $record) {
					$scanned++;
					$intentId = (string)($record['paymentIntentId'] ?? '');
					if ($intentId === '') {
						continue;
					}

					try {
						$outcome = $statusProvider($intentId);
					} catch (\Throwable $e) {
						$this->logger->warning(
							'Shillinq: payment polling status lookup failed',
							['exception' => $e->getMessage()]
						);
						continue;
					}

					if ($outcome === null || $outcome === '') {
						continue;
					}

					$result = $this->reconcile(
						gateway: (string)($record['paymentGateway'] ?? 'mollie'),
						event: [
							'paymentIntentId' => $intentId,
							'outcome' => $outcome,
						],
					);
					if ($result['result'] === self::RESULT_APPLIED || $result['result'] === self::RESULT_UNAPPLIED) {
						$reconciled++;
					}
				}//end foreach

				$page++;
			} while ($batchSize === $pageSize);
		}//end foreach

		return [
			'scanned' => $scanned,
			'reconciled' => $reconciled,
		];
	}//end pollPending()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug (defaults to 'shillinq').
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
