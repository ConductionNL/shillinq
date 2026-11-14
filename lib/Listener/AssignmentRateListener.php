<?php

/**
 * Assignment Rate Listener
 *
 * Refreshes the rate a project assignment shows when the assignment is
 * created, when its person, project, rate card, billing role, period or
 * administration changes, and when a version of a rate card becomes active
 * (sales-billable-rate-per-hour REQ-BRPH-004).
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
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\Billing\AssignmentRateRefresher;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps an assignment's shown rate current.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 */
class AssignmentRateListener implements IEventListener {
	/**
	 * The assignment fields that decide its rate. A write of the rate alone
	 * changes none of them, so the listener's own write ends here.
	 *
	 * @var array<int, string>
	 */
	public const RATE_FIELDS = ['personId', 'projectId', 'rateCardId', 'billingRoleId', 'startDate', 'endDate', 'administrationId'];

	/**
	 * The rate card version schema.
	 *
	 * @var string
	 */
	private const VERSION_SCHEMA = 'RateCardVersion';

	/**
	 * Constructor.
	 *
	 * @param AssignmentRateRefresher         $refresher      Writes the assignment's current rate.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves an entity's schema slug.
	 * @param LoggerInterface                 $logger         Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AssignmentRateRefresher $refresher,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a created or updated object.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectCreatedEvent) {
				$this->changed(entity: $event->getObject(), old: null);
				return;
			}

			if ($event instanceof ObjectUpdatedEvent) {
				$old = $event->getOldObject();
				$this->changed(entity: $event->getNewObject(), old: ($old?->getObject() ?? []));
			}
		} catch (Throwable $e) {
			$this->logger->warning('AssignmentRateListener: assignment rate not refreshed', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Refresh what a created (old null) or updated object affects.
	 *
	 * @param object|null               $entity The object.
	 * @param array<string, mixed>|null $old    Its previous data; null when it was created.
	 *
	 * @return void
	 */
	private function changed(?object $entity, ?array $old): void {
		if ($entity === null) {
			return;
		}

		$slug = $this->schemaResolver->schemaSlug(entity: $entity);
		$new = (array)($entity->getObject() ?? []);
		if ($slug === AssignmentRateRefresher::ASSIGNMENT_SCHEMA) {
			if ($old === null || $this->rateFieldsChanged(new: $new, old: $old) === true) {
				$this->refreshAssignment(assignment: ['id' => ObjectIdentifier::resolve(saved: $entity)] + $new);
			}

			return;
		}

		if ($slug === self::VERSION_SCHEMA
			&& (string)($new['status'] ?? '') === 'active'
			&& ($old === null || (string)($old['status'] ?? '') !== 'active')
		) {
			$this->refresher->refreshForVersion(version: $new);
		}
	}//end changed()

	/**
	 * Whether an update touched a field the rate depends on.
	 *
	 * @param array<string, mixed> $new The new data.
	 * @param array<string, mixed> $old The previous data; empty when the event carried none.
	 *
	 * @return bool True when the rate may have changed.
	 */
	private function rateFieldsChanged(array $new, array $old): bool {
		if ($old === []) {
			return true;
		}

		foreach (self::RATE_FIELDS as $field) {
			if ((string)($new[$field] ?? '') !== (string)($old[$field] ?? '')) {
				return true;
			}
		}

		return false;
	}//end rateFieldsChanged()

	/**
	 * Refresh one assignment and log when it has no rate.
	 *
	 * @param array<string, mixed> $assignment The assignment with its id.
	 *
	 * @return void
	 */
	private function refreshAssignment(array $assignment): void {
		$answer = $this->refresher->refresh(assignment: $assignment);
		if (($answer['status'] ?? '') !== 'rated') {
			$this->logger->info(
				'AssignmentRateListener: assignment has no rate today',
				['assignment' => (string)($assignment['id'] ?? ''), 'reason' => (string)($answer['reason'] ?? '')]
			);
		}
	}//end refreshAssignment()
}//end class
