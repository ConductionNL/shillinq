<?php

/**
 * Unit tests for the email channel of BookingNotificationService: every
 * booking mail goes through Nextcloud's mailer and a sent reminder is
 * recorded on its booking.
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
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Service\NotificationDispatch;
use OCA\Shillinq\Service\NotificationMailer;
use OCA\Shillinq\Service\OpenconnectorNotification;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The email channel goes through IMailer, per template type.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.2
 */
#[CoversClass(BookingNotificationService::class)]
#[UsesClass(NotificationMailer::class)]
#[UsesClass(NotificationDispatch::class)]
#[UsesClass(OpenconnectorNotification::class)]
final class BookingNotificationMailTest extends TestCase {

	/**
	 * Every saveObject call: [register, schema, object, uuid].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every message handed to the mailer: [to, subject, body].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $mails = [];

	/**
	 * The service over a recording mailer and store.
	 *
	 * @param array<int, string>|null $refused   Addresses the mail server refuses; null throws instead.
	 * @param ContainerInterface|null $container The container; by default one that must not be asked.
	 * @param bool                    $saveFails Whether the store throws on save.
	 *
	 * @return BookingNotificationService
	 */
	private function service(?array $refused = [], ?ContainerInterface $container = null, bool $saveFails = false): BookingNotificationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('bookings');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true) use ($saveFails): ObjectEntityStub {
				if ($saveFails === true && $schema === 'Booking') {
					throw new RuntimeException('store down');
				}

				$this->saves[] = [(string)$register, (string)$schema, $object, $uuid, $_rbac];
				return new ObjectEntityStub(payload: $object);
			}
		);

		$message = $this->createMock(IMessage::class);
		$draft = ['to' => [], 'subject' => '', 'body' => ''];
		$message->method('setTo')->willReturnCallback(
			function (array $to) use (&$draft, $message): IMessage {
				$draft['to'] = $to;
				return $message;
			}
		);
		$message->method('setSubject')->willReturnCallback(
			function (string $subject) use (&$draft, $message): IMessage {
				$draft['subject'] = $subject;
				return $message;
			}
		);
		$message->method('setPlainBody')->willReturnCallback(
			function (string $body) use (&$draft, $message): IMessage {
				$draft['body'] = $body;
				return $message;
			}
		);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $address): bool => str_contains($address, '@'));
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function () use (&$draft, $refused): array {
				if ($refused === null) {
					throw new RuntimeException('smtp down');
				}

				$this->mails[] = [$draft['to'], $draft['subject'], $draft['body']];
				return array_values(array_intersect($draft['to'], $refused));
			}
		);

		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->expects(self::never())->method('get');
		}

		return new BookingNotificationService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objectService,
			mailer: new NotificationMailer(mailer: $mailer, logger: $this->createMock(LoggerInterface::class)),
		);
	}//end service()

	/**
	 * The booking used throughout.
	 *
	 * @return array<string, mixed>
	 */
	private function booking(): array {
		return [
			'id' => 'b-7',
			'guestName' => 'Ann',
			'guestEmail' => 'ann@example.com',
			'startTime' => '2026-12-03T10:00:00+00:00',
			'status' => 'confirmed',
		];
	}//end booking()

	/**
	 * The audit rows saved, without their time.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function deliveries(): array {
		$rows = [];
		foreach ($this->saves as $save) {
			if ($save[1] === 'NotificationDelivery') {
				$row = $save[2];
				unset($row['sentAt']);
				$rows[] = $row;
			}
		}

		return $rows;
	}//end deliveries()

	/**
	 * The booking saves made.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function bookingSaves(): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save[1] === 'Booking'));
	}//end bookingSaves()

	/**
	 * The template types a booking trigger fires on.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function templateTypes(): array {
		return [
			'created' => ['created'],
			'changed' => ['changed'],
			'cancelled' => ['cancelled'],
			'reminder' => ['reminder'],
		];
	}//end templateTypes()

	/**
	 * Each template type is rendered and mailed through IMailer, never posted to openconnector.
	 *
	 * @param string $type The template type.
	 *
	 * @return void
	 */
	#[DataProvider('templateTypes')]
	public function testEachTemplateTypeIsMailedThroughTheMailer(string $type): void {
		$service = $this->service();

		$service->dispatchNotification(
			trigger: ['name' => 'T-' . $type, 'eventType' => $type],
			recipient: ['role' => 'customer', 'channels' => ['email'], 'address' => 'ann@example.com'],
			template: ['name' => 'Tpl-' . $type, 'subject' => ucfirst($type) . ': {{ booking.guestName }}', 'body' => "On {{ booking.startTime | date('d-m-Y') }}"],
			booking: $this->booking()
		);

		self::assertSame([[['ann@example.com'], ucfirst($type) . ': Ann', 'On 03-12-2026']], $this->mails);
		self::assertSame(
			[
				[
					'triggerName' => 'T-' . $type,
					'triggerType' => $type,
					'bookingId' => 'b-7',
					'recipient' => 'ann@example.com',
					'channel' => 'email',
					'templateName' => 'Tpl-' . $type,
					'status' => 'sent',
					'failureReason' => null,
					'retryCount' => 0,
				],
			],
			$this->deliveries()
		);
	}//end testEachTemplateTypeIsMailedThroughTheMailer()

	/**
	 * A sent reminder is written on its booking, without the job's window context.
	 *
	 * @return void
	 */
	public function testASentReminderIsRecordedOnTheBooking(): void {
		$service = $this->service();

		$service->dispatchNotification(
			trigger: ['name' => 'Day before', 'eventType' => 'reminder'],
			recipient: ['role' => 'customer', 'channels' => ['email'], 'address' => 'ann@example.com'],
			template: ['subject' => 'Tomorrow', 'body' => 'See you'],
			booking: $this->booking() + ['hoursUntilEvent' => 24.0]
		);

		$saves = $this->bookingSaves();
		self::assertCount(1, $saves);
		[$register, , $object, $uuid, $rbac] = $saves[0];
		self::assertSame('bookings', $register);
		self::assertSame('b-7', $uuid);
		self::assertFalse($rbac);
		self::assertSame('email', $object['lastReminderChannel']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $object['lastReminderSentAt']);
		self::assertArrayNotHasKey('hoursUntilEvent', $object);
		self::assertSame('Ann', $object['guestName']);
	}//end testASentReminderIsRecordedOnTheBooking()

	/**
	 * A mail other than a reminder leaves the booking alone.
	 *
	 * @return void
	 */
	public function testANonReminderMailDoesNotWriteTheBooking(): void {
		$service = $this->service();

		$service->dispatchNotification(
			trigger: ['eventType' => 'created'],
			recipient: ['channels' => ['email'], 'address' => 'ann@example.com'],
			template: ['subject' => 's', 'body' => 'b'],
			booking: $this->booking()
		);

		self::assertCount(1, $this->mails);
		self::assertSame([], $this->bookingSaves());
	}//end testANonReminderMailDoesNotWriteTheBooking()

	/**
	 * A refused or failing mail is no send: nothing is recorded on the booking and the failure is audited.
	 *
	 * @return void
	 */
	public function testARefusedMailIsAuditedAsFailedAndNotRecorded(): void {
		$service = $this->service(refused: ['ann@example.com']);

		$service->dispatchNotification(
			trigger: ['name' => 'Day before', 'eventType' => 'reminder'],
			recipient: ['channels' => ['email'], 'address' => 'ann@example.com'],
			template: ['subject' => 's', 'body' => 'b'],
			booking: $this->booking()
		);

		self::assertSame([], $this->bookingSaves());
		$deliveries = $this->deliveries();
		self::assertCount(1, $deliveries);
		self::assertSame('failed', $deliveries[0]['status']);
		self::assertSame('all channels exhausted', $deliveries[0]['failureReason']);
		self::assertSame(1, $deliveries[0]['retryCount']);
	}//end testARefusedMailIsAuditedAsFailedAndNotRecorded()

	/**
	 * A mailer that throws and an address that is no email address both answer false, so the next channel is tried.
	 *
	 * @return void
	 */
	public function testAFailingMailFallsThroughToTheNextChannel(): void {
		$posts = [];
		$client = new class($posts) {
			/**
			 * Hold the post log.
			 *
			 * @param array<int, array<string, mixed>> $posts The log.
			 */
			public function __construct(private array &$posts) {
			}//end __construct()

			/**
			 * Record a post and answer 202.
			 *
			 * @param string               $url     The URL.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return object
			 */
			public function post(string $url, array $options): object {
				$this->posts[] = $options['json'];
				return new class {
					/**
					 * The status.
					 *
					 * @return int
					 */
					public function getStatusCode(): int {
						return 202;
					}//end getStatusCode()
				};
			}//end post()
		};
		$clientService = $this->getMockBuilder(className: \stdClass::class)->addMethods(['newClient'])->getMock();
		$clientService->method('newClient')->willReturn($client);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCP\Http\Client\IClientService')->willReturn($clientService);

		$service = $this->service(refused: null, container: $container);
		$service->dispatchNotification(
			trigger: ['eventType' => 'reminder'],
			recipient: ['channels' => ['email', 'sms'], 'address' => 'ann@example.com'],
			template: ['subject' => 's', 'body' => 'b'],
			booking: $this->booking()
		);

		self::assertSame([], $this->mails);
		self::assertCount(1, $posts);
		self::assertSame('sms', $posts[0]['channelAdapter']);
		$saves = $this->bookingSaves();
		self::assertCount(1, $saves);
		self::assertSame('sms', $saves[0][2]['lastReminderChannel']);
	}//end testAFailingMailFallsThroughToTheNextChannel()

	/**
	 * A failed booking write is logged and does not undo the audited send.
	 *
	 * @return void
	 */
	public function testAFailedBookingWriteKeepsTheSend(): void {
		$service = $this->service(saveFails: true);

		$service->dispatchNotification(
			trigger: ['eventType' => 'reminder'],
			recipient: ['channels' => ['email'], 'address' => 'ann@example.com'],
			template: ['subject' => 's', 'body' => 'b'],
			booking: $this->booking()
		);

		self::assertCount(1, $this->mails);
		self::assertSame('sent', $this->deliveries()[0]['status']);
	}//end testAFailedBookingWriteKeepsTheSend()

	/**
	 * An address that is not an email address is not handed to the mail server.
	 *
	 * @return void
	 */
	public function testANonEmailAddressIsNotMailed(): void {
		$service = $this->service();

		$service->dispatchNotification(
			trigger: ['eventType' => 'created'],
			recipient: ['channels' => ['email'], 'address' => '+31612345678'],
			template: ['subject' => 's', 'body' => 'b'],
			booking: $this->booking()
		);

		self::assertSame([], $this->mails);
		self::assertSame('failed', $this->deliveries()[0]['status']);
	}//end testANonEmailAddressIsNotMailed()
}//end class
