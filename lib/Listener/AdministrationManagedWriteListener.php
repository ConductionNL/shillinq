<?php

/**
 * Administration Managed Write Listener
 *
 * Refuses a create or update of per-administration configuration by anyone
 * who does not manage that administration.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save check that the writer manages the administration the object configures.
 *
 * On an update both the stored and the new administration are checked, so a
 * policy cannot be moved out of, or into, an administration the writer does
 * not manage. A write without a session user (repair steps, seeding) is not
 * judged here.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */
class AdministrationManagedWriteListener implements IEventListener {

	/**
	 * The schemas whose writes are checked.
	 *
	 * @var array<int,string>
	 */
	public const SCHEMAS = ['StandardsPolicy'];

	/**
	 * The refusal shown to the writer.
	 *
	 * @var string
	 */
	public const DENY_MESSAGE = 'Only an owner of this administration can change its configuration.';

	/**
	 * Constructor.
	 *
	 * @param AdministrationContextService $administrationContext The session user.
	 * @param AdministrationManagementAccess $managementAccess Owner or admin check.
	 * @param ListenerSchemaResolver $schemaResolver Schema matching for the written entity.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AdministrationContextService $administrationContext,
		private readonly AdministrationManagementAccess $managementAccess,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when the caller does not manage its administration.
	 *
	 * @param Event $event An ObjectCreatingEvent or ObjectUpdatingEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md
	 */
	public function handle(Event $event): void {
		$entities = [];
		if ($event instanceof ObjectCreatingEvent === true) {
			$entities[] = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entities[] = $event->getNewObject();
			$entities[] = $event->getOldObject();
		}

		$entities = array_values(array_filter($entities, static fn ($entity): bool => $entity !== null));
		if ($entities === [] || $this->isChecked(entity: $entities[0]) === false) {
			return;
		}

		if ($this->administrationContext->currentUserId() === null) {
			return;
		}

		foreach ($entities as $entity) {
			$administrationId = trim((string)(($entity->getObject() ?? [])['administrationId'] ?? ''));
			if ($this->managementAccess->canManage(administrationId: $administrationId) === true) {
				continue;
			}

			$this->logger->warning(
				'Shillinq: refused a configuration write by a user who does not manage the administration',
				['administrationId' => $administrationId]
			);
			$event->setErrors(['message' => self::DENY_MESSAGE, 'field' => 'administrationId']);
			$event->stopPropagation();
			return;
		}
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
