<?php

/**
 * Report Schedule Write Listener
 *
 * A report schedule is run as its owner and its file goes to its recipients,
 * so whoever may change the recipients decides who receives the owner's
 * reports. Only the owner may change a schedule's recipients, status, next run,
 * folder, administration or report type, and every recipient must be one the
 * owner may share the administration's reports with.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Reporting\Schedule\ReportRecipientPolicy;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Keeps a report schedule's delivery in its owner's hands.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 */
class ReportScheduleWriteListener implements IEventListener {

	/**
	 * Fields only the owner may change.
	 *
	 * @var array<int, string>
	 */
	public const OWNER_FIELDS = ['recipients', 'status', 'nextRunAt', 'folderPath', 'administrationId', 'reportType'];

	/**
	 * Refusal for somebody else's schedule.
	 *
	 * @var string
	 */
	public const MESSAGE_NOT_OWNER = 'Only the owner of a report schedule can change who receives it and when it runs.';

	/**
	 * Refusal for a recipient without access, followed by the entries.
	 *
	 * @var string
	 */
	public const MESSAGE_RECIPIENT = 'These recipients have no access to this administration, so they cannot receive its reports: ';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Matches ReportSchedule in shillinq's register.
	 * @param ReportRecipientPolicy  $policy         Who may receive the reports.
	 * @param IUserSession           $userSession    The caller; system saves pass.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ReportRecipientPolicy $policy,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Check a schedule before it is saved.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
	 */
	public function handle(Event $event): void {
		$caller = $this->userSession->getUser()?->getUID();
		if ($caller === null) {
			return;
		}

		$entity = null;
		$before = null;
		$owner  = $caller;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
			$old    = $event->getOldObject();
			$before = $old?->getObject();
			$owner  = (string)($old?->getOwner() ?? $caller);
		}

		if ($entity === null || $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'ReportSchedule') === false) {
			return;
		}

		$error = $this->refusal(after: $entity->getObject(), before: $before, owner: $owner, caller: $caller);
		if ($error !== null) {
			$event->setErrors(['message' => $error, 'field' => 'recipients']);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why the save is refused, or null.
	 *
	 * @param array<string, mixed>      $after  The schedule as it would be saved.
	 * @param array<string, mixed>|null $before The stored schedule, null on create.
	 * @param string                    $owner  The schedule's owner.
	 * @param string                    $caller The user saving.
	 *
	 * @return string|null
	 */
	private function refusal(array $after, ?array $before, string $owner, string $caller): ?string {
		if ($before !== null && $owner !== $caller) {
			foreach (self::OWNER_FIELDS as $field) {
				if (json_encode($before[$field] ?? null) !== json_encode($after[$field] ?? null)) {
					return self::MESSAGE_NOT_OWNER;
				}
			}
		}

		$refused = $this->policy->refused(
			ownerId: $owner,
			administrationId: (string)($after['administrationId'] ?? ''),
			recipients: array_map('strval', (array)($after['recipients'] ?? []))
		);
		if ($refused !== []) {
			return self::MESSAGE_RECIPIENT . implode(', ', $refused);
		}

		return null;
	}//end refusal()
}//end class
