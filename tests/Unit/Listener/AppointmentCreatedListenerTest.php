<?php

/**
 * Characterisation tests for AppointmentCreatedListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins which created appointments get a confirmation token, with what arguments.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class AppointmentCreatedListenerTest extends TestCase {

	public function testOutcomes(): void {
		$pending = [
			'appointmentId' => 'ap-1',
			'status' => 'pending_confirmation',
			'customerId' => 'c-1',
			'customerUserId' => 'u-1',
			'customerName' => 'Ada',
			'customerEmail' => 'ada@example.test',
		];

		$none = ['issued' => [], 'logs' => []];
		self::assertSame($none, $this->outcome('event', '7', 'Appointment', $pending));
		self::assertSame($none, $this->outcome('created', null, 'Appointment', $pending));
		self::assertSame($none, $this->outcome('created', '7', 'Booking', $pending));
		self::assertSame($none, $this->outcome('created', '7', 'Appointment', ['status' => 'confirmed'] + $pending));
		self::assertSame($none, $this->outcome('created', '7', 'Appointment', ['appointmentId' => ''] + $pending));
		self::assertSame(
			[
				'issued' => [[$pending, ['id' => 'c-1', 'userId' => 'u-1', 'name' => 'Ada', 'email' => 'ada@example.test']]],
				'logs' => [],
			],
			$this->outcome('created', '7', ' shillinq/Appointment ', $pending)
		);
		$bare = ['appointmentId' => 'ap-2', 'status' => 'pending_confirmation'];
		self::assertSame(
			[[$bare, ['id' => '', 'userId' => '', 'name' => '', 'email' => '']]],
			$this->outcome('created', '7', 'appointment', $bare)['issued']
		);
		self::assertSame(
			[['error', 'AppointmentCreatedListener: token issuance failed', ['exception' => 'mail down']]],
			$this->outcome('created', '7', 'Appointment', ['customerEmail' => 'fail'] + $pending)['logs']
		);
	}

	/**
	 * Dispatch one event and capture the token calls and log records.
	 *
	 * @param string $kind 'created' or 'event'.
	 * @param string|null $schemaId The entity's schema id.
	 * @param string $slug The resolved schema slug.
	 * @param array<string,mixed> $appointment The appointment payload.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(string $kind, string|null $schemaId, string $slug, array $appointment): array {
		$issued = [];
		$tokens = $this->createMock(ConfirmationTokenService::class);
		$tokens->method('issueAndSend')->willReturnCallback(
			function (array $appointment, array $customer) use (&$issued): array {
				$issued[] = [$appointment, $customer];
				if ($customer['email'] === 'fail') {
					throw new \RuntimeException('mail down');
				}

				return [];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$entity = new ObjectEntity();
		$entity->setSchema($schemaId);
		$entity->setObject($appointment);
		$event = new Event();
		if ($kind === 'created') {
			$event = new ObjectCreatedEvent($entity);
		}

		(new AppointmentCreatedListener($tokens, $resolver, $logger))->handle($event);

		if ($issued !== [] && $issued[0][1]['email'] === 'fail') {
			$issued = [];
		}

		return ['issued' => $issued, 'logs' => $logger->records];
	}
}
