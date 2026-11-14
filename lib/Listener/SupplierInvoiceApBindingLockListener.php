<?php

/**
 * Supplier Invoice AP Binding Lock Listener
 *
 * Once a supplier invoice is handed to accounts payable it carries the uuid
 * of its AP transaction, and the pay guard accepts the invoice as paid when
 * that transaction is paid and carries the invoice's administration, number,
 * supplier and total. Those fields are frozen from then on, so a write cannot
 * point the invoice at another invoice's paid transaction and copy that
 * invoice's details along.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a change to the AP binding of a supplier invoice handed to accounts payable.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 */
class SupplierInvoiceApBindingLockListener implements IEventListener {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'This invoice has been handed to accounts payable. '
		. 'Its AP transaction, administration, number, supplier and total can no longer change.';

	/**
	 * Refusal for a write whose data names another object than the invoice itself.
	 *
	 * @var string
	 */
	public const ID_MESSAGE = 'The id in the data of this invoice is not the id of the invoice.';

	/**
	 * The fields that bind an invoice to its AP transaction.
	 *
	 * @var array<int, string>
	 */
	public const BOUND_FIELDS = ['apTransactionId', 'administrationId', 'invoiceNumber', 'supplierId', 'totalInclVat'];

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Matches SupplierInvoice in shillinq's register.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Refuse an update that changes a bound field once the AP transaction is set.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($new === null || $old === null || $this->schemaResolver->matchesSchema(entity: $new, expectedSlug: 'SupplierInvoice') === false) {
			return;
		}

		$after = ($new->getObject() ?? []);
		$errors = $this->idError(entity: $new, data: $after);
		$changed = $this->changedBoundField(before: ($old->getObject() ?? []), after: $after);
		if ($errors === [] && $changed !== null) {
			$errors = ['message' => self::MESSAGE, 'field' => $changed];
		}

		if ($errors !== []) {
			$event->setErrors($errors);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * The error when the data carries another id than the entity's uuid. The pay
	 * guard identifies the invoice by this id, because it receives only the data,
	 * and refuses a blank one.
	 *
	 * @param ObjectEntity         $entity The invoice as it would be saved.
	 * @param array<string, mixed> $data   Its data.
	 *
	 * @return array<string, string>
	 */
	private function idError(ObjectEntity $entity, array $data): array {
		$id = (string)($data['id'] ?? '');
		if ($id === '' || $id === (string)$entity->getUuid()) {
			return [];
		}

		return ['message' => self::ID_MESSAGE, 'field' => 'id'];
	}//end idError()

	/**
	 * The first bound field the update changes, or null; nothing is bound before the handover.
	 *
	 * @param array<string, mixed> $before The invoice as stored.
	 * @param array<string, mixed> $after  The invoice as it would be saved.
	 *
	 * @return string|null
	 */
	private function changedBoundField(array $before, array $after): ?string {
		if (trim((string)($before['apTransactionId'] ?? '')) === '') {
			return null;
		}

		foreach (self::BOUND_FIELDS as $field) {
			if ((string)json_encode($before[$field] ?? null) !== (string)json_encode($after[$field] ?? null)) {
				return $field;
			}
		}

		return null;
	}//end changedBoundField()
}//end class
