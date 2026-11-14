<?php

/**
 * Unit tests for NoShowFeeCaptureService.
 *
 * Covers the bookings-depth no-show-fee-capture requirement: a recorded
 * no-show charges the defined `noShowFee` (percentage of appointment cost)
 * through the DepositPayment payment-provider rails, and a booking WITHOUT a
 * defined fee dispatches no charge at all.
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
 * @spec openspec/changes/bookings-depth/specs/bookings-cancellation-rules/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Kernel\Model\DepositPaymentResult;
use OCA\Shillinq\Service\NoShowFeeCaptureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the no-show fee computation + capture-through-provider flow.
 *
 * @spec openspec/changes/bookings-depth/specs/bookings-cancellation-rules/spec.md
 */
final class NoShowFeeCaptureServiceTest extends TestCase {

	/**
	 * Payment-provider adapter mock.
	 *
	 * @var DepositPaymentAdapterInterface&MockObject
	 */
	private DepositPaymentAdapterInterface&MockObject $adapter;

	/**
	 * Set up the adapter + logger mocks. The service is created per-test so
	 * expectations on the adapter differ between cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->adapter = $this->createMock(DepositPaymentAdapterInterface::class);

	}//end setUp()

	/**
	 * Build the service under test with the shared adapter mock.
	 *
	 * @return NoShowFeeCaptureService
	 */
	private function makeService(): NoShowFeeCaptureService {
		return new NoShowFeeCaptureService(
			adapter: $this->adapter,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * Build a captured provider result stub for a given intent id.
	 *
	 * @param string $intentId Gateway-side intent id.
	 *
	 * @return DepositPaymentResult
	 */
	private function capturedResult(string $intentId): DepositPaymentResult {
		return new DepositPaymentResult(
			lifecycleState: 'captured',
			gatewayStatus: 'PAYMENT_DEFERRED',
			paymentIntentId: $intentId,
			paymentLink: '',
			gateway: 'LOG_DEFERRED',
			dormant: true,
		);

	}//end capturedResult()

	/**
	 * A no-show with a 100% fee charges the full appointment cost via a fresh
	 * provider charge (authorise-now rail — no pre-existing authorization).
	 *
	 * @return void
	 */
	public function testNoShowChargesFullDefinedFee(): void {
		$this->adapter->expects(self::once())
			->method('requestPayment')
			->with(
				self::callback(
					static fn (array $payload): bool => (int)$payload['amount']['value'] === 10000
				)
			)
			->willReturn($this->capturedResult(intentId: 'pi_new_123'));
		$this->adapter->expects(self::never())->method('capturePayment');

		$appointment = [
			'appointmentId' => 'apt-1',
			'appointmentCost' => 10000,
			'currency' => 'EUR',
			'appliedPolicy' => ['noShowFee' => 100],
		];

		$out = $this->makeService()->captureNoShowFee(appointment: $appointment);

		self::assertTrue($out['charged']);
		self::assertSame(10000, $out['feeCents']);
		self::assertSame(10000, $out['appointment']['noShowFeeAmount']);
		self::assertSame(NoShowFeeCaptureService::STATUS_CAPTURED, $out['appointment']['noShowFeeStatus']);
		self::assertSame('pi_new_123', $out['appointment']['noShowFeePaymentIntentId']);

	}//end testNoShowChargesFullDefinedFee()

	/**
	 * A 50% no-show fee charges half the appointment cost (integer-cents,
	 * round half-up).
	 *
	 * @return void
	 */
	public function testNoShowChargesPercentageFee(): void {
		$this->adapter->expects(self::once())
			->method('requestPayment')
			->with(
				self::callback(
					static fn (array $payload): bool => (int)$payload['amount']['value'] === 4000
				)
			)
			->willReturn($this->capturedResult(intentId: 'pi_half'));

		$appointment = [
			'appointmentId' => 'apt-2',
			'appointmentCost' => 8000,
			'appliedPolicy' => ['noShowFee' => 50],
		];

		$out = $this->makeService()->captureNoShowFee(appointment: $appointment);

		self::assertSame(4000, $out['feeCents']);
		self::assertTrue($out['charged']);

	}//end testNoShowChargesPercentageFee()

	/**
	 * A booking WITHOUT a defined no-show fee dispatches NO charge at all —
	 * neither a capture nor a fresh charge — and is stamped `none`.
	 *
	 * @return void
	 */
	public function testNoFeeDefinedDoesNotCharge(): void {
		$this->adapter->expects(self::never())->method('requestPayment');
		$this->adapter->expects(self::never())->method('capturePayment');

		$appointment = [
			'appointmentId' => 'apt-3',
			'appointmentCost' => 10000,
			'appliedPolicy' => ['noShowFee' => 0],
		];

		$out = $this->makeService()->captureNoShowFee(appointment: $appointment);

		self::assertFalse($out['charged']);
		self::assertSame(0, $out['feeCents']);
		self::assertNull($out['result']);
		self::assertSame(NoShowFeeCaptureService::STATUS_NONE, $out['appointment']['noShowFeeStatus']);

	}//end testNoFeeDefinedDoesNotCharge()

	/**
	 * A booking with no appliedPolicy at all also dispatches no charge.
	 *
	 * @return void
	 */
	public function testNoPolicyDoesNotCharge(): void {
		$this->adapter->expects(self::never())->method('requestPayment');
		$this->adapter->expects(self::never())->method('capturePayment');

		$out = $this->makeService()->captureNoShowFee(
			appointment: [
				'appointmentId' => 'apt-4',
				'appointmentCost' => 5000,
			]
		);

		self::assertFalse($out['charged']);
		self::assertSame(0, $out['feeCents']);

	}//end testNoPolicyDoesNotCharge()

	/**
	 * When the appointment already carries an authorized payment intent, the
	 * fee is CAPTURED against it (capture-later rail) rather than a fresh
	 * charge being opened.
	 *
	 * @return void
	 */
	public function testCaptureAgainstExistingAuthorization(): void {
		$this->adapter->expects(self::never())->method('requestPayment');
		$this->adapter->expects(self::once())
			->method('capturePayment')
			->with(
				'pi_auth_hold',
				self::callback(
					static fn (array $payload): bool => (int)$payload['amount']['value'] === 2500
						&& (string)$payload['reason'] === 'noShowFee'
				)
			)
			->willReturn($this->capturedResult(intentId: 'pi_auth_hold'));

		$appointment = [
			'appointmentId' => 'apt-5',
			'appointmentCost' => 2500,
			'appliedPolicy' => ['noShowFee' => 100],
			'depositPaymentIntentId' => 'pi_auth_hold',
		];

		$out = $this->makeService()->captureNoShowFee(appointment: $appointment);

		self::assertTrue($out['charged']);
		self::assertSame('pi_auth_hold', $out['appointment']['noShowFeePaymentIntentId']);

	}//end testCaptureAgainstExistingAuthorization()

	/**
	 * computeNoShowFeeCents clamps a >100 percentage to the full cost and
	 * returns 0 for a zero-cost appointment.
	 *
	 * @return void
	 */
	public function testComputeClampsAndGuards(): void {
		$service = $this->makeService();

		self::assertSame(
			10000,
			$service->computeNoShowFeeCents(
				appointment: ['appointmentCost' => 10000, 'appliedPolicy' => ['noShowFee' => 150]]
			)
		);
		self::assertSame(
			0,
			$service->computeNoShowFeeCents(
				appointment: ['appointmentCost' => 0, 'appliedPolicy' => ['noShowFee' => 100]]
			)
		);

	}//end testComputeClampsAndGuards()

	/**
	 * Pin every outcome of captureNoShowFee: the exact provider payloads on
	 * both rails, the stamped appointment, the returned envelope, the failed
	 * status and every audit log line.
	 *
	 * @return void
	 */
	public function testCaptureOutcomesThroughEveryRail(): void {
		$calls = [];
		$logs = [];
		$states = ['captured', 'refused', 'pending'];
		$adapter = $this->createMock(DepositPaymentAdapterInterface::class);
		$adapter->method('requestPayment')->willReturnCallback(
			function (array $payload) use (&$calls, &$states): DepositPaymentResult {
				$calls[] = ['request', $payload];
				return new DepositPaymentResult(array_shift($states), 'S', 'pi_req', '', 'GW', false);
			}
		);
		$adapter->method('capturePayment')->willReturnCallback(
			function (string $intent, array $payload) use (&$calls, &$states): DepositPaymentResult {
				$calls[] = ['capture', $intent, $payload];
				return new DepositPaymentResult(array_shift($states), 'S', $intent, '', 'GW', true);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$logs): void {
				$logs[] = [$message, $context];
			}
		);
		$service = new NoShowFeeCaptureService($adapter, $logger);
		$at = new \DateTimeImmutable('2026-03-04 10:20:30', new \DateTimeZone('Europe/Amsterdam'));

		$none = $service->captureNoShowFee(['appointmentCost' => 500], $at);
		$fresh = $service->captureNoShowFee(
			['appointmentId' => 'a1', 'administrationId' => 'adm', 'appointmentCost' => 999, 'appliedPolicy' => ['noShowFee' => 50]],
			$at
		);
		$failed = $service->captureNoShowFee(
			['appointmentCost' => 1000, 'currency' => 'USD', 'appliedPolicy' => ['noShowFee' => 10], 'depositPaymentIntentId' => 'pi_h'],
			$at
		);
		$pending = $service->captureNoShowFee(['appointmentId' => 'a3', 'appointmentCost' => 300, 'appliedPolicy' => ['noShowFee' => 100]], $at);

		self::assertSame(
			['appointment' => ['appointmentCost' => 500, 'noShowFeeAmount' => 0, 'noShowFeeStatus' => 'none'], 'feeCents' => 0, 'charged' => false, 'result' => null],
			$none
		);
		$meta1 = ['appointmentId' => 'a1', 'administrationId' => 'adm', 'correlationId' => 'noshow-a1'];
		$meta2 = ['appointmentId' => '', 'administrationId' => '', 'correlationId' => 'noshow-unknown'];
		$meta3 = ['appointmentId' => 'a3', 'administrationId' => '', 'correlationId' => 'noshow-a3'];
		self::assertSame(
			[
				['request', ['amount' => ['value' => 500, 'currency' => 'EUR'], 'description' => 'No-show fee', 'methodHint' => 'creditcard', 'metadata' => $meta1]],
				['capture', 'pi_h', ['amount' => ['value' => 100, 'currency' => 'USD'], 'reason' => 'noShowFee', 'metadata' => $meta2]],
				['request', ['amount' => ['value' => 300, 'currency' => 'EUR'], 'description' => 'No-show fee', 'methodHint' => 'creditcard', 'metadata' => $meta3]],
			],
			$calls
		);
		self::assertSame(
			['noShowFeeAmount' => 500, 'noShowFeeStatus' => 'captured', 'noShowFeePaymentIntentId' => 'pi_req', 'noShowFeeCapturedAt' => '2026-03-04T09:20:30Z'],
			array_slice($fresh['appointment'], 4)
		);
		self::assertSame([500, true, 'captured'], [$fresh['feeCents'], $fresh['charged'], $fresh['result']->lifecycleState]);
		self::assertSame(['failed', 'pi_h', 100, true], [$failed['appointment']['noShowFeeStatus'], $failed['appointment']['noShowFeePaymentIntentId'], $failed['feeCents'], $failed['charged']]);
		self::assertSame('captured', $pending['appointment']['noShowFeeStatus']);
		$ctx = static fn (string $id, int $fee, string $state, bool $dormant, string $pi): array => [
			'appointmentId' => $id, 'feeCents' => $fee, 'gateway' => 'GW', 'lifecycleState' => $state, 'dormant' => $dormant, 'paymentIntentId' => $pi,
		];
		$captured = 'Shillinq: no-show fee captured via payment provider';
		self::assertSame(
			[
				['Shillinq: no-show recorded but no fee defined — no charge dispatched', ['appointmentId' => '']],
				[$captured, $ctx('a1', 500, 'captured', false, 'pi_req')],
				[$captured, $ctx('', 100, 'refused', true, 'pi_h')],
				[$captured, $ctx('a3', 300, 'pending', false, 'pi_req')],
			],
			$logs
		);

	}//end testCaptureOutcomesThroughEveryRail()
}//end class
