<?php

/**
 * Minimal ObjectUpdatedEvent stub for unit tests.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 */
class ObjectUpdatedEvent extends Event {
	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity|null $object The updated object (new value).
	 * @param ObjectEntity|null $oldObject The pre-update object (old value).
	 */
	public function __construct(
		private ?ObjectEntity $object = null,
		private ?ObjectEntity $oldObject = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the updated object (new value).
	 *
	 * @return ObjectEntity|null
	 */
	public function getObject(): ?ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Return the pre-update object (old value).
	 *
	 * @return ObjectEntity|null
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
}//end class
