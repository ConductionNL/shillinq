<?php

/**
 * Minimal ObjectTransitionedEvent stub for unit tests. Mirrors the real
 * OCA\OpenRegister\Event\ObjectTransitionedEvent public surface.
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
 * Stub for OCA\OpenRegister\Event\ObjectTransitionedEvent.
 */
class ObjectTransitionedEvent extends Event {
	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity $object The transitioned object.
	 * @param string $action The transition action id.
	 * @param string $from The source lifecycle state.
	 * @param string $to The target lifecycle state.
	 * @param string|null $userId The acting user id.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 */
	public function __construct(
		private ObjectEntity $object,
		private string $action,
		private string $from,
		private string $to,
		private ?string $userId,
		private string $register,
		private string $schema,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the transitioned object.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Return the transition action id.
	 *
	 * @return string
	 */
	public function getAction(): string {
		return $this->action;
	}//end getAction()

	/**
	 * Return the source lifecycle state.
	 *
	 * @return string
	 */
	public function getFrom(): string {
		return $this->from;
	}//end getFrom()

	/**
	 * Return the target lifecycle state.
	 *
	 * @return string
	 */
	public function getTo(): string {
		return $this->to;
	}//end getTo()

	/**
	 * Return the acting user id.
	 *
	 * @return string|null
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Return the register slug.
	 *
	 * @return string
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * Return the schema slug.
	 *
	 * @return string
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()
}//end class
