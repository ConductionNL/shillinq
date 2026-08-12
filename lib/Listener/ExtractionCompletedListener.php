<?php

/**
 * Extraction Completed Listener
 *
 * Change receipt-extraction-consume (REQ-RXC-001) — consumes docudesk's
 * cross-app {@see \OCA\DocuDesk\Event\FinancialExtractionCompletedEvent}
 * (canonical wire contract `nl.conduction.docudesk.extraction.completed`,
 * owned by docudesk's financial-document-field-extraction spec) and turns it
 * into an uncommitted, confidence-scored draft: a `SupplierInvoice` for
 * `docType: supplier-invoice`, a `Receipt` for `docType: receipt`.
 *
 * Registered against the docudesk event FQCN via
 * IRegistrationContext::registerEventListener() (mirrors
 * {@see \OCA\Shillinq\Listener\SigningConcludedListener}) — safe even when
 * docudesk is not autoloadable; NC only needs the string key. `handle()`
 * itself is guarded by `class_exists` so the listener is inert when docudesk
 * is absent.
 *
 * Resolution: the listener looks up an existing draft carrying the same
 * `sourceDocumentUri` (+ schema). A match is refreshed in place (the
 * REQ-RXC-005 re-request round trip). No match creates a brand-new draft AND
 * notifies `requestedBy` (REQ-RXC-001 "unmatched documentUri is not
 * dropped").
 *
 * Fail-soft: any error is logged but never rethrown into docudesk's
 * synchronous dispatch.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a docudesk extraction-completed event into a confidence-scored
 * shillinq draft.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
class ExtractionCompletedListener implements IEventListener {
	/**
	 * Notification object type for the "draft created" alert to requestedBy.
	 *
	 * @var string
	 */
	private const NOTIFICATION_OBJECT_TYPE = 'extraction_draft';

	/**
	 * Notification subject identifier.
	 *
	 * @var string
	 */
	private const NOTIFICATION_SUBJECT = 'extraction_draft_created';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService
	 *                                      pulled lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param ExtractionPrefillService $prefillService Field-mapping service.
	 * @param INotificationManager $notificationManager NC notification dispatcher.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly ExtractionPrefillService $prefillService,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a docudesk FinancialExtractionCompletedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function handle(Event $event): void {
		// Guarded by class_exists so the listener is inert when docudesk is
		// not installed (registration is safe even then — see class docblock).
		if (class_exists(\OCA\DocuDesk\Event\FinancialExtractionCompletedEvent::class) === false) {
			return;
		}

		if (($event instanceof \OCA\DocuDesk\Event\FinancialExtractionCompletedEvent) === false) {
			return;
		}

		try {
			$this->apply(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ExtractionCompletedListener: failed to apply extraction-completed event — fail-soft',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Apply one extraction-completed payload to a draft.
	 *
	 * @param \OCA\DocuDesk\Event\FinancialExtractionCompletedEvent $event The event.
	 *
	 * @return void
	 */
	private function apply(\OCA\DocuDesk\Event\FinancialExtractionCompletedEvent $event): void {
		$documentUri = trim($event->getDocumentUri());
		$docType = trim($event->getDocType());
		if ($documentUri === '' || $docType === '') {
			return;
		}

		$schema = $this->prefillService->schemaForDocType(docType: $docType);
		if ($schema === null) {
			$this->logger->info(
				'ExtractionCompletedListener: unknown docType — skipping',
				['docType' => $docType]
			);
			return;
		}

		$existing = $this->findBySourceDocumentUri(schema: $schema, documentUri: $documentUri);
		$isNew = ($existing === null);

		$administrationId = ($existing['administrationId'] ?? null);
		if (is_string($administrationId) === false || $administrationId === '') {
			$administrationId = $this->resolveAdministrationId(userId: $event->getRequestedBy());
		}

		$draft = $this->prefillService->buildDraft(
			docType: $docType,
			documentUri: $documentUri,
			fields: $event->getFields(),
			fieldConfidence: $event->getFieldConfidence(),
			overallConfidence: $event->getOverallConfidence(),
			existingDraft: $existing,
			administrationId: (string)$administrationId
		);

		$saved = $this->saveObject(schema: $schema, object: $draft);

		$this->logger->info(
			'ExtractionCompletedListener: extraction draft persisted',
			[
				'schema' => $schema,
				'documentUri' => $documentUri,
				'created' => $isNew,
				'requestedBy' => $event->getRequestedBy(),
			]
		);

		if ($isNew === true) {
			// REQ-RXC-001 "unmatched documentUri is not dropped" — surface
			// the new draft to whoever requested the extraction.
			$this->notifyRequester(
				requestedBy: $event->getRequestedBy(),
				schema: $schema,
				draft: $saved
			);
		}

	}//end apply()

	/**
	 * Find an existing draft carrying the same sourceDocumentUri.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $documentUri The docudesk documentUri.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findBySourceDocumentUri(string $schema, string $documentUri): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => ['sourceDocumentUri' => $documentUri]]);
		} catch (Throwable $e) {
			$this->logger->info(
				'ExtractionCompletedListener: OR query unavailable — treating as new draft',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findBySourceDocumentUri()

	/**
	 * Resolve an administration for a requesting user via their
	 * AdministrationMembership, falling back to 'default' (mirrors
	 * {@see \OCA\Shillinq\Controller\SupplierInvoiceImportController::resolveAdministrationId()}
	 * for the no-membership case).
	 *
	 * @param string $userId The requesting NC user id.
	 *
	 * @return string
	 */
	private function resolveAdministrationId(string $userId): string {
		if ($userId === '') {
			return 'default';
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('AdministrationMembership')
				->findAll(['filters' => ['userId' => $userId]]);
		} catch (Throwable $e) {
			return 'default';
		}

		if (is_array($rows) === true) {
			foreach ($rows as $row) {
				if (is_array($row) === true) {
					$administrationId = (string)($row['administrationId'] ?? '');
					if ($administrationId !== '') {
						return $administrationId;
					}
				}
			}
		}

		return 'default';
	}//end resolveAdministrationId()

	/**
	 * Notify the requesting user that a new extraction draft is ready for review.
	 *
	 * @param string $requestedBy NC user id.
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $draft The persisted draft.
	 *
	 * @return void
	 */
	private function notifyRequester(string $requestedBy, string $schema, array $draft): void {
		if ($requestedBy === '') {
			return;
		}

		$reference = (string)($draft['invoiceNumber'] ?? ($draft['receiptNumber'] ?? ($draft['id'] ?? '')));

		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($requestedBy)
				->setDateTime(new DateTime())
				->setObject(self::NOTIFICATION_OBJECT_TYPE, $reference)
				->setSubject(
					self::NOTIFICATION_SUBJECT,
					[
						'schema' => $schema,
						'reference' => $reference,
					]
				);
			$this->notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ExtractionCompletedListener: failed to dispatch extraction-draft notification',
				['schema' => $schema, 'requestedBy' => $requestedBy, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end notifyRequester()

	/**
	 * Persist a draft via the real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object payload.
	 *
	 * @return array<string,mixed> The persisted object (or the input on failure).
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			if (is_array($result) === true) {
				return $result;
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'ExtractionCompletedListener: failed to persist extraction draft',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
		}

		return $object;
	}//end saveObject()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
