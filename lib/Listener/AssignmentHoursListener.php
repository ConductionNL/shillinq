<?php

/**
 * Assignment Hours Listener
 *
 * Keeps an assignment's logged hours current within seconds of a booking
 * (people-hours-budget REQ-PHB-001): an hour record that is created, changed
 * or deleted re-sums the assignment it names, and when a change moves the
 * hour to another assignment both are re-summed. A changed estimate on the
 * assignment clears the warned flags and re-sums it (REQ-PHB-002).
 *
 * A failure is logged and never blocks the booking.
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
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Project\AssignmentHours;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-sums an assignment's hours when an hour is booked, changed or removed.
 *
 * @template-implements IEventListener<Event>
 */
class AssignmentHoursListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param AssignmentHours        $hours          Sums and writes the hours budget.
	 * @param ListenerSchemaResolver $schemaResolver Resolves an entity's schema slug.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly AssignmentHours $hours,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a created, updated or deleted hour record or assignment.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectCreatedEvent) {
				$this->onHour(entity: $event->getObject(), ignoreHourId: null);
				return;
			}

			if ($event instanceof ObjectDeletedEvent) {
				$entity = $event->getObject();
				$this->onHour(entity: $entity, ignoreHourId: ObjectIdentifier::resolve(saved: $entity));
				return;
			}

			if ($event instanceof ObjectUpdatedEvent) {
				$this->onUpdated(event: $event);
			}
		} catch (Throwable $e) {
			$this->logger->warning('AssignmentHoursListener: hours budget not updated', ['exception' => $e->getMessage()]);
		}

	}//end handle()

	/**
	 * Re-sum the assignment a created or deleted hour names.
	 *
	 * @param ObjectEntity|null $entity       The hour record.
	 * @param string|null       $ignoreHourId The hour being deleted, left out of the sum.
	 *
	 * @return void
	 */
	private function onHour(?ObjectEntity $entity, ?string $ignoreHourId): void {
		if ($entity === null || $this->schemaResolver->schemaSlug(entity: $entity) !== AssignmentHours::HOUR_SCHEMA) {
			return;
		}

		$assignmentId = (string)(($entity->getObject() ?? [])['projectAssignmentId'] ?? '');
		if ($assignmentId !== '') {
			$this->hours->recalculate(assignmentId: $assignmentId, ignoreHourId: $ignoreHourId);
		}

	}//end onHour()

	/**
	 * Re-sum after an hour changed, or re-arm after an estimate changed.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return void
	 */
	private function onUpdated(ObjectUpdatedEvent $event): void {
		$entity = $event->getNewObject();
		$slug = $this->schemaResolver->schemaSlug(entity: $entity);
		$new = ($entity->getObject() ?? []);
		$oldEntity = $event->getOldObject();
		$old = ($oldEntity?->getObject() ?? []);

		if ($slug === AssignmentHours::ASSIGNMENT_SCHEMA) {
			// Without the old version there is no telling whether the estimate
			// changed; the listener's own writes always carry it.
			if ($oldEntity !== null
				&& (string)($old['estimatedHours'] ?? '') !== (string)($new['estimatedHours'] ?? '')
			) {
				$this->hours->recalculate(assignmentId: ObjectIdentifier::resolve(saved: $entity), rearm: true);
			}

			return;
		}

		if ($slug !== AssignmentHours::HOUR_SCHEMA) {
			return;
		}

		$assignments = array_unique(
			array_filter(
				[
					(string)($new['projectAssignmentId'] ?? ''),
					(string)($old['projectAssignmentId'] ?? ''),
				],
				static fn (string $id): bool => $id !== ''
			)
		);
		foreach ($assignments as $assignmentId) {
			$this->hours->recalculate(assignmentId: $assignmentId);
		}

	}//end onUpdated()
}//end class
