<?php

/**
 * Supplier Invoice Warning Listener
 *
 * A typed or imported SupplierInvoice gets its duplicate and IBAN warnings
 * when it is saved, so the same rule applies however the invoice arrived
 * (purchasing-supplier-invoice-intake REQ-PSII-003, REQ-PSII-004). The
 * write-back is a patch of the two warning fields, made only when they
 * change: that is the re-entry guard, because the patch fires this listener
 * again and then finds nothing to change.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps duplicateOfId and ibanMismatch current on every SupplierInvoice save.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */
class SupplierInvoiceWarningListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SupplierInvoiceChecks  $checks         The shared checks.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema slug.
	 * @param ObjectServiceInterface $objectService  Writes the warning fields back.
	 * @param SettingsService        $settings       The register slug.
	 * @param LoggerInterface        $logger         Fail-soft log.
	 */
	public function __construct(
		private readonly SupplierInvoiceChecks $checks,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Recompute the warnings of a saved supplier invoice.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($entity === null || $this->schemaResolver->schemaSlug(entity: $entity) !== 'SupplierInvoice') {
			return;
		}

		try {
			$invoice = $entity->getObject();
			$id = (string)($invoice['id'] ?? ($entity->getUuid() ?? ''));
			$invoice['id'] = $id;
			$warnings = $this->checks->warnings(invoice: $invoice);
			$unchanged = (string)($invoice['duplicateOfId'] ?? '') === $warnings['duplicateOfId']
				&& (string)($invoice['ibanMismatch'] ?? '') === $warnings['ibanMismatch'];
			if ($unchanged === true) {
				return;
			}

			$this->objectService->patchObject(
				objectId: $id,
				data: $warnings,
				register: $this->settings->getRegisterSlug(),
				schema: 'SupplierInvoice'
			);
		} catch (Throwable $e) {
			$this->logger->warning('SupplierInvoiceWarningListener: warnings not updated', ['exception' => $e->getMessage()]);
		}//end try

	}//end handle()
}//end class
