<?php

/**
 * Minimal ObjectUpdatingEvent stub for unit tests.
 *
 * Same pre-save VETO contract as ObjectCreatingEvent, for the update path.
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
 * Stub for OCA\OpenRegister\Event\ObjectUpdatingEvent.
 */
class ObjectUpdatingEvent extends Event {

	/**
	 * Errors set by a rejecting hook.
	 *
	 * @var array<string,mixed>
	 */
	private array $errors = [];

	/**
	 * Fields a hook adds to the object before it is persisted.
	 *
	 * @var array<string,mixed>
	 */
	private array $modifiedData = [];

	/**
	 * Construct the event.
	 *
	 * @param ObjectEntity|null $newObject The object as it will be persisted.
	 * @param ObjectEntity|null $oldObject The object as it is stored today.
	 */
	public function __construct(
		private ?ObjectEntity $newObject = null,
		private ?ObjectEntity $oldObject = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Return the object as it will be persisted.
	 *
	 * @return ObjectEntity|null
	 */
	public function getNewObject(): ?ObjectEntity {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * Return the object as it is stored today.
	 *
	 * @return ObjectEntity|null
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()

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

	/**
	 * Record fields a hook adds to the object before it is persisted.
	 *
	 * @param array<string,mixed> $data The fields to merge.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;

	}//end setModifiedData()

	/**
	 * Return the fields a hook adds to the object.
	 *
	 * @return array<string,mixed>
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end class
