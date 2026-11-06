<?php

/**
 * Linked Objects Lock Listener
 *
 * A financial document names the objects it is about in `linkedObjects`. A
 * quote or an invoice may change that list while it is a draft. Once it is
 * sent or issued, and from the moment a payment request or a payout is
 * raised, the list is what the debtor or the payee was told, so a change is
 * refused. An item that does not name a register, a schema and an id is
 * refused in every state, because a reference nobody can follow links nothing.
 *
 * One change to a kept list is allowed: an empty list on a payment request may
 * take the request's own `subject`, which is what BackfillLinkedObjects writes.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Util\LinkedObjectReferences;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Keeps a financial document's linked objects once it is issued.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 */
class LinkedObjectsLockListener implements IEventListener {

	/**
	 * The field this listener guards.
	 *
	 * @var string
	 */
	public const FIELD = 'linkedObjects';

	/**
	 * Per document schema: the state field, and the states in which the list may still change.
	 * An empty list of open states means the list is kept from creation.
	 *
	 * @var array<string, array{0: string, 1: array<int, string>}>
	 */
	public const DOCUMENTS = [
		'Quote'          => ['status', ['draft']],
		'ARInvoice'      => ['lifecycleState', ['draft']],
		'PaymentRequest' => ['state', []],
		'InboundPayable' => ['state', []],
	];

	/**
	 * Refusal for a change to a kept list.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The objects this document is about cannot change once it is issued.';

	/**
	 * Refusal for an item that names no register, schema or id.
	 *
	 * @var string
	 */
	public const MALFORMED = LinkedObjectReferences::MALFORMED;

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Matches the document schemas in shillinq's register.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Refuse a malformed list, or a change to a kept one.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		$new = $event->getNewObject();
		if ($old === null || $new === null) {
			return;
		}

		$document = $this->documentOf(entity: $new);
		if ($document === null) {
			return;
		}

		$before = $old->getObject();
		$after  = $new->getObject();
		$list   = LinkedObjectReferences::references(list: ($after[self::FIELD] ?? []));
		if ($list === null) {
			$event->setErrors(['message' => self::MALFORMED, 'field' => self::FIELD]);
			$event->stopPropagation();
			return;
		}

		[$stateField, $openStates] = self::DOCUMENTS[$document];
		if (in_array((string)($before[$stateField] ?? ''), $openStates, true) === true) {
			return;
		}

		$kept = LinkedObjectReferences::references(list: ($before[self::FIELD] ?? [])) ?? [];
		if ($kept === $list) {
			return;
		}

		if ($kept === [] && $document === 'PaymentRequest' && $list === LinkedObjectReferences::references(list: [($before['subject'] ?? [])])) {
			return;
		}

		$event->setErrors(['message' => self::MESSAGE, 'field' => self::FIELD]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Which guarded document schema an entity is, if any.
	 *
	 * @param object $entity The incoming object.
	 *
	 * @return string|null The schema slug, or null when it is not a financial document.
	 */
	private function documentOf(object $entity): ?string {
		foreach (array_keys(self::DOCUMENTS) as $slug) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $slug) === true) {
				return $slug;
			}
		}

		return null;
	}//end documentOf()
}//end class
