<?php

/**
 * Unit tests for PortalWithdrawalService.
 *
 * REQ-SCX-004: a signed-in customer withdraws only their own appointment; a
 * guest withdraws only the appointment of a valid signed link.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Booking\PortalWithdrawalService;
use OCA\Shillinq\Service\Booking\WithdrawalLinkService;
use OCA\Shillinq\Service\WithdrawalService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * M. Visser (CustomerMaster cm-visser) and her widget booking apt-1.
 */
final class PortalWithdrawalServiceTest extends TestCase {

	/**
	 * Stored rows by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The withdrawal service double.
	 *
	 * @var WithdrawalService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WithdrawalService $withdrawals;

	/**
	 * Seed the account, the customer and the appointment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows['portalAccount'] = ['acc-1' => ['subjectRef' => 'sub-visser', 'audience' => 'customer', 'claims' => ['shillinq' => ['customerMasterId' => 'cm-visser']]]];
		$this->rows['CustomerMaster'] = ['cm-visser' => ['email' => 'M.Visser@example.nl']];
		$this->rows['Appointment'] = [
			'apt-1' => ['customerEmail' => 'm.visser@example.nl', 'customerId' => '', 'source' => 'widget'],
			'apt-2' => ['customerEmail' => 'j.jansen@example.nl', 'customerId' => 'cm-jansen', 'source' => 'widget'],
			'apt-3' => ['customerEmail' => '', 'customerId' => 'cm-visser', 'source' => 'widget'],
		];
		$this->withdrawals = $this->createMock(WithdrawalService::class);
	}//end setUp()

	/**
	 * The service over the in-memory rows.
	 *
	 * @return PortalWithdrawalService
	 */
	private function service(): PortalWithdrawalService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$schema): array {
				$out = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					if (array_intersect_assoc($config['filters'] ?? [], array_filter($row, 'is_scalar')) === ($config['filters'] ?? [])) {
						$out[] = $row;
					}
				}

				return $out;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use (&$schema): ?ObjectEntityInterface {
				$row = ($this->rows[$schema][(string)$id] ?? null);
				if ($row === null) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);

		return new PortalWithdrawalService($this->withdrawals, $this->links(), $objects, new NullLogger());
	}//end service()

	/**
	 * A link service over a fixed secret.
	 *
	 * @return WithdrawalLinkService
	 */
	private function links(): WithdrawalLinkService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');

		return new WithdrawalLinkService($config, $this->createMock(IAppConfig::class), new WithdrawalService($this->createMock(\OCP\IL10N::class)));
	}//end links()

	/**
	 * A withdrawal outcome as WithdrawalService answers it.
	 *
	 * @param bool        $withdrawn Whether it went through.
	 * @param string|null $reason    The refusal reason.
	 *
	 * @return array<string, mixed>
	 */
	private static function outcome(bool $withdrawn, ?string $reason = null): array {
		return ['withdrawn' => $withdrawn, 'code' => '', 'reason' => $reason, 'withdrawal' => ['acknowledgedAt' => '2026-09-24T14:05:00+00:00'], 'appointment' => ['id' => 'apt-1']];
	}//end outcome()

	/**
	 * A valid subject withdraws their own booking, matched by e-mail or by customer record.
	 *
	 * @return void
	 */
	public function testAValidSubjectWithdrawsTheirOwnBooking(): void {
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');
		$this->withdrawals->expects($this->exactly(2))->method('withdraw')
			->willReturnCallback(fn (string $id, string $channel): array => $channel === 'portal' ? self::outcome(true) : self::outcome(false));
		$claims = ['audience' => 'customer', 'sub' => 'sub-visser'];

		$this->assertSame(['status' => 'withdrawn', 'acknowledgedAt' => '2026-09-24T14:05:00+00:00'], $this->service()->withdrawForSubject($claims, 'apt-1', $now));
		$this->assertSame('withdrawn', $this->service()->withdrawForSubject($claims, 'apt-3', $now)['status']);
	}//end testAValidSubjectWithdrawsTheirOwnBooking()

	/**
	 * Another customer's booking, a wrong audience, an unknown subject or booking: forbidden, nothing withdrawn.
	 *
	 * @return void
	 */
	public function testAnythingButTheirOwnBookingIsForbidden(): void {
		$this->withdrawals->expects($this->never())->method('withdraw');
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');
		$service = $this->service();

		$this->assertSame(['status' => 'forbidden'], $service->withdrawForSubject(['audience' => 'customer', 'sub' => 'sub-visser'], 'apt-2', $now));
		$this->assertSame(['status' => 'forbidden'], $service->withdrawForSubject(['audience' => 'customer', 'sub' => 'sub-visser'], 'apt-9', $now));
		$this->assertSame(['status' => 'forbidden'], $service->withdrawForSubject(['audience' => 'supplier', 'sub' => 'sub-visser'], 'apt-1', $now));
		$this->assertSame(['status' => 'forbidden'], $service->withdrawForSubject(['audience' => 'customer', 'sub' => 'sub-nobody'], 'apt-1', $now));
		$this->assertSame(['status' => 'forbidden'], $service->withdrawForSubject(['audience' => 'customer', 'sub' => 'sub-visser'], '../apt-1', $now));
	}//end testAnythingButTheirOwnBookingIsForbidden()

	/**
	 * A valid link withdraws its appointment by the guest channel; an expired or tampered one is forbidden.
	 *
	 * @return void
	 */
	public function testALinkWithdrawsOnlyWhileValid(): void {
		$now = new DateTimeImmutable('2026-09-24T14:05:00+00:00');
		$this->withdrawals->expects($this->once())->method('withdraw')->with('apt-1', 'guest-link', $now)->willReturn(self::outcome(true));
		$valid = (string)$this->links()->tokenFor('apt-1', $now->getTimestamp() + 60);
		$expired = (string)$this->links()->tokenFor('apt-1', $now->getTimestamp());

		$this->assertSame('withdrawn', $this->service()->withdrawByLink($valid, $now)['status']);
		$this->assertSame(['status' => 'forbidden'], $this->service()->withdrawByLink($expired, $now));
		$this->assertSame(['status' => 'forbidden'], $this->service()->withdrawByLink($valid . 'x', $now));
	}//end testALinkWithdrawsOnlyWhileValid()

	/**
	 * A booking no longer withdrawable is refused with its reason; a failure is an error; a vanished booking forbidden.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreAnswered(): void {
		$now = new DateTimeImmutable('2026-10-05T09:00:00+00:00');
		$token = (string)$this->links()->tokenFor('apt-1', $now->getTimestamp() + 60);
		$this->withdrawals->method('withdraw')->willReturnOnConsecutiveCalls(
			self::outcome(false, 'The withdrawal period ended on 2026-10-03.'),
			$this->throwException(new \RuntimeException('down')),
			['withdrawn' => false, 'code' => 'not-booked-online', 'reason' => null, 'withdrawal' => null, 'appointment' => null]
		);

		$this->assertSame(['status' => 'refused', 'reason' => 'The withdrawal period ended on 2026-10-03.'], $this->service()->withdrawByLink($token, $now));
		$this->assertSame(['status' => 'error'], $this->service()->withdrawByLink($token, $now));
		$this->assertSame(['status' => 'forbidden'], $this->service()->withdrawByLink($token, $now));
	}//end testRefusalsAndFailuresAreAnswered()

	/**
	 * Ownership by customer record, else by e-mail ignoring case; an empty e-mail owns nothing.
	 *
	 * @return void
	 */
	public function testOwnership(): void {
		$this->assertTrue(PortalWithdrawalService::owns('cm-1', null, ['customerId' => 'cm-1']));
		$this->assertTrue(PortalWithdrawalService::owns('cm-1', ['email' => 'A@B.nl'], ['customerEmail' => ' a@b.nl ']));
		$this->assertFalse(PortalWithdrawalService::owns('cm-1', ['email' => ''], ['customerEmail' => '']));
		$this->assertFalse(PortalWithdrawalService::owns('cm-1', null, ['customerId' => 'cm-2', 'customerEmail' => 'a@b.nl']));
	}//end testOwnership()
}//end class
