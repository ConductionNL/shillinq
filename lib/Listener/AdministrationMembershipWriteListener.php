<?php

/**
 * Administration Membership Write Listener
 *
 * Refuses a create or update of an administration-scoped object whose
 * `administrationId` the signed-in user holds no valid membership for.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save membership check on the administration an object is written into.
 *
 * A lifecycle transition saves through the same path, so every ImportBatch
 * step (parse, mapping, validate, dry-run, post, reverse) is refused for a
 * user outside the batch's administration before its action runs. A write
 * without a session user (a background step) is not judged here.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 */
class AdministrationMembershipWriteListener implements IEventListener {

	/**
	 * The schemas whose writes are checked.
	 *
	 * @var array<int,string>
	 */
	public const SCHEMAS = ['ImportBatch', 'ImportMapping'];

	/**
	 * The refusal shown to the writer.
	 *
	 * @var string
	 */
	public const DENY_MESSAGE = 'You are not a member of this administration.';

	/**
	 * Constructor.
	 *
	 * @param AdministrationContextService $administrationContext The caller's memberships.
	 * @param ListenerSchemaResolver $schemaResolver Schema matching for the written entity.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AdministrationContextService $administrationContext,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when the caller is no member of its administration.
	 *
	 * @param Event $event An ObjectCreatingEvent or ObjectUpdatingEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->isChecked(entity: $entity) === false) {
			return;
		}

		$userId = $this->administrationContext->currentUserId();
		if ($userId === null) {
			return;
		}

		$data = ($entity->getObject() ?? []);
		$administrationId = trim((string)($data['administrationId'] ?? ''));
		if ($administrationId === '') {
			return;
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === true) {
			return;
		}

		$this->logger->warning(
			'Shillinq: refused a write into an administration the user is no member of',
			['userId' => $userId, 'administrationId' => $administrationId]
		);

		$event->setErrors(['message' => self::DENY_MESSAGE, 'field' => 'administrationId']);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the entity belongs to one of the checked schemas.
	 *
	 * @param object $entity The written entity.
	 *
	 * @return bool
	 */
	private function isChecked(object $entity): bool {
		foreach (self::SCHEMAS as $schema) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $schema) === true) {
				return true;
			}
		}

		return false;
	}//end isChecked()
}//end class
