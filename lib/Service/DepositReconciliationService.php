<?php

/**
 * Shillinq Deposit Reconciliation Service
 *
 * Idempotently reconciles a DepositPayment record with a payment outcome reported
 * by the gateway — via the webhook listener (REQ-DP-006) or the polling fallback
 * background job (REQ-DP-007). Both paths funnel through reconcile() so the
 * idempotency rule lives in exactly one place: an outcome is applied at most once.
 *
 * Per ADR-031 this is the single-method orchestration exception: the declarative
 * x-openregister-lifecycle on the DepositPayment schema owns the AR invoice / credit
 * note materialisation and notifications; this service only performs the idempotent
 * lookup-and-state-flip that an async, signature-verified caller requires.
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
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-007, REQ-DP-011)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentResult;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Idempotent reconciliation of DepositPayment records against gateway outcomes.
 *
 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-007)
 */
class DepositReconciliationService {
	/**
	 * Outcome: the payment was authorized by the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_AUTHORIZED = 'authorized';

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
	 * Result: idempotent no-op — the deposit was already in the target state.
	 *
	 * @var string
	 */
	public const RESULT_NOOP = 'noop';

	/**
	 * Result: no DepositPayment matched the payment intent.
	 *
	 * @var string
	 */
	public const RESULT_NOT_FOUND = 'not-found';

	/**
	 * Target lifecycle state per outcome.
	 *
	 * @var array<string, string>
	 */
	private const TARGET_STATE = [
		self::OUTCOME_AUTHORIZED => 'authorized',
		self::OUTCOME_FAILED => 'failed',
		self::OUTCOME_VOIDED => 'voided',
	];

	/**
	 * States that are terminal/already-settled and must not be downgraded by a
	 * late or replayed webhook. Authorization, capture and void are sticky.
	 *
	 * @var array<string, array<string>>
	 */
	private const ALREADY_SETTLED = [
		self::OUTCOME_AUTHORIZED => ['authorized', 'captured', 'voided'],
		self::OUTCOME_FAILED => ['failed', 'authorized', 'captured', 'voided'],
		self::OUTCOME_VOIDED => ['voided'],
	];

	/**
	 * Constructor for DepositReconciliationService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param ?DepositPaymentAdapterInterface $adapter Optional DepositPayment lifecycle
	 *                                                 adapter. When supplied, the scheduled
	 *                                                 polling workflow (REQ-DP-007) can call
	 *                                                 `pollPendingViaAdapter()` to project
	 *                                                 gateway status onto OUTCOME_* without
	 *                                                 the caller having to know about the
	 *                                                 adapter at all. Optional so existing
	 *                                                 call sites that pass an explicit
	 *                                                 `pollPending($callable)` still work.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private ?DepositPaymentAdapterInterface $adapter = null,
	) {
	}//end __construct()

	/**
	 * Reconcile a single DepositPayment against a reported outcome.
	 *
	 * Idempotent: if the deposit is already in (or beyond) the target state for the
	 * given outcome, the call is a no-op and no AR invoice/credit note is duplicated
	 * (REQ-DP-006). The lookup is by paymentIntentId, scoping the write to exactly
	 * the matched record (no IDOR — the gateway never supplies a record id).
	 *
	 * @param string $paymentIntentId The opaque gateway payment intent reference.
	 * @param string $outcome One of the OUTCOME_* constants.
	 * @param string $gateway The gateway slug ('mollie'|'stripe').
	 * @param ?string $errorCode Machine error code on failure (REQ-DP-011).
	 * @param ?string $errorMessage Human-readable error on failure (REQ-DP-011).
	 *
	 * @return string One of the RESULT_* constants.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-011)
	 */
	public function reconcile(
		string $paymentIntentId,
		string $outcome,
		string $gateway,
		?string $errorCode = null,
		?string $errorMessage = null,
	): string {
		if (isset(self::TARGET_STATE[$outcome]) === false) {
			return self::RESULT_NOOP;
		}

		if ($paymentIntentId === '') {
			return self::RESULT_NOT_FOUND;
		}

		$registerSlug = $this->getRegisterSlug();

		$matches = $this->objectService
			->setRegister($registerSlug)
			->setSchema('DepositPayment')
			->findAll(
				[
					'filters' => ['paymentIntentId' => $paymentIntentId],
					'limit' => 1,
				]
			);

		if (empty($matches) === true) {
			return self::RESULT_NOT_FOUND;
		}

		$deposit = $matches[0];
		$currentState = (string)($deposit['state'] ?? 'draft');
		$targetState = self::TARGET_STATE[$outcome];

		// Idempotency / no-downgrade guard: if the deposit is already settled in a
		// way that supersedes this outcome, do nothing (REQ-DP-006).
		if (in_array($currentState, self::ALREADY_SETTLED[$outcome], true) === true) {
			$this->logger->debug(
				'Shillinq: deposit webhook is an idempotent no-op',
				['gateway' => $gateway, 'currentState' => $currentState, 'outcome' => $outcome]
			);
			return self::RESULT_NOOP;
		}

		$deposit['state'] = $targetState;
		$deposit['paymentGateway'] = $gateway;
		$deposit['lastWebhookAttempt'] = gmdate('Y-m-d\TH:i:s\Z');

		if ($outcome === self::OUTCOME_AUTHORIZED) {
			$deposit['authorizedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		} elseif ($outcome === self::OUTCOME_VOIDED) {
			$deposit['voidedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		} elseif ($outcome === self::OUTCOME_FAILED) {
			// REQ-DP-011: persist the failure reason for the operator UI. Never the
			// raw card data — the gateway error code/message only.
			$deposit['lastErrorCode'] = ($errorCode ?? 'payment_failed');
			$deposit['lastErrorMessage'] = ($errorMessage ?? 'Payment failed at gateway.');
		}

		$this->objectService->saveObject(
			object: $deposit,
			register: $registerSlug,
			schema: 'DepositPayment',
		);

		return self::RESULT_APPLIED;
	}//end reconcile()

	/**
	 * Polling fallback for missed webhooks (REQ-DP-007).
	 *
	 * Queries all DepositPayment records still in `pending` and reconciles each
	 * against the gateway status reported by $statusProvider. The provider is a
	 * callable so the live OpenConnector.getPaymentStatus() call can be injected at
	 * runtime and mocked in tests; it receives the paymentIntentId and MUST return
	 * one of the OUTCOME_* constants, or null/'' to leave the deposit pending.
	 *
	 * The actual scheduling is declared on the DepositPayment schema as an
	 * x-openregister-scheduled-workflow (ADR-031) — no app-local TimedJob exists.
	 *
	 * @param callable(string): ?string $statusProvider Maps a paymentIntentId to an OUTCOME_* or null.
	 *
	 * @return array{scanned: int, reconciled: int} Counters for observability.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-007)
	 */
	public function pollPending(callable $statusProvider): array {
		$registerSlug = $this->getRegisterSlug();

		$pageSize = 200;
		$page = 1;
		$scanned = 0;
		$reconciled = 0;
		$batchSize = 0;

		do {
			$batch = $this->objectService
				->setRegister($registerSlug)
				->setSchema('DepositPayment')
				->findAll(
					[
						'filters' => ['state' => 'pending'],
						'limit' => $pageSize,
						'offset' => (($page - 1) * $pageSize),
					]
				);
			$batchSize = count($batch);

			foreach ($batch as $deposit) {
				$scanned++;
				$intentId = (string)($deposit['paymentIntentId'] ?? '');
				if ($intentId === '') {
					continue;
				}

				try {
					$outcome = $statusProvider($intentId);
				} catch (\Throwable $e) {
					$this->logger->warning(
						'Shillinq: deposit polling status lookup failed',
						['exception' => $e->getMessage()]
					);
					continue;
				}

				if ($outcome === null || $outcome === '') {
					continue;
				}

				$result = $this->reconcile(
					paymentIntentId: $intentId,
					outcome: $outcome,
					gateway: (string)($deposit['paymentGateway'] ?? 'mollie'),
				);
				if ($result === self::RESULT_APPLIED) {
					$reconciled++;
				}
			}//end foreach

			$page++;
		} while ($batchSize === $pageSize);

		return [
			'scanned' => $scanned,
			'reconciled' => $reconciled,
		];
	}//end pollPending()

	/**
	 * Adapter-backed polling fallback (REQ-DP-007).
	 *
	 * Convenience wrapper that synthesises the status-provider callable
	 * from the injected `DepositPaymentAdapterInterface`. The scheduled
	 * polling workflow (`shillinq-deposit-polling-fallback`) hits this
	 * method so the lifecycle code never sees a Mollie-vs-Stripe branch —
	 * the adapter projects the gateway's raw status onto the
	 * DepositPayment lifecycle state (`pending`, `authorized`, `failed`,
	 * `voided`) and this service maps that lifecycle state to one of the
	 * OUTCOME_* constants. Dormant adapter outcomes (the
	 * `LogDepositPaymentAdapter` synthetic `pending` / `PAYMENT_DEFERRED`
	 * envelope) leave the deposit pending — no premature lifecycle
	 * advancement.
	 *
	 * When no adapter is bound, the method returns a zeroed counter pair
	 * and logs a warning so the scheduled-workflow tick is observable
	 * even on tenants that have not yet configured a PSP.
	 *
	 * @return array{scanned: int, reconciled: int} Counters for observability.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-007)
	 */
	public function pollPendingViaAdapter(): array {
		if ($this->adapter === null) {
			$this->logger->warning(
				'Shillinq DepositReconciliationService::pollPendingViaAdapter() called without an adapter bound — '
				. 'returning empty counters. Configure DepositPaymentAdapterInterface in Application::register() to enable.'
			);
			return ['scanned' => 0, 'reconciled' => 0];
		}

		$adapter = $this->adapter;
		$logger = $this->logger;

		return $this->pollPending(
			statusProvider: static function (string $intentId) use ($adapter, $logger): ?string {
				try {
					$result = $adapter->fetchStatus($intentId, '');
				} catch (\Throwable $e) {
					$logger->warning(
						'Shillinq DepositPayment fetchStatus threw — skipping for this tick',
						['paymentIntentId' => $intentId, 'exception' => $e->getMessage()]
					);
					return null;
				}

				// Dormant adapter MUST NOT advance the lifecycle (per
				// LogDepositPaymentAdapter docblock + REQ-DP-007).
				if ($result->dormant === true) {
					return null;
				}

				return self::lifecycleStateToOutcome(result: $result);
			}
		);
	}//end pollPendingViaAdapter()

	/**
	 * Map a `DepositPaymentResult.lifecycleState` to one of the
	 * `OUTCOME_*` constants the reconcile loop understands.
	 *
	 * Pure mapping — no side effects — pulled out so the test surface can
	 * verify the projection contract independent of the adapter wiring.
	 *
	 * @param DepositPaymentResult $result The adapter result.
	 *
	 * @return ?string One of OUTCOME_AUTHORIZED / OUTCOME_FAILED /
	 *                 OUTCOME_VOIDED, or NULL when the lifecycle state
	 *                 does not warrant a reconciliation advance (e.g.
	 *                 `pending`, `draft`, `captured` — `captured` is
	 *                 covered by the AUTHORIZED → CAPTURED transition
	 *                 the lifecycle owns separately).
	 */
	public static function lifecycleStateToOutcome(DepositPaymentResult $result): ?string {
		return match ($result->lifecycleState) {
			'authorized', 'captured' => self::OUTCOME_AUTHORIZED,
			'failed' => self::OUTCOME_FAILED,
			'voided' => self::OUTCOME_VOIDED,
			default => null,
		};
	}//end lifecycleStateToOutcome()

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
