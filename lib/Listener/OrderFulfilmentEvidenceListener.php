<?php

/**
 * OrderFulfilment bewijsstuk write-path guard
 *
 * Enforces REQ-004 — an OrderFulfilment may only carry `status: completed`
 * when at least one bewijsstuk (proof of delivery) is attached — on the PLAIN
 * WRITE path, i.e. `POST`/`PUT` against
 * `/apps/openregister/api/objects/{register}/OrderFulfilment`.
 *
 * Why this listener has to exist
 * ------------------------------
 * The schema declares the gate declaratively, as a `requires:` clause on the
 * `voltooien` lifecycle transition (`in-progress` → `completed`) pointing at
 * {@see \OCA\Shillinq\Lifecycle\OrderFulfilmentGuard::canVoltooien}. That
 * clause is only ever consulted by OpenRegister's TransitionController, on the
 * explicit transition endpoint. It says nothing about — and cannot see — a
 * write that sets `status` directly:
 *
 *   - On CREATE, OpenRegister's LifecycleInitialStateListener only seeds the
 *     declared `initialState` when the caller left the lifecycle field empty
 *     ("Caller already set a value — leave it alone"). A client that POSTs
 *     `{"status": "completed", "supportingDocuments": []}` is therefore persisted
 *     exactly as sent — born in the terminal state, with the transition and
 *     its guard never involved.
 *   - On UPDATE, a PUT that rewrites `status` to `completed` bypasses the
 *     transition endpoint in the same way.
 *
 * Measured on a live Nextcloud 32 + OpenRegister instance: that POST returned
 * `201 Created` with `status: completed` and zero bewijsstukken. The gate REQ-004
 * describes did not exist on the path clients actually use.
 *
 * This listener closes that path using OpenRegister's supported pre-save veto:
 * `ObjectCreatingEvent` / `ObjectUpdatingEvent` are stoppable, and MagicMapper
 * turns a stopped event into a `HookStoppedException`, which ObjectsController
 * renders as HTTP 422 with the guard's message.
 *
 * The declarative transition guard is NOT replaced — it stays the contract for
 * the transition endpoint, and this change also registers its DI tag so it can
 * finally resolve. This listener is the defence-in-depth that makes the rule
 * hold however the write arrives.
 *
 * `bewijsstuk` is a standardised Dutch domain term (proof of delivery in the
 * Dutch procurement/BBV chain) and is deliberately kept, not translated.
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
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Write-path enforcement of the REQ-004 bewijsstuk-required completion gate.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 */
class OrderFulfilmentEvidenceListener implements IEventListener {

	/**
	 * Schema slug this guard is scoped to.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'OrderFulfilment';

	/**
	 * The lifecycle field on the OrderFulfilment schema.
	 *
	 * @var string
	 */
	private const STATUS_FIELD = 'status';

	/**
	 * The terminal state the bewijsstuk gate protects.
	 *
	 * @var string
	 */
	private const COMPLETED_STATE = 'completed';

	/**
	 * User-facing denial message (REQ-004).
	 *
	 * Public because the declarative `voltooien` transition guard registered in
	 * Application::register() must deny with the SAME wording — the two paths
	 * enforce one rule and a caller should not be able to tell them apart.
	 *
	 * @var string
	 */
	public const DENY_MESSAGE = 'An OrderFulfilment can only be completed when at least one bewijsstuk '
		. '(proof of delivery) with a documentId is attached (REQ-004).';

	/**
	 * Constructor.
	 *
	 * @param OrderFulfilmentGuard $guard The REQ-004 precondition guard (single source of truth).
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema to its slug.
	 * @param LoggerInterface $logger Logger for guard diagnostics.
	 */
	public function __construct(
		private readonly OrderFulfilmentGuard $guard,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto a create/update that would persist `status: completed` without a bewijsstuk.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null) {
			return;
		}

		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return;
		}

		$data = [];
		if (method_exists($entity, 'getObject') === true) {
			$data = ($entity->getObject() ?? []);
		}

		if (is_array($data) === false) {
			$data = [];
		}

		// Only the completed state is gated — an in-progress or cancelled
		// delivery may be written freely, with or without bewijsstukken.
		$status = strtolower(trim((string)($data[self::STATUS_FIELD] ?? '')));
		if ($status !== self::COMPLETED_STATE) {
			return;
		}

		if ($this->guard->canVoltooien(assignment: $data) === true) {
			return;
		}

		$this->logger->warning(
			'Shillinq: refused an OrderFulfilment write into `completed` without a bewijsstuk (REQ-004)',
			[
				'commitmentId' => ($data['commitmentId'] ?? 'unknown'),
				'milestoneId' => ($data['milestoneId'] ?? 'unknown'),
			]
		);

		$event->setErrors(
			[
				'message' => self::DENY_MESSAGE,
				'field' => 'supportingDocuments',
				'requirement' => 'REQ-004',
				'attemptedState' => self::COMPLETED_STATE,
			]
		);
		$event->stopPropagation();

	}//end handle()
}//end class
