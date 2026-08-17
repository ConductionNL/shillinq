<?php

/**
 * Unit tests for DepositReconciliationService.
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
 * @spec openspec/changes/bookings-deposits/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-007, REQ-DP-011)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DepositReconciliationService;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentResult;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the idempotent reconciliation + polling fallback (REQ-DP-006/007/011).
 */
final class DepositReconciliationServiceTest extends TestCase {
	/**
	 * Build a fluent ObjectService stub returning the given deposits from findAll()
	 * and recording every saveObject() call into a shared sink.
	 *
	 * @param array<int, array<string, mixed>> $deposits Records returned by findAll().
	 * @param array<int, array<string, mixed>> $saved Reference sink for saveObject().
	 *
	 * @return object The stub.
	 */
	private function buildObjectServiceStub(array $deposits, array &$saved): object {
		return new class($deposits, $saved) {
			/**
			 * @param array<int, array<string, mixed>> $deposits Records.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(
				private array $deposits,
				private array &$saved,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
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

				return $this->deposits;
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = $object;
				return $object;
			}
		};
	}//end buildObjectServiceStub()

	/**
	 * Build the service under test with a container yielding the given ObjectService.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return DepositReconciliationService
	 */
	private function makeService(object $objectService): DepositReconciliationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new DepositReconciliationService(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($objectService),
		);
	}//end makeService()

	/**
	 * Build a service with both an ObjectService stub and a deposit-payment
	 * adapter injected — used by the adapter-wired pollPending tests.
	 *
	 * @param object $objectService Stub.
	 * @param ?DepositPaymentAdapterInterface $adapter Adapter to inject (NULL for the
	 *                                                 adapter-absent path).
	 *
	 * @return DepositReconciliationService
	 */
	private function makeServiceWithAdapter(object $objectService, ?DepositPaymentAdapterInterface $adapter): DepositReconciliationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new DepositReconciliationService(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			adapter: $adapter,
			objectService: new DuckObjectServiceAdapter($objectService),
		);
	}//end makeServiceWithAdapter()

	/**
	 * Build a DepositPaymentResult with the given lifecycle state.
	 *
	 * @param string $lifecycleState The projected DepositPayment lifecycle state.
	 * @param bool $dormant TRUE for a synthetic dormant outcome.
	 *
	 * @return DepositPaymentResult
	 */
	private function buildAdapterResult(string $lifecycleState, bool $dormant = false): DepositPaymentResult {
		return new DepositPaymentResult(
			lifecycleState: $lifecycleState,
			gatewayStatus: $dormant ? 'PAYMENT_DEFERRED' : 'paid',
			paymentIntentId: 'tr_test',
			paymentLink: '',
			gateway: $dormant ? 'LOG_DEFERRED' : 'mollie',
			dormant: $dormant,
		);
	}//end buildAdapterResult()

	/**
	 * A pending deposit transitions to authorized and authorizedAt is set.
	 *
	 * @return void
	 */
	public function testAuthorizeTransitionsPendingToAuthorized(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_1', 'state' => 'pending']],
			$saved
		);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: 'tr_1',
			outcome: DepositReconciliationService::OUTCOME_AUTHORIZED,
			gateway: 'mollie',
		);

		self::assertSame(DepositReconciliationService::RESULT_APPLIED, $result);
		self::assertCount(1, $saved);
		self::assertSame('authorized', $saved[0]['state']);
		self::assertSame('mollie', $saved[0]['paymentGateway']);
		self::assertArrayHasKey('authorizedAt', $saved[0]);
	}//end testAuthorizeTransitionsPendingToAuthorized()

	/**
	 * Replaying an authorize webhook on an already-authorized deposit is a no-op
	 * and writes nothing — no double AR invoice (REQ-DP-006 idempotency).
	 *
	 * @return void
	 */
	public function testAuthorizeIsIdempotentOnAlreadyAuthorized(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_1', 'state' => 'authorized']],
			$saved
		);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: 'tr_1',
			outcome: DepositReconciliationService::OUTCOME_AUTHORIZED,
			gateway: 'mollie',
		);

		self::assertSame(DepositReconciliationService::RESULT_NOOP, $result);
		self::assertCount(0, $saved);
	}//end testAuthorizeIsIdempotentOnAlreadyAuthorized()

	/**
	 * A late "authorized" webhook must not downgrade a voided deposit.
	 *
	 * @return void
	 */
	public function testAuthorizeDoesNotDowngradeVoided(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_1', 'state' => 'voided']],
			$saved
		);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: 'tr_1',
			outcome: DepositReconciliationService::OUTCOME_AUTHORIZED,
			gateway: 'mollie',
		);

		self::assertSame(DepositReconciliationService::RESULT_NOOP, $result);
		self::assertCount(0, $saved);
	}//end testAuthorizeDoesNotDowngradeVoided()

	/**
	 * A failed outcome records the error code/message for the operator UI (REQ-DP-011).
	 *
	 * @return void
	 */
	public function testFailedRecordsErrorDetails(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'pi_x', 'state' => 'pending']],
			$saved
		);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: 'pi_x',
			outcome: DepositReconciliationService::OUTCOME_FAILED,
			gateway: 'stripe',
			errorCode: 'insufficient_funds',
			errorMessage: 'Insufficient funds.',
		);

		self::assertSame(DepositReconciliationService::RESULT_APPLIED, $result);
		self::assertSame('failed', $saved[0]['state']);
		self::assertSame('insufficient_funds', $saved[0]['lastErrorCode']);
		self::assertSame('Insufficient funds.', $saved[0]['lastErrorMessage']);
	}//end testFailedRecordsErrorDetails()

	/**
	 * No matching deposit yields RESULT_NOT_FOUND and no write.
	 *
	 * @return void
	 */
	public function testNoMatchReturnsNotFound(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub([], $saved);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: 'tr_missing',
			outcome: DepositReconciliationService::OUTCOME_AUTHORIZED,
			gateway: 'mollie',
		);

		self::assertSame(DepositReconciliationService::RESULT_NOT_FOUND, $result);
		self::assertCount(0, $saved);
	}//end testNoMatchReturnsNotFound()

	/**
	 * An empty payment intent id is treated as not-found (defensive).
	 *
	 * @return void
	 */
	public function testEmptyIntentReturnsNotFound(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub([], $saved);
		$service = $this->makeService($stub);

		$result = $service->reconcile(
			paymentIntentId: '',
			outcome: DepositReconciliationService::OUTCOME_AUTHORIZED,
			gateway: 'mollie',
		);

		self::assertSame(DepositReconciliationService::RESULT_NOT_FOUND, $result);
	}//end testEmptyIntentReturnsNotFound()

	/**
	 * The polling fallback reconciles pending deposits using the injected status
	 * provider and counts what it changed (REQ-DP-007).
	 *
	 * @return void
	 */
	public function testPollPendingReconcilesViaStatusProvider(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[
				['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie'],
				['paymentIntentId' => 'tr_b', 'state' => 'pending', 'paymentGateway' => 'mollie'],
			],
			$saved
		);
		$service = $this->makeService($stub);

		// tr_a authorizes; tr_b stays pending (provider returns null).
		$provider = static function (string $intentId): ?string {
			return ($intentId === 'tr_a') ? DepositReconciliationService::OUTCOME_AUTHORIZED : null;
		};

		$counters = $service->pollPending($provider);

		self::assertSame(2, $counters['scanned']);
		self::assertSame(1, $counters['reconciled']);
		self::assertCount(1, $saved);
		self::assertSame('authorized', $saved[0]['state']);
	}//end testPollPendingReconcilesViaStatusProvider()

	/**
	 * A status-provider exception for one deposit does not abort the whole poll.
	 *
	 * @return void
	 */
	public function testPollPendingSurvivesProviderError(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie']],
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
	 * `lifecycleStateToOutcome` projects DepositPayment lifecycle states
	 * onto the OUTCOME_* constants the reconcile loop understands.
	 * Captured maps to AUTHORIZED because the capture transition is owned
	 * by the lifecycle, not the polling fallback.
	 *
	 * @return void
	 */
	public function testLifecycleStateToOutcomeMapsCorrectly(): void {
		$authorized = $this->buildAdapterResult('authorized');
		$captured = $this->buildAdapterResult('captured');
		$failed = $this->buildAdapterResult('failed');
		$voided = $this->buildAdapterResult('voided');
		$pending = $this->buildAdapterResult('pending');
		$draft = $this->buildAdapterResult('draft');

		self::assertSame(
			DepositReconciliationService::OUTCOME_AUTHORIZED,
			DepositReconciliationService::lifecycleStateToOutcome($authorized)
		);
		self::assertSame(
			DepositReconciliationService::OUTCOME_AUTHORIZED,
			DepositReconciliationService::lifecycleStateToOutcome($captured)
		);
		self::assertSame(
			DepositReconciliationService::OUTCOME_FAILED,
			DepositReconciliationService::lifecycleStateToOutcome($failed)
		);
		self::assertSame(
			DepositReconciliationService::OUTCOME_VOIDED,
			DepositReconciliationService::lifecycleStateToOutcome($voided)
		);
		self::assertNull(DepositReconciliationService::lifecycleStateToOutcome($pending));
		self::assertNull(DepositReconciliationService::lifecycleStateToOutcome($draft));
	}//end testLifecycleStateToOutcomeMapsCorrectly()

	/**
	 * `pollPendingViaAdapter()` returns zeroed counters and logs a warning
	 * when no adapter is bound. The lifecycle does NOT advance and the
	 * ObjectService is never touched — the scheduled workflow tick stays
	 * a no-op until the production binding lands.
	 *
	 * @return void
	 */
	public function testPollPendingViaAdapterReturnsZeroWhenNoAdapterBound(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending']],
			$saved
		);
		$service = $this->makeServiceWithAdapter($stub, null);

		$counters = $service->pollPendingViaAdapter();

		self::assertSame(0, $counters['scanned']);
		self::assertSame(0, $counters['reconciled']);
		self::assertCount(0, $saved);
	}//end testPollPendingViaAdapterReturnsZeroWhenNoAdapterBound()

	/**
	 * The dormant adapter leaves pending deposits pending — REQ-DP-007:
	 * `LogDepositPaymentAdapter` returns dormant=true and the service
	 * MUST NOT advance the lifecycle on a dormant outcome.
	 *
	 * @return void
	 */
	public function testPollPendingViaAdapterRespectsDormancy(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie']],
			$saved
		);

		$adapter = $this->createMock(DepositPaymentAdapterInterface::class);
		$adapter->method('fetchStatus')->willReturn(
			$this->buildAdapterResult('pending', dormant: true)
		);

		$service = $this->makeServiceWithAdapter($stub, $adapter);
		$counters = $service->pollPendingViaAdapter();

		self::assertSame(1, $counters['scanned']);
		self::assertSame(0, $counters['reconciled']);
		self::assertCount(0, $saved);
	}//end testPollPendingViaAdapterRespectsDormancy()

	/**
	 * The adapter-wired poll advances `pending` deposits to `authorized`
	 * when the adapter projects an `authorized` lifecycle state — the
	 * canonical happy-path of the polling fallback (REQ-DP-007).
	 *
	 * @return void
	 */
	public function testPollPendingViaAdapterAdvancesOnLiveAuthorized(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie']],
			$saved
		);

		$adapter = $this->createMock(DepositPaymentAdapterInterface::class);
		$adapter->method('fetchStatus')->willReturn(
			$this->buildAdapterResult('authorized')
		);

		$service = $this->makeServiceWithAdapter($stub, $adapter);
		$counters = $service->pollPendingViaAdapter();

		self::assertSame(1, $counters['scanned']);
		self::assertSame(1, $counters['reconciled']);
		self::assertCount(1, $saved);
		self::assertSame('authorized', $saved[0]['state']);
		self::assertArrayHasKey('authorizedAt', $saved[0]);
	}//end testPollPendingViaAdapterAdvancesOnLiveAuthorized()

	/**
	 * An adapter exception is contained per-deposit — the lifecycle does
	 * not advance and the surrounding scheduled-workflow tick keeps
	 * processing the rest of the batch.
	 *
	 * @return void
	 */
	public function testPollPendingViaAdapterSurvivesAdapterException(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie']],
			$saved
		);

		$adapter = $this->createMock(DepositPaymentAdapterInterface::class);
		$adapter->method('fetchStatus')->willThrowException(new \RuntimeException('PSP unreachable'));

		$service = $this->makeServiceWithAdapter($stub, $adapter);
		$counters = $service->pollPendingViaAdapter();

		self::assertSame(1, $counters['scanned']);
		self::assertSame(0, $counters['reconciled']);
		self::assertCount(0, $saved);
	}//end testPollPendingViaAdapterSurvivesAdapterException()

	/**
	 * A failed lifecycle state from the adapter projects onto OUTCOME_FAILED.
	 *
	 * @return void
	 */
	public function testPollPendingViaAdapterProjectsFailedLifecycleOntoFailedOutcome(): void {
		$saved = [];
		$stub = $this->buildObjectServiceStub(
			[['paymentIntentId' => 'tr_a', 'state' => 'pending', 'paymentGateway' => 'mollie']],
			$saved
		);

		$adapter = $this->createMock(DepositPaymentAdapterInterface::class);
		$adapter->method('fetchStatus')->willReturn(
			$this->buildAdapterResult('failed')
		);

		$service = $this->makeServiceWithAdapter($stub, $adapter);
		$counters = $service->pollPendingViaAdapter();

		self::assertSame(1, $counters['scanned']);
		self::assertSame(1, $counters['reconciled']);
		self::assertSame('failed', $saved[0]['state']);
	}//end testPollPendingViaAdapterProjectsFailedLifecycleOntoFailedOutcome()
}//end class
