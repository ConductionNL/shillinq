<?php

/**
 * Unit tests for ConfirmationMailer.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCA\Shillinq\Service\ConfirmationMailer;
use OCA\Shillinq\Service\IcsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies email delivery routing and graceful degradation.
 */
final class ConfirmationMailerTest extends TestCase {
	/**
	 * Build a mailer with the given openconnector CallService double (or none).
	 *
	 * @param object|null $callService The CallService double, or null to simulate absence.
	 *
	 * @return ConfirmationMailer
	 */
	private function makeMailer(?object $callService): ConfirmationMailer {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($callService) {
				if ($id === 'OCA\OpenConnector\Service\CallService' && $callService !== null) {
					return $callService;
				}
				throw new \RuntimeException('not found: ' . $id);
			}
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.nl/index.php/apps/shillinq/confirm');

		// IcsService is final — build a real one with mocked DI deps so the
		// mailer's call into generateIcs() executes the real (cheap) path.
		$tzResolver = new TimezoneResolver(
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
		);
		$ics = new IcsService(
			$tzResolver,
			$this->createMock(LoggerInterface::class),
		);

		return new ConfirmationMailer(
			container: $container,
			urlGenerator: $url,
			icsService: $ics,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeMailer()

	/**
	 * A demo appointment fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function appointment(): array {
		return [
			'appointmentNumber' => 'APT-1',
			'serviceName' => 'Consult',
			'customerEmail' => 'jan@example.nl',
			'customerTimezone' => 'Europe/Amsterdam',
			'startTime' => '2026-05-22T12:30:00Z',
			'endTime' => '2026-05-22T13:00:00Z',
		];
	}//end appointment()

	/**
	 * An appointment without an email is skipped (returns false).
	 *
	 * @return void
	 */
	public function testSendSkipsWhenNoEmail(): void {
		$mailer = $this->makeMailer(null);
		$appt = $this->appointment();
		unset($appt['customerEmail']);

		self::assertFalse($mailer->send(appointment: $appt, rawToken: 'tok'));
	}//end testSendSkipsWhenNoEmail()

	/**
	 * When openconnector is absent, the mailer logs and reports handed-off (true).
	 *
	 * @return void
	 */
	public function testSendDegradesGracefullyWithoutOpenconnector(): void {
		$mailer = $this->makeMailer(null);
		self::assertTrue($mailer->send(appointment: $this->appointment(), rawToken: 'tok'));
	}//end testSendDegradesGracefullyWithoutOpenconnector()

	/**
	 * When openconnector is present, the mailer hands the payload (with an ICS
	 * attachment and a confirmation web link) to its send() method.
	 *
	 * @return void
	 */
	public function testSendDispatchesPayloadToOpenconnector(): void {
		$captured = null;
		$callService = new class($captured) {
			/**
			 * Captured payload reference.
			 *
			 * @var mixed
			 */
			public mixed $captured;

			/**
			 * Constructor.
			 *
			 * @param mixed $captured Bound capture sink.
			 */
			public function __construct(&$captured) {
				$this->captured = &$captured;
			}

			/**
			 * Capture the payload.
			 *
			 * @param array<string,mixed> $payload The payload.
			 *
			 * @return void
			 */
			public function send(array $payload): void {
				$this->captured = $payload;
			}
		};

		$mailer = $this->makeMailer($callService);
		$result = $mailer->send(appointment: $this->appointment(), rawToken: 'rawtok123');

		self::assertTrue($result);
		$payload = $callService->captured;
		self::assertSame('jan@example.nl', $payload['to']);
		self::assertStringContainsString('Consult', $payload['subject']);
		self::assertStringContainsString('token=rawtok123', $payload['webLink']);
		self::assertSame('appointment.ics', $payload['attachments'][0]['filename']);
		self::assertStringContainsString('text/calendar', $payload['attachments'][0]['contentType']);
		self::assertStringContainsString('BEGIN:VCALENDAR', $payload['attachments'][0]['content']);
	}//end testSendDispatchesPayloadToOpenconnector()
}//end class
