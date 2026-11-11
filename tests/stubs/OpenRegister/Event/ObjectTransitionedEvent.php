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
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;

/**
 * Stub for OCA\OpenRegister\Event\ObjectTransitionedEvent.
 */
class ObjectTransitionedEvent extends Event {
	private string $action;

	private string $from;

	private string $to;

	private ?string $userId;

	private string $register;

	private string $schema;

	private bool $automatic;

	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity $object Object after the transition.
	 * @param TransitionMove $move The action applied and the states it moved between.
	 * @param RegisterSchemaRef $scope Register and schema slugs.
	 * @param TransitionActor|null $actor Who made the move; null for a system move.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
		TransitionMove $move,
		RegisterSchemaRef $scope,
		?TransitionActor $actor = null,
	) {
		parent::__construct();
		$actor = $actor ?? new TransitionActor();
		$this->action = $move->action;
		$this->from = $move->from;
		$this->to = $move->to;
		$this->userId = $actor->userId;
		$this->register = (string) $scope->register;
		$this->schema = (string) $scope->schema;
		$this->automatic = $actor->automatic;
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

	/**
	 * Whether the transition ran without a user.
	 *
	 * @return bool
	 */
	public function isAutomatic(): bool {
		return $this->automatic;
	}//end isAutomatic()
}//end class
