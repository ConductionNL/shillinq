<?php

/**
 * The object a pre-save (creating / updating) event is about to write.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the incoming entity of a pre-save event and its payload, shared by
 * the pre-save validation listeners that veto a write.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 */
final class PreSaveWrite {

	/**
	 * The entity a creating event creates, or an updating event's new
	 * version; null for any other event.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function incomingEntity(Event $event): object|null {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		return $entity;

	}//end incomingEntity()

	/**
	 * The entity's payload as an array ([] when it has none).
	 *
	 * @param object $entity The incoming entity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function payload(object $entity): array {
		$data = [];
		if (method_exists($entity, 'getObject') === true) {
			$data = ($entity->getObject() ?? []);
		}

		if (is_array($data) === false) {
			return [];
		}

		return $data;

	}//end payload()
}//end class
