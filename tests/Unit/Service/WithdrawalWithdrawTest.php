<?php

/**
 * Unit tests for WithdrawalService::withdraw().
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\Model\DepositPaymentResult;
use OCA\Shillinq\Service\CancellationService;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An object service double that also records saves.
 */
final class WithdrawalObjectServiceFake {

	/**
	 * The targeted schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Rows by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $data = [];

	/**
	 * The saves: [schema, uuid, object].
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	public array $saved = [];

	/**
	 * Target a register (one register here).
	 *
	 * @param string $register The register.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Target a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * One row by id.
	 *
	 * @param int|string $id            The id.
	 * @param array|null $_extend       Unused.
	 * @param bool       $files         Unused.
	 * @param mixed      $register      Unused.
	 * @param mixed      $schema        The schema, or the targeted one.
	 * @param bool       $_rbac         Unused.
	 * @param bool       $_multitenancy Unused.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$row = ($this->data[(string)($schema ?? $this->schema)][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('not found');
		}

		return $row;
	}//end find()

	/**
	 * Rows matching every filter.
	 *
	 * @param array $config        The query.
	 * @param bool  $_rbac         Unused.
	 * @param bool  $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$out = [];
		foreach (($this->data[$this->schema] ?? []) as $id => $row) {
			$match = true;
			foreach (($config['filters'] ?? []) as $key => $value) {
				$match = $match && (($row[$key] ?? null) === $value);
			}

			if ($match === true) {
				$out[] = $row + ['id' => $id];
			}
		}

		return $out;
	}//end findAll()

	/**
	 * Record a save and answer it with an id.
	 *
	 * @param array       $object        The object.
	 * @param string      $register      Unused.
	 * @param string      $schema        The schema.
	 * @param string|null $uuid          The id, or null for a new object.
	 * @param bool        $_rbac         Unused.
	 * @param bool        $_multitenancy Unused.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = [$schema, $uuid, $object];

		return $object + ['id' => ($uuid ?? 'wd-1')];
	}//end saveObject()
}//end class

/**
 * Withdrawing cancels without a fee, refunds in full, or records the refund as due.
 *
 * @covers \OCA\Shillinq\Service\WithdrawalService
 * @uses   \OCA\Shillinq\Service\CancellationService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Kernel\Model\DepositPaymentResult
 */
final class WithdrawalWithdrawTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var WithdrawalObjectServiceFake
	 */
	private WithdrawalObjectServiceFake $os;

	/**
	 * The deposit adapter double.
	 *
	 * @var DepositPaymentAdapterInterface&MockObject
	 */
	private DepositPaymentAdapterInterface $deposits;

	protected function setUp(): void {
		parent::setUp();
		$this->os = new WithdrawalObjectServiceFake();
		$this->deposits = $this->createMock(DepositPaymentAdapterInterface::class);
		// M. Visser's Knippen en kleuren: booked 2026-09-20 for 2026-10-15, EUR 25 deposit paid,
		// a policy charging 50 percent within 30 days.
		$this->os->data['Appointment']['apt-1'] = [
			'@self' => ['created' => '2026-09-20T09:00:00+00:00'],
			'appointmentId' => 'apt-1',
			'administrationId' => 'adm-kapsalon-knip',
			'serviceId' => 'svc-knippen-kleuren',
			'source' => 'widget',
			'status' => 'confirmed',
			'startTime' => '2026-10-15T10:00:00Z',
			'appointmentCost' => 8500,
			'appliedPolicy' => ['lateFeeBrackets' => [['daysBeforeStart' => 30, 'feePercent' => 50]]],
			'depositPaymentId' => 'dep-1',
		];
		$this->os->data['Service']['svc-1'] = ['serviceId' => 'svc-knippen-kleuren', 'name' => 'Knippen en kleuren'];
		$this->os->data['DepositPayment']['dep-1'] = ['amount' => 2500, 'currencyCode' => 'EUR', 'state' => 'captured', 'paymentIntentId' => 'pi_1', 'arInvoiceId' => 'inv-1'];
	}//end setUp()

	/**
	 * The service wired to the doubles.
	 *
	 * @return WithdrawalService
	 */
	private function service(): WithdrawalService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->os);

		return new WithdrawalService(
			l10n: $l10n,
			container: $container,
			cancellation: new CancellationService(logger: new NullLogger()),
			deposits: $this->deposits,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * A live adapter refunds the deposit in full; the appointment is cancelled with no fee.
	 *
	 * @return void
	 */
	public function testADepositIsRefundedAndTheBookingCancelledWithoutAFee(): void {
		$this->deposits->method('isDormant')->willReturn(false);
		$this->deposits->expects($this->once())->method('initiateRefund')
			->with('pi_1', $this->callback(static fn (array $p): bool => $p['amount'] === ['value' => 2500, 'currency' => 'EUR'] && $p['metadata']['depositPaymentId'] === 'dep-1'))
			->willReturn(new DepositPaymentResult('voided', 'refunded', 'pi_1', '', 'log', false));

		$outcome = $this->service()->withdraw('apt-1', 'guest-link', new DateTimeImmutable('2026-09-24T14:05:00+00:00'));

		$this->assertTrue($outcome['withdrawn']);
		[$schema, $uuid, $record] = $this->os->saved[0];
		$this->assertSame(['Withdrawal', null], [$schema, $uuid]);
		$this->assertSame('apt-1', $record['appointmentId']);
		$this->assertSame('guest-link', $record['channel']);
		$this->assertSame('2026-09-24T14:05:00+00:00', $record['requestedAt']);
		$this->assertSame(2500, $record['refundAmount']);
		$this->assertSame('initiated', $record['refundStatus']);
		$this->assertNull($record['refundDueBy']);
		$this->assertSame('inv-1', $record['invoiceId']);

		[$schema, $uuid, $appointment] = $this->os->saved[1];
		$this->assertSame(['Appointment', 'apt-1'], [$schema, $uuid]);
		$this->assertSame('cancelled', $appointment['status']);
		$this->assertSame('withdrawal', $appointment['cancelledReason']);
		// The policy would have kept half; a withdrawal keeps nothing.
		$this->assertSame(8500, $appointment['refundAmount']);
		$this->assertSame(['withdrawalId' => 'wd-1', 'withdrawnAt' => '2026-09-24T14:05:00+00:00'], $appointment['withdrawal']);
		$this->assertArrayNotHasKey('@self', $appointment);
	}//end testADepositIsRefundedAndTheBookingCancelledWithoutAFee()

	/**
	 * While the adapter is dormant, the refund is recorded as due 14 days later.
	 *
	 * @return void
	 */
	public function testADormantAdapterRecordsTheRefundAsDue(): void {
		$this->deposits->method('isDormant')->willReturn(true);
		$this->deposits->expects($this->never())->method('initiateRefund');

		$this->service()->withdraw('apt-1', 'portal', new DateTimeImmutable('2026-09-24T14:05:00+00:00'));

		$record = $this->os->saved[0][2];
		$this->assertSame('due', $record['refundStatus']);
		$this->assertSame('2026-10-08', $record['refundDueBy']);
		$this->assertSame('portal', $record['channel']);
	}//end testADormantAdapterRecordsTheRefundAsDue()

	/**
	 * A refused refund is recorded as failed and due, for staff.
	 *
	 * @return void
	 */
	public function testARefusedRefundIsDueForStaff(): void {
		$this->deposits->method('isDormant')->willReturn(false);
		$this->deposits->method('initiateRefund')->willThrowException(new \RuntimeException('gateway down'));

		$this->service()->withdraw('apt-1', 'portal', new DateTimeImmutable('2026-09-24T14:05:00+00:00'));

		$this->assertSame('failed', $this->os->saved[0][2]['refundStatus']);
		$this->assertSame('2026-10-08', $this->os->saved[0][2]['refundDueBy']);
	}//end testARefusedRefundIsDueForStaff()

	/**
	 * After the period nothing is written and the reason comes back.
	 *
	 * @return void
	 */
	public function testALateWithdrawalChangesNothing(): void {
		$outcome = $this->service()->withdraw('apt-1', 'portal', new DateTimeImmutable('2026-10-05T09:00:00+00:00'));

		$this->assertFalse($outcome['withdrawn']);
		$this->assertSame('period-ended', $outcome['code']);
		$this->assertSame([], $this->os->saved);
	}//end testALateWithdrawalChangesNothing()

	/**
	 * Without a deposit nothing is refunded.
	 *
	 * @return void
	 */
	public function testABookingWithoutADepositHasNothingToRefund(): void {
		unset($this->os->data['Appointment']['apt-1']['depositPaymentId']);
		$this->deposits->expects($this->never())->method('initiateRefund');

		$this->service()->withdraw('apt-1', 'portal', new DateTimeImmutable('2026-09-24T14:05:00+00:00'));

		$this->assertSame(['none', 0], [$this->os->saved[0][2]['refundStatus'], $this->os->saved[0][2]['refundAmount']]);
	}//end testABookingWithoutADepositHasNothingToRefund()
}//end class
