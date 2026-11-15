<?php

/**
 * Characterisation tests for the ExceptionResolutionService alert path.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins queries, notifications and log lines of notifyOnException.
 */
final class ExceptionResolutionAlertTest extends TestCase {

	/**
	 * Recorded events, in order: queries, notifications and log lines.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $events = [];

	/**
	 * Build the service over in-memory rows.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows        Schema => rows.
	 * @param array<int,string>                            $failingUids Users whose notify() throws.
	 *
	 * @return ExceptionResolutionService
	 */
	private function service(array $rows, array $failingUids): ExceptionResolutionService {
		$events = &$this->events;
		$store = new class($rows, $events) {
			private string $schema = '';

			public function __construct(private array $rows, private array &$events) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				$this->events[] = ['find', $this->schema, $filters];
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static fn ($row): bool => is_array($row) === false || array_intersect_assoc($filters, $row) === $filters
					)
				);
			}

			public function saveObject(array $object): array {
				return $object;
			}
		};
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($failingUids, &$events): void {
				$uid = $notification->getUser();
				if (in_array($uid, $failingUids, true) === true) {
					throw new \RuntimeException('push failed for ' . $uid);
				}
				$events[] = ['notify', $uid, $notification->getObjectId(), $notification->getSubject(), $notification->getSubjectParameters()];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new ExceptionResolutionService(
			$config,
			$context,
			$this->createMock(IUserSession::class),
			$manager,
			$logger,
			new DuckObjectServiceAdapter($store)
		);

	}//end service()

	/**
	 * A notification that remembers what it was given.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$state = ['app' => '', 'user' => '', 'id' => '', 'type' => '', 'subject' => '', 'params' => []];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(
			function (string $app) use (&$state, $notification): INotification {
				$state['app'] = $app;
				return $notification;
			}
		);
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$state, $notification): INotification {
				$state['user'] = $user;
				return $notification;
			}
		);
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use (&$state, $notification): INotification {
				$state['type'] = $type;
				$state['id'] = $id;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params = []) use (&$state, $notification): INotification {
				$state['subject'] = $subject;
				$state['params'] = $params;
				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(function () use (&$state): string {
			return $state['user'];
		});
		$notification->method('getObjectId')->willReturnCallback(function () use (&$state): string {
			return $state['app'] . '/' . $state['type'] . '/' . $state['id'];
		});
		$notification->method('getSubject')->willReturnCallback(function () use (&$state): string {
			return $state['subject'];
		});
		$notification->method('getSubjectParameters')->willReturnCallback(function () use (&$state): array {
			return $state['params'];
		});

		return $notification;

	}//end notification()

	/**
	 * Rows for one exception match with routing through a tolerance profile.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private function rows(): array {
		return [
			'ThreeWayMatch' => [
				[
					'id' => 'twm-1',
					'invoiceId' => 'inv-1',
					'matchStatus' => 'exception_quantity',
					'administrationId' => 'adm-1',
					'divergenceDetails' => ['junk', ['toleranceProfileId' => '  '], ['toleranceProfileId' => 'TP-NONE'], ['toleranceProfileId' => 'TP-EMPTY'], ['toleranceProfileId' => ' TP-C ']],
				],
				['id' => 'twm-2', 'matchStatus' => 'matched', 'administrationId' => 'adm-1'],
				['id' => 'twm-3', 'matchStatus' => 'exception_price', 'administrationId' => 'adm-1', 'divergenceDetails' => 'oops'],
			],
			'SupplierInvoice' => [
				['id' => 'inv-1', 'invoiceNumber' => 'N-1', 'supplierId' => 's-1', 'totalInclVat' => '1210', 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-EMPTY', 'exceptionRouting' => ' ', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-C', 'exceptionRouting' => ' controller ', 'administrationId' => 'adm-1'],
			],
			'AdministrationMembership' => [
				['administrationId' => 'adm-1', 'role' => 'controller', 'userId' => ' u1 '],
				['administrationId' => 'adm-1', 'role' => 'controller', 'userId' => 'u2'],
				['administrationId' => 'adm-1', 'role' => 'controller', 'userId' => 'u1'],
				['administrationId' => 'adm-1', 'role' => 'controller', 'userId' => ''],
			],
		];

	}//end rows()

	/**
	 * Pin routing, recipient dedupe, parameters, a failing delivery and the
	 * exact query order.
	 *
	 * @return void
	 */
	public function testRoutedAlertQueriesNotifiesAndLogsExactly(): void {
		$this->service($this->rows(), ['u2'])->notifyOnException('adm-1', 'twm-1');

		$params = ['matchId' => 'twm-1', 'invoiceId' => 'inv-1', 'invoiceNumber' => 'N-1', 'supplierId' => 's-1', 'totalInclVat' => 1210, 'currency' => 'EUR'];
		self::assertSame(
			[
				['find', 'ThreeWayMatch', ['id' => 'twm-1', 'administrationId' => 'adm-1']],
				['find', 'SupplierInvoice', ['id' => 'inv-1', 'administrationId' => 'adm-1']],
				['find', 'ToleranceProfile', ['profileId' => 'TP-NONE', 'administrationId' => 'adm-1']],
				['find', 'ToleranceProfile', ['profileId' => 'TP-EMPTY', 'administrationId' => 'adm-1']],
				['find', 'ToleranceProfile', ['profileId' => 'TP-C', 'administrationId' => 'adm-1']],
				['find', 'AdministrationMembership', ['administrationId' => 'adm-1', 'role' => 'controller']],
				['notify', 'u1', 'shillinq/three_way_match_exception/twm-1', 'three_way_match_exception_raised', $params],
				[
					'warning',
					'ExceptionResolutionService: failed to dispatch exception notification',
					['administrationId' => 'adm-1', 'userId' => 'u2', 'role' => 'controller', 'subject' => 'three_way_match_exception_raised', 'matchId' => 'twm-1', 'exception' => 'push failed for u2'],
				],
			],
			$this->events
		);

	}//end testRoutedAlertQueriesNotifiesAndLogsExactly()

	/**
	 * Pin the quiet exits (unknown or resolved match), the default role for a
	 * non-list divergence, the missing-invoice parameters and the
	 * no-recipients log line; and the tenant refusal.
	 *
	 * @return void
	 */
	public function testQuietExitsDefaultRoleAndNoRecipients(): void {
		$rows = $this->rows();
		$rows['AdministrationMembership'] = [];
		$service = $this->service($rows, []);
		$service->notifyOnException('adm-1', 'twm-x');
		$service->notifyOnException('adm-1', 'twm-2');
		$service->notifyOnException('adm-1', 'twm-3');

		self::assertSame(
			[
				['find', 'ThreeWayMatch', ['id' => 'twm-x', 'administrationId' => 'adm-1']],
				['find', 'ThreeWayMatch', ['id' => 'twm-2', 'administrationId' => 'adm-1']],
				['find', 'ThreeWayMatch', ['id' => 'twm-3', 'administrationId' => 'adm-1']],
				['find', 'AdministrationMembership', ['administrationId' => 'adm-1', 'role' => 'crediteuren_administrateur']],
				[
					'info',
					'ExceptionResolutionService: no recipients for exception alert',
					['administrationId' => 'adm-1', 'role' => 'crediteuren_administrateur', 'matchId' => 'twm-3'],
				],
			],
			$this->events
		);

		$this->events = [];
		$rows['AdministrationMembership'] = [['administrationId' => 'adm-1', 'role' => 'crediteuren_administrateur', 'userId' => 'ca']];
		$this->service($rows, [])->notifyOnException('adm-1', 'twm-3');
		$params = ['matchId' => 'twm-3', 'invoiceId' => '', 'invoiceNumber' => '', 'supplierId' => '', 'totalInclVat' => 0, 'currency' => 'EUR'];
		self::assertSame(['notify', 'ca', 'shillinq/three_way_match_exception/twm-3', 'three_way_match_exception_raised', $params], $this->events[2]);

		$this->expectExceptionMessage('Administration not found');
		$service->notifyOnException('adm-2', 'twm-1');

	}//end testQuietExitsDefaultRoleAndNoRecipients()
}//end class
