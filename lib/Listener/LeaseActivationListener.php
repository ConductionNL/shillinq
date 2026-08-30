<?php

/**
 * Lease Activation Listener
 *
 * Change revive-lease-capabilities (shillinq#446): the missing IFRS-16 lease
 * schedule trigger. {@see \OCA\Shillinq\Service\LeasePaymentScheduleService::generateSchedule()}
 * fully materialises the per-period amortization schedule (interest /
 * principal split + straight-line RoU depreciation) for an activated lease
 * and passes its unit tests, but nothing ever called it: only the read-only
 * `buildSchedule` preview ran, so the `LeasePaymentSchedule` rows were never
 * persisted and the RoU asset / lease liability stayed frozen at their
 * opening values.
 *
 * The "obvious" declarative wiring cannot work (design D1): OpenRegister has
 * no lifecycle *action* executor, and `LeaseContract`'s list-form
 * transitions block `TransitionEngine`, so `ObjectTransitionedEvent` is never
 * dispatched for a lease. The trigger that genuinely executes is
 * `ObjectUpdatedEvent` / `ObjectCreatedEvent`, dispatched by
 * `MagicMapper` on every save through the public mutation surface (the path
 * shillinq's generic lease CRUD uses to set `status`). This listener reacts
 * to the edge into `active` and hands the lease to the schedule service.
 *
 * Fail-soft, mirroring {@see StockMoveTransitionedListener}: a downstream
 * failure is logged but never bubbled into the triggering save — a schedule
 * persistence hiccup must not roll back the operator's lease activation.
 * Edge-gated (design D2): fires only on the transition into `active`, so it
 * never clobbers already-posted schedule rows on a later save.
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
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from the LeaseContract activation edge to the schedule generator.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 */
class LeaseActivationListener implements IEventListener {

	/**
	 * The lifecycle state an activated lease lands in.
	 *
	 * @var string
	 */
	private const STATE_ACTIVE = 'active';

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param LeasePaymentScheduleService $scheduleService The amortization schedule generator.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly LeasePaymentScheduleService $scheduleService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent / ObjectUpdatedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($entity === null) {
				return;
			}

			if ($this->isLeaseContractSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
				return;
			}

			$new = $entity->getObject();
			if (is_array($new) === false) {
				return;
			}

			if ($this->isActivationEdge(event: $event, new: $new) === false) {
				return;
			}

			$leaseContractId = $this->resolveLeaseId(lease: $new);
			$administrationId = trim((string)($new['administrationId'] ?? ''));
			if ($leaseContractId === '' || $administrationId === '') {
				$this->logger->warning(
					'LeaseActivationListener: activated lease carries no id/administrationId; schedule generation skipped',
					['leaseNumber' => ($new['leaseNumber'] ?? null)]
				);
				return;
			}

			// The schedule service re-guards classification (IFRS16-capitalised
			// only) and the administration scope (ADR-005), so the listener
			// stays thin.
			$this->scheduleService->generateSchedule(
				leaseContractId: $leaseContractId,
				administrationId: $administrationId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'LeaseActivationListener: failed to generate the lease amortization schedule',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Whether this save moved the lease into the `active` state.
	 *
	 * For a create, the edge is simply "created active". For an update, the
	 * edge requires the previous state to be anything other than `active` and
	 * the new state to be `active` — so a later save of an already-active
	 * lease (which would otherwise clobber posted schedule rows) is ignored.
	 *
	 * @param Event $event The inbound event.
	 * @param array<string,mixed> $new The new object body.
	 *
	 * @return bool True when this save is the activation edge.
	 */
	private function isActivationEdge(Event $event, array $new): bool {
		$newStatus = (string)($new['status'] ?? '');
		if ($newStatus !== self::STATE_ACTIVE) {
			return false;
		}

		if ($event instanceof ObjectCreatedEvent) {
			return true;
		}

		// ObjectUpdatedEvent: require a real edge from a non-active prior
		// state. A missing old object (defensive) is treated as an edge.
		$old = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$oldEntity = $event->getOldObject();
			if ($oldEntity !== null) {
				$oldData = $oldEntity->getObject();
				if (is_array($oldData) === true) {
					$old = (string)($oldData['status'] ?? '');
				}
			}
		}

		return $old !== self::STATE_ACTIVE;
	}//end isActivationEdge()

	/**
	 * Resolve the lease id to hand to the schedule generator.
	 *
	 * Prefers @self.slug, then @self.id, then the top-level id — matching how
	 * LeasePaymentScheduleService::fetchLease() locates the contract.
	 *
	 * @param array<string,mixed> $lease The LeaseContract body.
	 *
	 * @return string The resolved lease id (empty when unresolvable).
	 */
	private function resolveLeaseId(array $lease): string {
		$self = ($lease['@self'] ?? null);
		if (is_array($self) === true) {
			$slug = trim((string)($self['slug'] ?? ''));
			if ($slug !== '') {
				return $slug;
			}

			$selfId = trim((string)($self['id'] ?? ''));
			if ($selfId !== '') {
				return $selfId;
			}
		}

		return trim((string)($lease['id'] ?? ''));
	}//end resolveLeaseId()

	/**
	 * Schema-name match for LeaseContract (plain slug or namespaced path).
	 *
	 * @param string $schema Schema identifier from the entity.
	 *
	 * @return bool True when the schema identifies a LeaseContract.
	 */
	private function isLeaseContractSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'leasecontract' || str_ends_with($normalised, '/leasecontract'));
	}//end isLeaseContractSchema()
}//end class
