<?php

/**
 * Minimal ObjectDeletedEvent stub for unit tests, shaped as OpenRegister's
 * lib/Event/ObjectDeletedEvent.php (constructor and getObject()).
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Stub for OCA\OpenRegister\Event\ObjectDeletedEvent.
 */
class ObjectDeletedEvent extends Event {

	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity $object The deleted object.
	 */
	public function __construct(
		private ObjectEntity $object,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the deleted object.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
