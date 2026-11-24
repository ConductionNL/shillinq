<?php

/**
 * Invoice Number Lock Listener
 *
 * An assigned invoice number cannot be changed (REQ-SIIC-002). On every AR
 * invoice update this refuses a change of `invoiceNumber` once the stored
 * invoice has left the states before issue (draft, awaiting approval,
 * approved). Before issue a number is only provisional; the issue itself,
 * which leaves draft or approved, is where the series number is written.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a change of an assigned AR invoice number.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.3
 */
class InvoiceNumberLockListener implements IEventListener {

	/**
	 * The refusal.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The invoice number was assigned when the invoice was issued and cannot be changed.';

	/**
	 * The states before issue, in which a number is still provisional.
	 *
	 * @var array<int, string>
	 */
	public const UNNUMBERED_STATES = ['draft', 'awaiting-approval', 'approved'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells an AR invoice from other objects.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Refuse the update when it changes an assigned number.
	 *
	 * @param Event $event The update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.3
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($new === null || $old === null || $this->schemaResolver->matchesSchema(entity: $new, expectedSlug: 'ARInvoice') === false) {
			return;
		}

		$before = ($old->getObject() ?? []);
		$after = ($new->getObject() ?? []);
		if ($this->changesAssignedNumber(before: $before, after: $after) === true) {
			$event->setErrors(['message' => self::MESSAGE, 'field' => 'invoiceNumber']);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Whether the update changes a number that is already assigned.
	 *
	 * @param array<string, mixed> $before The stored invoice.
	 * @param array<string, mixed> $after  The invoice as it would be saved.
	 *
	 * @return bool
	 */
	private function changesAssignedNumber(array $before, array $after): bool {
		$old = trim((string)($before['invoiceNumber'] ?? ''));
		$new = trim((string)($after['invoiceNumber'] ?? ''));
		if ($old === $new) {
			return false;
		}

		return in_array((string)($before['lifecycleState'] ?? ''), self::UNNUMBERED_STATES, true) === false;
	}//end changesAssignedNumber()
}//end class
