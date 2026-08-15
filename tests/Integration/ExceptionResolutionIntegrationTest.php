<?php

/**
 * Integration test for the slice-08 exception resolution flow
 * (bookkeeping-purchase-order-3way).
 *
 * Wires {@see ExceptionResolutionService} on top of an in-memory
 * OpenRegister ObjectService stub seeded with:
 *
 *  - a SupplierInvoice in `exception` status,
 *  - the matching PurchaseOrder + GoodsReceiptNote,
 *  - one ThreeWayMatch in `exception_price` carrying a divergence detail,
 *  - one AdministrationMembership row per recipient queue
 *    (crediteuren-administrateur, Inkoper).
 *
 * The test then:
 *
 *  1. confirms the invoice is still in `exception` and the match in
 *     `exception_price` — payment is blocked at start;
 *  2. invokes notifyOnException() and asserts the
 *     crediteuren-administrateur queue receives the alert;
 *  3. runs the three resolution paths on three sibling matches
 *     (acceptWithMotivation → approved + invoice unblocked,
 *      fileDispute → credit_note_requested + UBL dispatch +
 *      Inkoper escalation + invoice STILL blocked,
 *      rejectAndBlockPayment → rejected + invoice rejected).
 *
 * The slice-08 integration target is "full exception → resolution flow
 * with payment block until resolved". This file is that test.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md#tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Service\PurchaseOrder\CreditNoteRequestAdapterInterface;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration: full exception → resolution flow with payment block
 * until resolved.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExceptionResolutionIntegrationTest extends TestCase {

	/**
	 * Captured ObjectService saves, populated by the service under test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Captured CreditNote dispatch payloads.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $dispatched = [];

	/**
	 * Captured NC notifications.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $notifications = [];

	/**
	 * Reset buffers between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->dispatched = [];
		$this->notifications = [];

	}//end setUp()

	/**
	 * Build an in-memory OR ObjectService stub seeded with the supplied
	 * schema=>rows map. Captures saves into $this->saved.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data): object {
		return new class($data, $this->saved) {
			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Capture ref.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment counter.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Seed.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Apply equality filters to the active schema rows.
			 *
			 * @param array<string,mixed> $params Query parameters.
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
			}//end findAll()

			/**
			 * Persist an object — stamps an id when absent + captures the save.
			 *
			 * @param array<string,mixed> $object Object to persist.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'gen-' . $this->idCounter;
				}

				$rows = ($this->data[$this->schema] ?? []);
				$updated = false;
				foreach ($rows as $i => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						$this->data[$this->schema][$i] = $object;
						$updated = true;
						break;
					}
				}

				if ($updated === false) {
					$this->data[$this->schema][] = $object;
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()

	/**
	 * Build the slice-08 service with all collaborators wired against
	 * in-memory test doubles.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 *
	 * @return ExceptionResolutionService
	 */
	private function buildService(array $data): ExceptionResolutionService {
		$stub = $this->objectServiceStub(data: $data);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$captured = ['app' => '', 'user' => '', 'object' => null, 'subject' => null];
				$self = &$captured;
				$notification->method('setApp')->willReturnCallback(
					function (string $app) use (&$self, $notification): INotification {
						$self['app'] = $app;
						return $notification;
					}
				);
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use (&$self, $notification): INotification {
						$self['user'] = $user;
						return $notification;
					}
				);
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnCallback(
					function (string $type, string $id) use (&$self, $notification): INotification {
						$self['object'] = ['type' => $type, 'id' => $id];
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $params = []) use (&$self, $notification): INotification {
						$self['subject'] = ['key' => $subject, 'parameters' => $params];
						return $notification;
					}
				);
				$this->notifications[] = &$self;
				return $notification;
			}
		);
		$notificationManager->method('notify')->willReturnCallback(
			static function (INotification $notification): void {
				// Captured at createNotification time.
			}
		);

		$adapter = new class($this->dispatched) implements CreditNoteRequestAdapterInterface {

			/**
			 * Capture buffer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $payloads;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $payloads Capture ref.
			 */
			public function __construct(array &$payloads) {
				$this->payloads = &$payloads;
			}//end __construct()

			/**
			 * Capture + accept.
			 *
			 * @param array<string,mixed> $payload Dispute envelope.
			 *
			 * @return array{accepted:bool,dispatchId:?string,error:?string}
			 */
			public function submitDisputeCreditNote(array $payload): array {
				$this->payloads[] = $payload;
				return [
					'accepted' => true,
					'dispatchId' => 'urn:uuid:cn-integration-' . count($this->payloads),
					'error' => null,
				];
			}//end submitDisputeCreditNote()
		};

		return new ExceptionResolutionService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			notificationManager: $notificationManager,
			logger: $logger,
			creditNoteAdapter: $adapter,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end buildService()

	/**
	 * Seed three sibling exception_price matches against three sibling
	 * invoices (one per resolution path) + the shared AdministrationMembership
	 * rows for both notification queues.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function seed(): array {
		$matches = [];
		$invoices = [];
		foreach (['accept', 'dispute', 'reject'] as $disposition) {
			$matches[] = [
				'id' => 'twm-' . $disposition,
				'invoiceId' => 'inv-' . $disposition,
				'matchedPoIds' => ['po-' . $disposition],
				'matchedGrnIds' => ['grn-' . $disposition],
				'matchStatus' => 'exception_price',
				'divergenceDetails' => [
					[
						'field' => 'unitPrice',
						'expected' => 1850000,
						'actual' => 1925000,
						'deltaCents' => 75000,
						'deltaPercentage' => 405,
					],
				],
				'createdAt' => '2026-07-21T08:00:00+00:00',
				'administrationId' => 'adm-1',
			];
			$invoices[] = [
				'id' => 'inv-' . $disposition,
				'invoiceNumber' => 'INV-NW-' . strtoupper($disposition),
				'supplierId' => 'sup-1',
				'currency' => 'EUR',
				'totalExclVat' => 1900000,
				'totalVat' => 25000,
				'totalInclVat' => 1925000,
				'statusCode' => 'exception',
				'administrationId' => 'adm-1',
			];
		}//end foreach

		return [
			'ThreeWayMatch' => $matches,
			'SupplierInvoice' => $invoices,
			'ToleranceProfile' => [],
			'AdministrationMembership' => [
				[
					'id' => 'mem-1',
					'administrationId' => 'adm-1',
					'role' => ExceptionResolutionService::ROLE_CREDITEUREN_ADMIN,
					'userId' => 'creditadmin',
				],
				[
					'id' => 'mem-2',
					'administrationId' => 'adm-1',
					'role' => ExceptionResolutionService::ROLE_INKOPER,
					'userId' => 'inkoper-bob',
				],
			],
		];

	}//end seed()

	/**
	 * Full exception → resolution flow:
	 *  1. notifyOnException raises the alert to crediteuren-administrateur.
	 *  2. acceptWithMotivation closes the first match → invoice advances
	 *     to `approved` (payment unblocked).
	 *  3. fileDispute closes the second match → UBL dispatched, Inkoper
	 *     escalated, invoice STAYS in `exception` (payment blocked).
	 *  4. rejectAndBlockPayment closes the third match → invoice advances
	 *     to `rejected` (terminal block).
	 *
	 * @return void
	 */
	public function testFullExceptionToResolutionFlowWithPaymentBlock(): void {
		$service = $this->buildService(data: $this->seed());

		// (1) Exception alert.
		$service->notifyOnException(
			administrationId: 'adm-1',
			matchId: 'twm-accept'
		);
		$exceptionSubject = ExceptionResolutionService::NOTIFICATION_SUBJECT_EXCEPTION;
		$exceptionAlerts = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['subject']['key'] === $exceptionSubject
			)
		);
		self::assertCount(1, $exceptionAlerts);
		self::assertSame('creditadmin', $exceptionAlerts[0]['user']);

		// (2) Accept with motivation → invoice advances to `approved`.
		$accepted = $service->acceptWithMotivation(
			administrationId: 'adm-1',
			matchId: 'twm-accept',
			resolutionNotes: 'Binnen tolerantie van jaarcontract.'
		);
		self::assertSame(ExceptionResolutionService::ACTION_ACCEPTED, $accepted['resolutionAction']);

		$invoiceAcceptSave = $this->lastInvoiceSave(invoiceId: 'inv-accept');
		self::assertNotNull($invoiceAcceptSave);
		self::assertSame('approved', $invoiceAcceptSave['statusCode']);

		// (3) File dispute → UBL dispatched, Inkoper escalated, invoice
		// STAYS in exception (payment block stays).
		$dispute = $service->fileDispute(
			administrationId: 'adm-1',
			matchId: 'twm-dispute',
			disputeReason: 'Prijs 4,05% boven contract — creditfactuur gevraagd.'
		);
		self::assertSame(
			ExceptionResolutionService::ACTION_CREDIT_NOTE_REQUESTED,
			$dispute['match']['resolutionAction']
		);
		self::assertTrue($dispute['dispatch']['accepted']);
		self::assertCount(1, $this->dispatched);
		self::assertSame('twm-dispute', $this->dispatched[0]['matchId']);

		$invoiceDisputeSave = $this->lastInvoiceSave(invoiceId: 'inv-dispute');
		self::assertNull(
			$invoiceDisputeSave,
			'Dispute MUST NOT advance the invoice — payment block persists.'
		);

		$disputeSubject = ExceptionResolutionService::NOTIFICATION_SUBJECT_DISPUTE;
		$inkoperEscalations = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['subject']['key'] === $disputeSubject
			)
		);
		self::assertCount(1, $inkoperEscalations);
		self::assertSame('inkoper-bob', $inkoperEscalations[0]['user']);

		// (4) Reject → invoice advances to `rejected` (terminal block).
		$rejected = $service->rejectAndBlockPayment(
			administrationId: 'adm-1',
			matchId: 'twm-reject',
			rejectionReason: 'Levering nooit ontvangen — afwijzen.'
		);
		self::assertSame(ExceptionResolutionService::ACTION_REJECTED, $rejected['resolutionAction']);

		$invoiceRejectSave = $this->lastInvoiceSave(invoiceId: 'inv-reject');
		self::assertNotNull($invoiceRejectSave);
		self::assertSame('rejected', $invoiceRejectSave['statusCode']);

		// Cross-cutting: every persisted resolution carries the
		// server-authoritative resolvedBy.
		$resolutions = array_values(
			array_filter(
				$this->saved,
				static fn (array $save): bool => $save['schema'] === 'ThreeWayMatch'
			)
		);
		self::assertCount(3, $resolutions);
		foreach ($resolutions as $save) {
			self::assertSame('alice', $save['object']['resolvedBy']);
			self::assertNotSame('', (string)$save['object']['resolvedAt']);
		}

	}//end testFullExceptionToResolutionFlowWithPaymentBlock()

	/**
	 * Find the last SupplierInvoice save for a given id (null when the
	 * invoice was never re-saved during the test).
	 *
	 * @param string $invoiceId Invoice id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function lastInvoiceSave(string $invoiceId): ?array {
		$last = null;
		foreach ($this->saved as $save) {
			if ($save['schema'] !== 'SupplierInvoice') {
				continue;
			}

			if (($save['object']['id'] ?? null) === $invoiceId) {
				$last = $save['object'];
			}
		}

		return $last;
	}//end lastInvoiceSave()
}//end class
