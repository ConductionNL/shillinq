<?php

/**
 * Unit tests for ExtractionCompletedListener (REQ-RXC-001).
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
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\DocuDesk\Event;

// Minimal in-test stub of the docudesk FinancialExtractionCompletedEvent
// contract so ExtractionCompletedListener::handle() can class_exists()-guard
// and read the payload back without the docudesk app present. The real class
// lives in docudesk; shillinq only consumes this shape (matches
// SigningDelegationServiceTest's established pattern for stubbing a docudesk
// cross-app event class under its real namespace).
if (class_exists(\OCA\DocuDesk\Event\FinancialExtractionCompletedEvent::class, false) === false) {
	class FinancialExtractionCompletedEvent extends \OCP\EventDispatcher\Event {
		/**
		 * @param array<string,mixed> $fields
		 * @param array<string,float> $fieldConfidence
		 */
		public function __construct(
			private readonly string $documentUri,
			private readonly string $requestedBy,
			private readonly string $sourceApp,
			private readonly string $docType,
			private readonly array $fields,
			private readonly array $fieldConfidence,
			private readonly float $overallConfidence,
		) {
			parent::__construct();
		}//end __construct()

		public function getDocumentUri(): string {
			return $this->documentUri;
		}//end getDocumentUri()

		public function getRequestedBy(): string {
			return $this->requestedBy;
		}//end getRequestedBy()

		public function getSourceApp(): string {
			return $this->sourceApp;
		}//end getSourceApp()

		public function getDocType(): string {
			return $this->docType;
		}//end getDocType()

		/**
		 * @return array<string,mixed>
		 */
		public function getFields(): array {
			return $this->fields;
		}//end getFields()

		/**
		 * @return array<string,float>
		 */
		public function getFieldConfidence(): array {
			return $this->fieldConfidence;
		}//end getFieldConfidence()

		public function getOverallConfidence(): float {
			return $this->overallConfidence;
		}//end getOverallConfidence()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\DocuDesk\Event\FinancialExtractionCompletedEvent;
use OCA\Shillinq\Listener\ExtractionCompletedListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Covers: a supplier-invoice event creates an uncommitted draft with field
 * values + confidence (REQ-RXC-001 scenario 1); an unmatched documentUri
 * creates a pending-review draft AND notifies requestedBy (scenario 2); a
 * re-request for an existing draft refreshes it in place rather than
 * duplicating; a non-matching event class is ignored.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExtractionCompletedListenerTest extends TestCase {

	/**
	 * Per-notification mock state-bag (spl_object_id => stdClass).
	 *
	 * @var array<int,object>
	 */
	private array $notificationState = [];

	/**
	 * Logger handed to the listener; null means a NullLogger.
	 *
	 * @var \Psr\Log\LoggerInterface|null
	 */
	private ?\Psr\Log\LoggerInterface $listenerLogger = null;

	/**
	 * REQ-RXC-001 scenario 1: a supplier-invoice extraction becomes a
	 * confidence-scored draft.
	 *
	 * @return void
	 */
	public function testSupplierInvoiceExtractionCreatesDraft(): void {
		$saved = [];
		$listener = $this->buildListener(existing: [], saved: $saved, notifications: $notifications);

		$listener->handle(
			$this->makeEvent(
				docType: 'supplier-invoice',
				fields: ['invoiceNumber' => 'F-2026-88', 'totalIncl' => 1210.0, 'supplierName' => 'ACME'],
				fieldConfidence: ['invoiceNumber' => 0.97, 'totalIncl' => 0.9],
				overallConfidence: 0.93,
			)
		);

		self::assertCount(1, $saved);
		self::assertSame('SupplierInvoice', $saved[0]['schema']);
		$record = $saved[0]['object'];
		self::assertSame('F-2026-88', $record['invoiceNumber']);
		self::assertSame(121000, $record['totalInclVat']);
		self::assertSame(0.93, $record['overallConfidence']);
		self::assertSame(0.97, $record['fieldConfidence']['invoiceNumber']);
		self::assertSame('pending-review', $record['extractionStatus']);
		self::assertSame([], $record['humanCorrected']);
		self::assertSame('docudesk://attachments/x/invoice.pdf', $record['sourceDocumentUri']);
		// Never auto-committed: statusCode stays the schema default, never advances a lifecycle.
		self::assertSame('received', $record['statusCode']);

	}//end testSupplierInvoiceExtractionCreatesDraft()

	/**
	 * REQ-RXC-001 scenario 2: an unmatched documentUri is not dropped — a
	 * pending-review draft is created AND requestedBy is notified.
	 *
	 * @return void
	 */
	public function testUnmatchedDocumentUriCreatesDraftAndNotifies(): void {
		$saved = [];
		$listener = $this->buildListener(existing: [], saved: $saved, notifications: $notifications);

		$listener->handle(
			$this->makeEvent(
				docType: 'receipt',
				fields: ['totalIncl' => 45.0, 'issueDate' => '2026-02-10', 'currency' => 'EUR'],
				fieldConfidence: ['totalIncl' => 0.9],
				overallConfidence: 0.88,
				requestedBy: 'bob',
			)
		);

		self::assertCount(1, $saved);
		self::assertSame('Receipt', $saved[0]['schema']);
		self::assertCount(1, $notifications);
		self::assertSame('bob', $notifications[0]['user']);

	}//end testUnmatchedDocumentUriCreatesDraftAndNotifies()

	/**
	 * REQ-RXC-005: a re-extraction for a documentUri that already has a
	 * draft refreshes that draft in place rather than creating a duplicate,
	 * and does NOT notify (the draft already exists / was surfaced once).
	 *
	 * @return void
	 */
	public function testReExtractionRefreshesExistingDraftWithoutDuplicating(): void {
		$saved = [];
		$existing = [
			[
				'id' => 'draft-1',
				'invoiceNumber' => 'F-OLD',
				'sourceDocumentUri' => 'docudesk://attachments/x/invoice.pdf',
				'administrationId' => 'adm-1',
				'statusCode' => 'received',
				'humanCorrected' => ['invoiceNumber'],
				'extractionStatus' => 'confirmed',
			],
		];
		$listener = $this->buildListener(existing: $existing, saved: $saved, notifications: $notifications);

		$listener->handle(
			$this->makeEvent(
				docType: 'supplier-invoice',
				fields: ['invoiceNumber' => 'F-NEW', 'totalIncl' => 500.0],
				fieldConfidence: ['invoiceNumber' => 0.95],
				overallConfidence: 0.95,
			)
		);

		self::assertCount(1, $saved, 'a re-extraction must update, never duplicate, the existing draft');
		self::assertSame('draft-1', $saved[0]['object']['id']);
		self::assertSame('F-NEW', $saved[0]['object']['invoiceNumber']);
		// A fresh extraction supersedes the prior human correction / status.
		self::assertSame([], $saved[0]['object']['humanCorrected']);
		self::assertSame('pending-review', $saved[0]['object']['extractionStatus']);
		self::assertCount(0, $notifications, 'a refresh of an already-known draft does not re-notify');

	}//end testReExtractionRefreshesExistingDraftWithoutDuplicating()

	/**
	 * OpenRegister's findAll() answers entities. A re-extraction still finds its
	 * draft, and a new draft lands in the requester's own administration.
	 *
	 * @return void
	 */
	public function testEntityRowsFindTheDraftAndTheAdministration(): void {
		$saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'SupplierInvoice' => [['id' => 'draft-1', 'sourceDocumentUri' => 'docudesk://attachments/x/invoice.pdf', 'administrationId' => 'adm-1']],
				'Receipt' => [],
				'AdministrationMembership' => [['id' => 'm-1', 'userId' => 'bob', 'administrationId' => 'adm-7']],
			],
			$saved,
			findAllRendersEntities: true
		);
		$unused = [];
		$listener = $this->buildListenerOver(store: $store, notifications: $unused);

		$listener->handle($this->makeEvent(docType: 'supplier-invoice', fields: ['invoiceNumber' => 'F-NEW'], fieldConfidence: [], overallConfidence: 0.9));
		self::assertSame('draft-1', $saved[0]['object']['id'] ?? null, 'the existing draft is refreshed');
		self::assertSame('adm-1', $saved[0]['object']['administrationId']);

		$listener->handle($this->makeEvent(docType: 'receipt', fields: ['totalIncl' => 45.0], fieldConfidence: [], overallConfidence: 0.9, requestedBy: 'bob'));
		self::assertSame('adm-7', $saved[1]['object']['administrationId'], 'a new draft lands in the requester\'s administration');

	}//end testEntityRowsFindTheDraftAndTheAdministration()

	/**
	 * A new draft skips the requester's expired and not-yet-valid memberships and
	 * lands in the administration they currently belong to.
	 *
	 * @return void
	 */
	public function testANewDraftSkipsMembershipsOutsideTheirValidity(): void {
		$saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'Receipt' => [],
				'AdministrationMembership' => [
					['id' => 'm-old', 'userId' => 'bob', 'administrationId' => 'adm-former', 'validUntil' => '2020-01-01'],
					['id' => 'm-next', 'userId' => 'bob', 'administrationId' => 'adm-next', 'validFrom' => '2999-01-01'],
					['id' => 'm-now', 'userId' => 'bob', 'administrationId' => 'adm-7', 'validFrom' => '2020-01-01'],
				],
			],
			$saved,
			findAllRendersEntities: true
		);
		$unused = [];
		$listener = $this->buildListenerOver(store: $store, notifications: $unused);

		$listener->handle($this->makeEvent(docType: 'receipt', fields: ['totalIncl' => 45.0], fieldConfidence: [], overallConfidence: 0.9, requestedBy: 'bob'));
		self::assertSame('adm-7', $saved[0]['object']['administrationId']);

	}//end testANewDraftSkipsMembershipsOutsideTheirValidity()

	/**
	 * A requester with only an expired membership gets no administration of theirs.
	 *
	 * @return void
	 */
	public function testANewDraftOfARequesterWithOnlyAnExpiredMembershipGoesToDefault(): void {
		$saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'Receipt' => [],
				'AdministrationMembership' => [['id' => 'm-old', 'userId' => 'bob', 'administrationId' => 'adm-former', 'validUntil' => '2020-01-01']],
			],
			$saved,
			findAllRendersEntities: true
		);
		$unused = [];
		$listener = $this->buildListenerOver(store: $store, notifications: $unused);

		$listener->handle($this->makeEvent(docType: 'receipt', fields: ['totalIncl' => 45.0], fieldConfidence: [], overallConfidence: 0.9, requestedBy: 'bob'));
		self::assertSame('default', $saved[0]['object']['administrationId']);

	}//end testANewDraftOfARequesterWithOnlyAnExpiredMembershipGoesToDefault()

	/**
	 * The draft lookup that runs on each extraction is bounded, and the
	 * notification of a new draft names the draft's number.
	 *
	 * @return void
	 */
	public function testReadsAreBoundedAndTheNotificationNamesTheDraft(): void {
		$saved = [];
		$store = new class(['Receipt' => [], 'AdministrationMembership' => []], $saved, true) extends InMemoryObjectServiceStub {
			/**
			 * The limit of each findAll.
			 *
			 * @var array<int,mixed>
			 */
			public array $limits = [];

			/**
			 * Record the limit, then answer as the stub does.
			 *
			 * @param array<string,mixed> $config        The query.
			 * @param bool                $_rbac         Ignored.
			 * @param bool                $_multitenancy Ignored.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->limits[] = ($config['limit'] ?? null);
				return parent::findAll($config, $_rbac, $_multitenancy);
			}
		};
		$notifications = [];

		$this->buildListenerOver(store: $store, notifications: $notifications)->handle(
			$this->makeEvent(docType: 'receipt', fields: ['totalIncl' => 45.0], fieldConfidence: [], overallConfidence: 0.9, requestedBy: 'bob')
		);

		self::assertSame([1, null], $store->limits, 'the draft lookup is bounded; memberships load through AdministrationContextService');
		self::assertSame($saved[0]['object']['receiptNumber'], $notifications[0]['reference'] ?? null);

	}//end testReadsAreBoundedAndTheNotificationNamesTheDraft()

	/**
	 * A non-matching event class is ignored without error.
	 *
	 * @return void
	 */
	public function testNonMatchingEventIsIgnored(): void {
		$saved = [];
		$listener = $this->buildListener(existing: [], saved: $saved, notifications: $notifications);

		$listener->handle(new Event());

		self::assertSame([], $saved);

	}//end testNonMatchingEventIsIgnored()

	/**
	 * Build a FinancialExtractionCompletedEvent (or its in-test stub).
	 *
	 * @param string $docType `receipt` or `supplier-invoice`.
	 * @param array<string,mixed> $fields Extracted fields.
	 * @param array<string,float> $fieldConfidence Per-field confidence.
	 * @param float $overallConfidence Aggregate confidence.
	 * @param string $requestedBy Requesting NC user id.
	 *
	 * @return FinancialExtractionCompletedEvent
	 */
	private function makeEvent(
		string $docType,
		array $fields,
		array $fieldConfidence,
		float $overallConfidence,
		string $requestedBy = 'alice',
	): FinancialExtractionCompletedEvent {
		return new FinancialExtractionCompletedEvent(
			documentUri: 'docudesk://attachments/x/invoice.pdf',
			requestedBy: $requestedBy,
			sourceApp: 'shillinq',
			docType: $docType,
			fields: $fields,
			fieldConfidence: $fieldConfidence,
			overallConfidence: $overallConfidence,
		);

	}//end makeEvent()

	/**
	 * Build a listener over an in-memory ObjectService stub + notification manager mock.
	 *
	 * @param array<int,array<string,mixed>> $existing Pre-existing rows returned by findAll.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,array<string,mixed>>|null $notifications Captured notifications (by reference, out param).
	 *
	 * @return ExtractionCompletedListener
	 */
	private function buildListener(array $existing, array &$saved, ?array &$notifications): ExtractionCompletedListener {
		$stub = $this->arrayStore(existing: $existing, saved: $saved);

		return $this->buildListenerOver(store: $stub, notifications: $notifications);

	}//end buildListener()

	/**
	 * A store that answers findAll() with arrays.
	 *
	 * @param array<int,array<string,mixed>> $existing Existing drafts.
	 * @param array<int,mixed>               $saved    Recorded saves.
	 *
	 * @return object
	 */
	private function arrayStore(array $existing, array &$saved): object {
		return new class($existing, $saved) {

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $existing;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $existing Rows returned by findAll.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $existing, array &$saved) {
				$this->existing = $existing;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'AdministrationMembership') {
					return [];
				}

				$filters = ($params['filters'] ?? []);
				$rows = $this->existing;
				foreach ($filters as $key => $value) {
					$rows = array_values(array_filter($rows, static fn (array $r): bool => (($r[$key] ?? null) === $value)));
				}

				return $rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (($object['id'] ?? '') === '') {
					$object['id'] = 'new-id';
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end arrayStore()

	/**
	 * The listener over the given store, recording notifications.
	 *
	 * @param object                 $store         The object service double.
	 * @param array<int,mixed>|null  $notifications Recorded notifications.
	 *
	 * @return ExtractionCompletedListener
	 */
	private function buildListenerOver(object $store, ?array &$notifications): ExtractionCompletedListener {
		$stub = $store;

		$notifications = [];


		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () : INotification {
				$state = (object)['user' => '', 'subject' => '', 'reference' => ''];

				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use ($notification, $state): INotification {
						$state->user = $user;
						return $notification;
					}
				);
				$notification->method('setObject')->willReturnCallback(
					function (string $type, string $reference) use ($notification, $state): INotification {
						$state->reference = $reference;
						return $notification;
					}
				);
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
					$notifications[] = ['user' => $state->user, 'subject' => $state->subject, 'reference' => $state->reference];
				}
			}
		);

		return new ExtractionCompletedListener(
			container: $container,
			appConfig: $appConfig,
			prefillService: new ExtractionPrefillService(),
			notificationManager: $manager,
			logger: ($this->listenerLogger ?? new NullLogger()),
			administrationContext: new AdministrationContextService(
				container: $container,
				userSession: $this->createMock(IUserSession::class),
				appConfig: $appConfig,
				logger: new NullLogger()
			)
		);

	}//end buildListener()

	/**
	 * Characterisation: the info records for an unknown docType, a new draft
	 * and a refreshed draft, in order.
	 *
	 * @return void
	 */
	public function testLogRecordsPerOutcome(): void {
		$logger = new class extends \Psr\Log\AbstractLogger {

			/**
			 * Captured records.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $records = [];

			/**
			 * Record a log call.
			 *
			 * @param mixed $level Level.
			 * @param \Stringable|string $message Message.
			 * @param array<string,mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};
		$this->listenerLogger = $logger;

		$saved = [];
		$this->buildListener(existing: [], saved: $saved, notifications: $notifications)->handle(
			$this->makeEvent(docType: 'unknown-type', fields: [], fieldConfidence: [], overallConfidence: 0.5)
		);
		$this->buildListener(existing: [], saved: $saved, notifications: $notifications)->handle(
			$this->makeEvent(docType: ' receipt ', fields: ['totalIncl' => 1.0], fieldConfidence: [], overallConfidence: 0.5, requestedBy: '')
		);
		$existing = [['id' => 'draft-1', 'sourceDocumentUri' => 'docudesk://attachments/x/invoice.pdf', 'administrationId' => 'adm-1']];
		$this->buildListener(existing: $existing, saved: $saved, notifications: $notifications)->handle(
			$this->makeEvent(docType: 'supplier-invoice', fields: [], fieldConfidence: [], overallConfidence: 0.9)
		);

		self::assertSame(
			[
				['info', 'ExtractionCompletedListener: unknown docType — skipping', ['docType' => 'unknown-type']],
				[
					'info',
					'ExtractionCompletedListener: extraction draft persisted',
					[
						'schema' => 'Receipt',
						'documentUri' => 'docudesk://attachments/x/invoice.pdf',
						'created' => true,
						'requestedBy' => '',
					],
				],
				[
					'info',
					'ExtractionCompletedListener: extraction draft persisted',
					[
						'schema' => 'SupplierInvoice',
						'documentUri' => 'docudesk://attachments/x/invoice.pdf',
						'created' => false,
						'requestedBy' => 'alice',
					],
				],
			],
			$logger->records
		);
		self::assertSame('default', $saved[0]['object']['administrationId']);
		self::assertSame('adm-1', $saved[1]['object']['administrationId']);
		self::assertCount(0, $notifications);

	}//end testLogRecordsPerOutcome()
}//end class
