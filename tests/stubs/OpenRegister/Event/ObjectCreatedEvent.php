<?php

/**
 * Minimal ObjectCreatedEvent stub for unit tests.
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
 * Stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 */
class ObjectCreatedEvent extends Event {
	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity|null $object The created object.
	 */
	public function __construct(
		private ?ObjectEntity $object = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the created object.
	 *
	 * @return ObjectEntity|null
	 */
	public function getObject(): ?ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
