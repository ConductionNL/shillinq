<?php

/**
 * Unit tests for ExceptionResolutionService (slice 08 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-005:
 *  - acceptWithMotivation() stamps resolution_action=accepted +
 *    resolved_by + resolved_at + notes; the linked invoice advances to
 *    `approved` (payment unblocked);
 *  - fileDispute() composes a UBL CreditNote dispute envelope, hands it
 *    to the CreditNoteRequestAdapter, records
 *    resolution_action=credit_note_requested with the dispatch id
 *    appended to resolutionNotes, escalates to the Inkoper notification
 *    queue and KEEPS the invoice in `exception` (payment stays blocked);
 *  - rejectAndBlockPayment() stamps resolution_action=rejected and
 *    advances the linked invoice to `rejected`;
 *  - server-authoritative guards reject cross-tenant access, blank
 *    notes/reasons, missing matches and non-exception match statuses
 *    (ADR-005);
 *  - notifyOnException() dispatches to the role declared on the
 *    ToleranceProfile.exceptionRouting when present, defaulting to the
 *    crediteuren-administrateur queue.
 *
 * The OpenRegister ObjectService is stubbed with an in-memory
 * schema-keyed store that honours equality filters; the
 * INotificationManager is mocked to capture dispatches; the
 * CreditNoteRequestAdapter is a stub that captures the payload. The
 * test layout mirrors MultiPoConsolidationServiceTest so the chain stays
 * drop-in compatible.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Service\PurchaseOrder\CreditNoteRequestAdapterInterface;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OpenRegister-backed exception-resolution service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExceptionResolutionServiceTest extends TestCase {

	/**
	 * Captured CreditNote adapter payloads, per test run.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $dispatchedPayloads = [];

	/**
	 * Captured NC notifications, per test run.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $notifications = [];

	/**
	 * Reset capture buffers between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dispatchedPayloads = [];
		$this->notifications = [];

	}//end setUp()

	/**
	 * Build the service over an in-memory ObjectService stub seeded with
	 * the supplied schema=>rows map.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param array{accepted:bool,dispatchId:?string,error:?string}|null $dispatchResult Adapter outcome (default success).
	 * @param string $userId UID returned by the session
	 *                       (default `alice`).
	 *
	 * @return ExceptionResolutionService
	 */
	private function buildService(
		array $data,
		array &$saved,
		array $accessibleAdministrations,
		?array $dispatchResult = null,
		string $userId = 'alice',
	): ExceptionResolutionService {
		$stub = $this->objectServiceStub(data: $data, saved: $saved);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

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
				// No-op — the createNotification capture already pinned it.
			}
		);

		$defaultResult = ($dispatchResult ?? ['accepted' => true, 'dispatchId' => 'urn:uuid:cn-test', 'error' => null]);
		$adapter = new class($this->dispatchedPayloads, $defaultResult) implements CreditNoteRequestAdapterInterface {

			/**
			 * Capture buffer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $payloads;

			/**
			 * Fixed result.
			 *
			 * @var array{accepted:bool,dispatchId:?string,error:?string}
			 */
			private array $result;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $payloads Capture ref.
			 * @param array{accepted:bool,dispatchId:?string,error:?string} $result Fixed result.
			 */
			public function __construct(array &$payloads, array $result) {
				$this->payloads = &$payloads;
				$this->result = $result;
			}//end __construct()

			/**
			 * Capture the payload, return the fixed result.
			 *
			 * @param array<string,mixed> $payload Dispute envelope.
			 *
			 * @return array{accepted:bool,dispatchId:?string,error:?string}
			 */
			public function submitDisputeCreditNote(array $payload): array {
				$this->payloads[] = $payload;
				return $this->result;
			}//end submitDisputeCreditNote()
		};

		return new ExceptionResolutionService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			notificationManager: $notificationManager,
			logger: $logger,
			creditNoteAdapter: $adapter,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build an in-memory OpenRegister ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 * @param array<int,array<string,mixed>> $saved Capture ref.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
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
			 * Auto-increment counter for saved objects.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (ignored).
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
	 * Seed an exception_price ThreeWayMatch with one linked invoice, PO
	 * and AdministrationMembership row for the
	 * crediteuren-administrateur queue.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function baselineSeed(): array {
		return [
			'ThreeWayMatch' => [
				[
					'id' => 'twm-1',
					'invoiceId' => 'inv-1',
					'matchedPoIds' => ['po-1'],
					'matchedGrnIds' => ['grn-1'],
					'matchStatus' => 'exception_price',
					'divergenceDetails' => [
						[
							'field' => 'unitPrice',
							'expected' => 18500_00,
							'actual' => 19250_00,
							'deltaCents' => 750_00,
							'deltaPercentage' => 405,
							'toleranceProfileId' => null,
						],
					],
					'createdAt' => '2026-07-21T08:00:00+00:00',
					'administrationId' => 'adm-1',
				],
			],
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'invoiceNumber' => 'INV-NW-001',
					'supplierId' => 'sup-1',
					'currency' => 'EUR',
					'totalExclVat' => 1900000,
					'totalVat' => 25000,
					'totalInclVat' => 1925000,
					'statusCode' => 'exception',
					'administrationId' => 'adm-1',
				],
			],
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

	}//end baselineSeed()

	/**
	 * Test acceptWithMotivation stamps resolution_action=accepted + notes
	 * + resolvedBy + resolvedAt and advances the linked invoice to
	 * approved.
	 *
	 * @return void
	 */
	public function testAcceptWithMotivationCapturesAuditAndAdvancesInvoice(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$result = $service->acceptWithMotivation(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			resolutionNotes: 'Verschil binnen jaarcontract tolerantie — alsnog akkoord.'
		);

		self::assertSame('twm-1', $result['id']);
		self::assertSame(ExceptionResolutionService::ACTION_ACCEPTED, $result['resolutionAction']);
		self::assertSame('alice', $result['resolvedBy']);
		self::assertNotSame('', (string)$result['resolvedAt']);
		self::assertSame(
			'Verschil binnen jaarcontract tolerantie — alsnog akkoord.',
			$result['resolutionNotes']
		);

		// Invoice advanced to approved (payment unblocked).
		$invoiceSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertCount(1, $invoiceSaves);
		self::assertSame('approved', $invoiceSaves[0]['object']['statusCode']);

	}//end testAcceptWithMotivationCapturesAuditAndAdvancesInvoice()

	/**
	 * Test acceptWithMotivation rejects a blank motivation (mandatory).
	 *
	 * @return void
	 */
	public function testAcceptWithMotivationRejectsBlankNotes(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resolutionNotes is required');

		$service->acceptWithMotivation(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			resolutionNotes: '   '
		);

	}//end testAcceptWithMotivationRejectsBlankNotes()

	/**
	 * Test acceptWithMotivation refuses an already-resolved match — the
	 * audit trail stays clean.
	 *
	 * @return void
	 */
	public function testAcceptWithMotivationRefusesAlreadyResolvedMatch(): void {
		$seed = $this->baselineSeed();
		$seed['ThreeWayMatch'][0]['matchStatus'] = 'auto_approved';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Match is not in an exception state');

		$service->acceptWithMotivation(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			resolutionNotes: 'attempted reopen'
		);

	}//end testAcceptWithMotivationRefusesAlreadyResolvedMatch()

	/**
	 * Cross-tenant calls mask as RuntimeException("Administration not
	 * found") — ADR-005 IDOR-safe.
	 *
	 * @return void
	 */
	public function testAcceptWithMotivationRejectsCrossTenantAccess(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->acceptWithMotivation(
			administrationId: 'adm-2',
			matchId: 'twm-1',
			resolutionNotes: 'cross-tenant attempt'
		);

	}//end testAcceptWithMotivationRejectsCrossTenantAccess()

	/**
	 * Test fileDispute composes the UBL CreditNote envelope, dispatches
	 * it, records credit_note_requested with the dispatch id appended to
	 * resolutionNotes, escalates to the Inkoper queue and KEEPS the
	 * invoice in exception (payment stays blocked).
	 *
	 * @return void
	 */
	public function testFileDisputeDispatchesUblAndEscalatesToInkoper(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1'],
			dispatchResult: ['accepted' => true, 'dispatchId' => 'urn:uuid:cn-feed-beef', 'error' => null]
		);

		$result = $service->fileDispute(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			disputeReason: 'Prijs 4,05% boven contractprijs — graag creditfactuur.'
		);

		self::assertArrayHasKey('match', $result);
		self::assertArrayHasKey('dispatch', $result);

		$match = $result['match'];
		$dispatch = $result['dispatch'];

		self::assertSame(ExceptionResolutionService::ACTION_CREDIT_NOTE_REQUESTED, $match['resolutionAction']);
		self::assertSame('alice', $match['resolvedBy']);
		self::assertNotSame('', (string)$match['resolvedAt']);
		self::assertStringContainsString('urn:uuid:cn-feed-beef', (string)$match['resolutionNotes']);

		self::assertTrue($dispatch['accepted']);
		self::assertSame('urn:uuid:cn-feed-beef', $dispatch['dispatchId']);

		// The adapter received the UBL envelope.
		self::assertCount(1, $this->dispatchedPayloads);
		$payload = $this->dispatchedPayloads[0];
		self::assertSame('twm-1', $payload['matchId']);
		self::assertSame('inv-1', $payload['invoiceId']);
		self::assertSame('INV-NW-001', $payload['invoiceNumber']);
		self::assertSame('sup-1', $payload['supplierId']);
		self::assertSame(1925000, $payload['totalInclVat']);
		self::assertSame('Prijs 4,05% boven contractprijs — graag creditfactuur.', $payload['reason']);

		// The invoice STAYS in exception — payment stays blocked.
		$invoiceSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertCount(0, $invoiceSaves);

		// One Inkoper escalation was queued.
		$inkoperNotifications = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['user'] === 'inkoper-bob'
			)
		);
		self::assertCount(1, $inkoperNotifications);
		self::assertSame(
			ExceptionResolutionService::NOTIFICATION_SUBJECT_DISPUTE,
			$inkoperNotifications[0]['subject']['key']
		);

	}//end testFileDisputeDispatchesUblAndEscalatesToInkoper()

	/**
	 * Test fileDispute logs but does not roll back when the adapter
	 * rejects the dispatch — the canonical ThreeWayMatch resolution is
	 * still persisted and the dispatch envelope surfaces the error to
	 * the caller.
	 *
	 * @return void
	 */
	public function testFileDisputeStillRecordsResolutionOnDispatchFailure(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1'],
			dispatchResult: ['accepted' => false, 'dispatchId' => null, 'error' => 'openconnector down']
		);

		$result = $service->fileDispute(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			disputeReason: 'Doorgaan ondanks adapter-uitval.'
		);

		self::assertFalse($result['dispatch']['accepted']);
		self::assertSame('openconnector down', $result['dispatch']['error']);

		self::assertSame(ExceptionResolutionService::ACTION_CREDIT_NOTE_REQUESTED, $result['match']['resolutionAction']);
		self::assertSame('Doorgaan ondanks adapter-uitval.', $result['match']['resolutionNotes']);

	}//end testFileDisputeStillRecordsResolutionOnDispatchFailure()

	/**
	 * Test rejectAndBlockPayment marks the match rejected and advances
	 * the linked invoice to `rejected` so the payment block is immediate.
	 *
	 * @return void
	 */
	public function testRejectAndBlockAdvancesInvoiceToRejected(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$result = $service->rejectAndBlockPayment(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			rejectionReason: 'Levering nooit ontvangen — afwijzen.'
		);

		self::assertSame(ExceptionResolutionService::ACTION_REJECTED, $result['resolutionAction']);
		self::assertSame('Levering nooit ontvangen — afwijzen.', $result['resolutionNotes']);
		self::assertSame('alice', $result['resolvedBy']);
		self::assertNotSame('', (string)$result['resolvedAt']);

		$invoiceSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertCount(1, $invoiceSaves);
		self::assertSame('rejected', $invoiceSaves[0]['object']['statusCode']);

	}//end testRejectAndBlockAdvancesInvoiceToRejected()

	/**
	 * Test rejectAndBlockPayment masks a forged matchId from another
	 * tenant as "ThreeWayMatch not found" — ADR-005 IDOR-safe.
	 *
	 * @return void
	 */
	public function testRejectAndBlockRejectsForgedCrossTenantMatch(): void {
		$seed = $this->baselineSeed();
		$seed['ThreeWayMatch'][0]['administrationId'] = 'adm-2';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ThreeWayMatch not found');

		$service->rejectAndBlockPayment(
			administrationId: 'adm-1',
			matchId: 'twm-1',
			rejectionReason: 'forged matchId'
		);

	}//end testRejectAndBlockRejectsForgedCrossTenantMatch()

	/**
	 * Test notifyOnException defaults to the crediteuren-administrateur
	 * queue when the match's divergenceDetails do not name a
	 * ToleranceProfile with an exceptionRouting tag.
	 *
	 * @return void
	 */
	public function testNotifyOnExceptionDefaultsToCrediteurenAdministrateur(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$service->notifyOnException(
			administrationId: 'adm-1',
			matchId: 'twm-1'
		);

		$credit = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['user'] === 'creditadmin'
			)
		);
		self::assertCount(1, $credit);
		self::assertSame(
			ExceptionResolutionService::NOTIFICATION_SUBJECT_EXCEPTION,
			$credit[0]['subject']['key']
		);
		self::assertSame(
			['type' => ExceptionResolutionService::NOTIFICATION_OBJECT_TYPE, 'id' => 'twm-1'],
			$credit[0]['object']
		);
		self::assertSame('INV-NW-001', $credit[0]['subject']['parameters']['invoiceNumber']);

	}//end testNotifyOnExceptionDefaultsToCrediteurenAdministrateur()

	/**
	 * Test notifyOnException routes to the role named in the matched
	 * ToleranceProfile.exceptionRouting when present — operators can
	 * pivot specific exception classes to a controller-approval queue
	 * without touching the service.
	 *
	 * @return void
	 */
	public function testNotifyOnExceptionHonoursToleranceProfileRouting(): void {
		$seed = $this->baselineSeed();
		$seed['ThreeWayMatch'][0]['divergenceDetails'][0]['toleranceProfileId'] = 'TP-CONTROLLER';
		$seed['ToleranceProfile'][] = [
			'id' => 'tp-1',
			'profileId' => 'TP-CONTROLLER',
			'exceptionRouting' => 'controller_approval',
			'administrationId' => 'adm-1',
		];
		$seed['AdministrationMembership'][] = [
			'id' => 'mem-3',
			'administrationId' => 'adm-1',
			'role' => 'controller_approval',
			'userId' => 'controller-claire',
		];

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$service->notifyOnException(
			administrationId: 'adm-1',
			matchId: 'twm-1'
		);

		$controllerNotifications = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['user'] === 'controller-claire'
			)
		);
		self::assertCount(1, $controllerNotifications);

		// The default crediteuren-administrateur queue MUST NOT be
		// notified — routing is exclusive.
		$defaultQueue = array_values(
			array_filter(
				$this->notifications,
				static fn (array $notification): bool => $notification['user'] === 'creditadmin'
			)
		);
		self::assertCount(0, $defaultQueue);

	}//end testNotifyOnExceptionHonoursToleranceProfileRouting()
}//end class
