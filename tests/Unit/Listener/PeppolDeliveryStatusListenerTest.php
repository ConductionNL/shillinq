<?php

/**
 * Unit tests for PeppolDeliveryStatusListener (REQ-EINV-005 / REQ-AR-011).
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
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-005
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\PeppolDeliveryStatusListener;
use OCP\EventDispatcher\GenericEvent;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Covers: sent -> delivered advances + persists detail; in-flight -> rejected
 * advances + persists detail + notifies ar-controller operators; illegal
 * transitions are skipped (fail-soft, no corruption).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeppolDeliveryStatusListenerTest extends TestCase {
	/**
	 * Per-notification mock state-bag (spl_object_id => stdClass).
	 *
	 * @var array<int,object>
	 */
	private array $notificationState = [];

	/**
	 * REQ-AR-011 scenario: sent -> delivered advances the sub-lifecycle and
	 * persists the event detail.
	 *
	 * @return void
	 */
	public function testSentToDeliveredAdvancesAndPersistsDetail(): void {
		$saved = [];
		$data = [
			'ARInvoice' => [
				[
					'id' => 'invoice-1',
					'invoiceNumber' => '2026-0051',
					'administrationId' => 'adm-1',
					'deliveryStatus' => 'sent',
					'transmissionId' => 'urn:uuid:old',
				],
			],
		];

		$listener = $this->buildListener(data: $data, saved: $saved, notifications: $notifications);

		$listener->handle(
			new GenericEvent(
				null,
				[
					'objectUri' => 'openregister://shillinq/ARInvoice/invoice-1',
					'transmissionId' => 'urn:uuid:old',
					'status' => 'delivered',
					'timestamp' => '2026-06-20T10:00:00+00:00',
					'detail' => 'Delivered to recipient AP',
				]
			)
		);

		$arSaves = array_values(array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertCount(1, $arSaves);
		self::assertSame('delivered', $arSaves[0]['object']['deliveryStatus']);
		self::assertSame('Delivered to recipient AP', $arSaves[0]['object']['deliveryDetail']);

	}//end testSentToDeliveredAdvancesAndPersistsDetail()

	/**
	 * REQ-AR-011 / REQ-EINV-005 scenario: an in-flight invoice rejected —
	 * deliveryStatus -> rejected, detail persisted, ar-controller notified.
	 *
	 * @return void
	 */
	public function testInFlightRejectedNotifiesFinanceOperators(): void {
		$saved = [];
		$data = [
			'ARInvoice' => [
				[
					'id' => 'invoice-1',
					'invoiceNumber' => '2026-0060',
					'administrationId' => 'adm-1',
					'deliveryStatus' => 'queued',
				],
			],
			'AdministrationMembership' => [
				['administrationId' => 'adm-1', 'role' => 'ar-controller', 'userId' => 'controller-1'],
				['administrationId' => 'adm-1', 'role' => 'inkoper', 'userId' => 'buyer-1'],
			],
		];

		$listener = $this->buildListener(data: $data, saved: $saved, notifications: $notifications);

		$listener->handle(
			new GenericEvent(
				null,
				[
					'objectUri' => 'openregister://shillinq/ARInvoice/invoice-1',
					'status' => 'rejected',
					'detail' => 'Unknown recipient participant',
				]
			)
		);

		$arSaves = array_values(array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertSame('rejected', $arSaves[0]['object']['deliveryStatus']);
		self::assertSame('Unknown recipient participant', $arSaves[0]['object']['deliveryDetail']);

		self::assertCount(1, $notifications);
		self::assertSame('controller-1', $notifications[0]['user']);

	}//end testInFlightRejectedNotifiesFinanceOperators()

	/**
	 * An illegal transition (e.g. not-sent -> delivered, skipping queued/sent)
	 * is skipped — fail-soft, no state corruption.
	 *
	 * @return void
	 */
	public function testIllegalTransitionIsSkipped(): void {
		$saved = [];
		$data = [
			'ARInvoice' => [
				[
					'id' => 'invoice-1',
					'invoiceNumber' => '2026-0070',
					'administrationId' => 'adm-1',
					'deliveryStatus' => 'not-sent',
				],
			],
		];

		$listener = $this->buildListener(data: $data, saved: $saved, notifications: $notifications);

		$listener->handle(
			new GenericEvent(
				null,
				[
					'objectUri' => 'openregister://shillinq/ARInvoice/invoice-1',
					'status' => 'delivered',
					'detail' => 'should not apply',
				]
			)
		);

		$arSaves = array_values(array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertCount(0, $arSaves, 'an illegal transition must never be persisted');

	}//end testIllegalTransitionIsSkipped()

	/**
	 * A non-GenericEvent is ignored without error.
	 *
	 * @return void
	 */
	public function testNonGenericEventIsIgnored(): void {
		$saved = [];
		$listener = $this->buildListener(data: [], saved: $saved, notifications: $notifications);

		$listener->handle(new \OCP\EventDispatcher\Event());

		self::assertSame([], $saved);

	}//end testNonGenericEventIsIgnored()

	/**
	 * Build a listener over an in-memory ObjectService stub + notification manager mock.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,array<string,mixed>>|null $notifications Captured notifications (by reference, out param).
	 *
	 * @return PeppolDeliveryStatusListener
	 */
	private function buildListener(array $data, array &$saved, ?array &$notifications): PeppolDeliveryStatusListener {
		$notifications = [];

		$stub = new class($data, $saved) {
			/**
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () : INotification {
				$state = (object)['user' => '', 'subject' => ''];

				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use ($notification, $state): INotification {
						$state->user = $user;
						return $notification;
					}
				);
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject) use ($notification, $state): INotification {
						$state->subject = $subject;
						return $notification;
					}
				);

				$this->notificationState[spl_object_id($notification)] = $state;
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use (&$notifications): void {
				$state = ($this->notificationState[spl_object_id($notification)] ?? null);
				if ($state !== null) {
					$notifications[] = ['user' => $state->user, 'subject' => $state->subject];
				}
			}
		);

		return new PeppolDeliveryStatusListener(
			container: $container,
			appConfig: $appConfig,
			notificationManager: $manager,
			logger: new NullLogger()
		);

	}//end buildListener()
}//end class
