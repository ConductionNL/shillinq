<?php

/**
 * Minimal ObjectCreatingEvent stub for unit tests.
 *
 * Reproduces the pre-save VETO contract MagicMapper consumes: a listener may
 * call setErrors() + stopPropagation(), and MagicMapper then throws a
 * HookStoppedException which OpenRegister's ObjectsController renders as
 * HTTP 422.
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
 * Stub for OCA\OpenRegister\Event\ObjectCreatingEvent.
 */
class ObjectCreatingEvent extends Event {

	/**
	 * Errors set by a rejecting hook.
	 *
	 * @var array<string,mixed>
	 */
	private array $errors = [];

	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity|null $object The object being created.
	 */
	public function __construct(
		private ?ObjectEntity $object = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the object being created.
	 *
	 * @return ObjectEntity|null
	 */
	public function getObject(): ?ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Record the rejection reason.
	 *
	 * @param array<string,mixed> $errors The rejection payload.
	 *
	 * @return void
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;

	}//end setErrors()

	/**
	 * Return the rejection reason.
	 *
	 * @return array<string,mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
