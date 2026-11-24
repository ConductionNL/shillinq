<?php

/**
 * Unit tests for WithdrawalLinkService.
 *
 * REQ-SCX-004: the signed link opens one appointment, expires with the
 * withdrawal period and opens nothing once tampered with.
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
use OCA\Shillinq\Service\Booking\WithdrawalLinkService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * M. Visser booked on 2026-09-20 10:00 (Amsterdam); her period ends 2026-10-04 10:00.
 */
final class WithdrawalLinkServiceTest extends TestCase {

	/**
	 * The service over a secret and a guest page.
	 *
	 * @param string $secret The instance secret.
	 * @param string $page   The guest page.
	 *
	 * @return WithdrawalLinkService
	 */
	private function service(string $secret = 'instance-secret', string $page = 'https://portal.example.nl/gast?app=shillinq'): WithdrawalLinkService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($secret);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				WithdrawalLinkService::PAGE_KEY => $page,
				default => $default,
			}
		);

		return new WithdrawalLinkService($config, $appConfig, new \OCA\Shillinq\Service\WithdrawalService($this->createMock(\OCP\IL10N::class)));
	}//end service()

	/**
	 * The appointment of the scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function appointment(): array {
		return ['id' => 'apt-1', 'source' => 'widget', '@self' => ['created' => '2026-09-20T10:00:00+02:00']];
	}//end appointment()

	/**
	 * The token in a link.
	 *
	 * @param string $link The link.
	 *
	 * @return string
	 */
	private static function tokenOf(string $link): string {
		parse_str((string)parse_url($link, PHP_URL_QUERY), $query);

		return (string)$query['withdraw'];
	}//end tokenOf()

	/**
	 * A valid link opens its appointment within the period, and nothing from the moment it ends.
	 *
	 * @return void
	 */
	public function testAValidLinkOpensItsAppointmentUntilThePeriodEnds(): void {
		$link = $this->service()->forAppointment($this->appointment());
		$this->assertNotNull($link);
		$this->assertStringStartsWith('https://portal.example.nl/gast?app=shillinq&withdraw=', $link);

		$token = self::tokenOf($link);
		$this->assertSame('apt-1', $this->service()->verify($token, new DateTimeImmutable('2026-09-24T14:05:00+02:00')));
		$this->assertSame('apt-1', $this->service()->verify($token, new DateTimeImmutable('2026-10-04T09:59:59+02:00')));
		$this->assertNull($this->service()->verify($token, new DateTimeImmutable('2026-10-04T10:00:00+02:00')), 'an expired link opens nothing');
	}//end testAValidLinkOpensItsAppointmentUntilThePeriodEnds()

	/**
	 * A link whose appointment id was changed, re-signed with another secret or cut opens nothing.
	 *
	 * @return void
	 */
	public function testATamperedLinkOpensNothing(): void {
		$now = new DateTimeImmutable('2026-09-24T14:05:00+02:00');
		$token = (string)$this->service()->tokenFor('apt-1', $now->getTimestamp() + 3600);
		[$payload, $signature] = explode('.', $token);
		$swapped = rtrim(strtr(base64_encode((string)json_encode(['i' => 'apt-2', 'e' => $now->getTimestamp() + 3600])), '+/', '-_'), '=');

		$this->assertNull($this->service()->verify($swapped . '.' . $signature, $now), 'another appointment id');
		$this->assertNull($this->service(secret: 'other-secret')->verify($token, $now), 'another instance');
		$this->assertNull($this->service()->verify($payload, $now), 'no signature');
		$this->assertNull($this->service()->verify($payload . '.!!', $now), 'not base64url');
		$this->assertNull($this->service()->verify('', $now));
	}//end testATamperedLinkOpensNothing()

	/**
	 * No link for a booking not made online, without a booking moment, an https page or a secret.
	 *
	 * @return void
	 */
	public function testNoLinkWhereNoneCanBeOffered(): void {
		$this->assertNull($this->service()->forAppointment(['source' => 'desk'] + $this->appointment()));
		$this->assertNull($this->service()->forAppointment(['id' => 'apt-1', 'source' => 'widget']));
		$this->assertNull($this->service(page: '')->forAppointment($this->appointment()));
		$this->assertNull($this->service(page: 'http://portal.example.nl')->forAppointment($this->appointment()));
		$this->assertNull($this->service(secret: '')->forAppointment($this->appointment()));
		$this->assertNull($this->service()->tokenFor('../apt', 1));
		$this->assertNull($this->service(secret: '')->verify('a.b', new DateTimeImmutable()));
	}//end testNoLinkWhereNoneCanBeOffered()
}//end class
