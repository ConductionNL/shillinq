<?php

/**
 * Unit tests for WithdrawalLinkSigner.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\WithdrawalLinkSigner;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A link opens its own appointment until the withdrawal period ends, and
 * nothing once altered, expired or signed with another secret (REQ-SCX-004).
 */
#[CoversClass(WithdrawalLinkSigner::class)]
final class WithdrawalLinkSignerTest extends TestCase {

	/**
	 * A signer over the given instance secret.
	 *
	 * @param string $secret The instance secret.
	 *
	 * @return WithdrawalLinkSigner
	 */
	private function signer(string $secret = 'instance-secret'): WithdrawalLinkSigner {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'secret' ? $secret : $default
		);

		return new WithdrawalLinkSigner(config: $config);
	}//end signer()

	/**
	 * A link opens its appointment until the end of the period, and not after.
	 *
	 * @return void
	 */
	public function testALinkOpensItsAppointmentUntilThePeriodEnds(): void {
		$expiry = new DateTimeImmutable('2026-10-04T23:59:59+00:00');
		$token = $this->signer()->tokenFor(appointmentId: 'appt-visser', expiresAt: $expiry);

		self::assertIsString($token);
		self::assertSame('appt-visser', $this->signer()->verify(token: $token, now: new DateTimeImmutable('2026-09-24T10:00:00+00:00')));
		self::assertSame('appt-visser', $this->signer()->verify(token: ' ' . $token . ' ', now: $expiry));
		self::assertNull($this->signer()->verify(token: $token, now: $expiry->modify('+1 second')));
	}//end testALinkOpensItsAppointmentUntilThePeriodEnds()

	/**
	 * A link with another appointment id, a moved expiry or a foreign
	 * signature opens nothing.
	 *
	 * @return void
	 */
	public function testATamperedLinkOpensNothing(): void {
		$now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
		$token = (string)$this->signer()->tokenFor(appointmentId: 'appt-visser', expiresAt: new DateTimeImmutable('2026-10-04T23:59:59+00:00'));
		[$payload, $signature] = explode('.', $token);
		$encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

		self::assertNull($this->signer()->verify(token: $encode('appt-other|' . strtotime('2026-10-04T23:59:59Z')) . '.' . $signature, now: $now));
		self::assertNull($this->signer()->verify(token: $encode('appt-visser|' . strtotime('2027-10-04T23:59:59Z')) . '.' . $signature, now: $now));
		self::assertNull($this->signer(secret: 'another-instance')->verify(token: $token, now: $now));
		self::assertNull($this->signer()->verify(token: $payload, now: $now));
		self::assertNull($this->signer()->verify(token: $payload . '.!!', now: $now));
		self::assertNull($this->signer()->verify(token: '', now: $now));
	}//end testATamperedLinkOpensNothing()

	/**
	 * Without an instance secret, or for an id a link may not carry, nothing
	 * is signed and nothing verifies.
	 *
	 * @return void
	 */
	public function testNoSecretOrAnUnusableIdSignsNothing(): void {
		$expiry = new DateTimeImmutable('2026-10-04T23:59:59+00:00');

		self::assertNull($this->signer(secret: '')->tokenFor(appointmentId: 'appt-visser', expiresAt: $expiry));
		self::assertNull($this->signer()->tokenFor(appointmentId: 'appt|other', expiresAt: $expiry));
		self::assertNull($this->signer()->tokenFor(appointmentId: '', expiresAt: $expiry));

		$token = (string)$this->signer()->tokenFor(appointmentId: 'appt-visser', expiresAt: $expiry);
		self::assertNull($this->signer(secret: '')->verify(token: $token, now: new DateTimeImmutable('2026-09-24T10:00:00+00:00')));
	}//end testNoSecretOrAnUnusableIdSignsNothing()
}//end class
