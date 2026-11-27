<?php

/**
 * Shillinq Chart Template Version Listener
 *
 * Queues the follower sync when a template is saved with a higher version.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\BackgroundJob\ChartTemplateSyncJob;
use OCA\Shillinq\Kernel\Contracts\ChartTemplateServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A template update whose version rose queues one sync of its followers.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-25-ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
 */
class ChartTemplateVersionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList               $jobList        The queue.
	 * @param ListenerSchemaResolverInterface $schemaResolver Which schema an event's object is.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Queue the sync when an AdministrationTemplate's version rose.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: ChartTemplateServiceInterface::TEMPLATE_SCHEMA) === false) {
			return;
		}

		$new = (array)($entity->getObject() ?? []);
		$old = (array)($event->getOldObject()?->getObject() ?? []);
		if ((int)($new['version'] ?? 1) <= (int)($old['version'] ?? 1)) {
			return;
		}

		$templateId = (string)($entity->getUuid() ?? '');
		if ($templateId === '') {
			return;
		}

		$this->jobList->add(ChartTemplateSyncJob::class, ['templateId' => $templateId]);
	}//end handle()
}//end class
