<?php

/**
 * Stored record
 *
 * The id and the flat-array form of an OpenRegister record as the object
 * service hands it back: a plain array, or an entity read through getUuid(),
 * jsonSerialize(), getObject() and getId(). Shared by the timeline retry
 * queue, its retry job and the dead-letter controller.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Reads an OpenRegister record's id and array form.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
final class StoredRecord {
	/**
	 * The string id of a saveObject() return value (entity or array), or null.
	 *
	 * @param mixed $result saveObject() return value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	public function idOf(mixed $result): ?string {
		if (is_array($result) === true) {
			$id = (string)($result['id'] ?? '');
			if ($id !== '') {
				return $id;
			}

			return null;
		}

		if (is_object($result) === true) {
			return $this->extractObjectId(result: $result);
		}

		return null;
	}//end idOf()

	/**
	 * Extract a string id from an object saveObject() return value.
	 *
	 * Tries getUuid(), then the serialised payload, then getId().
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function extractObjectId(object $result): string|null {
		$id = $this->uuidIdOf(result: $result);
		if ($id === null) {
			$id = $this->payloadIdOf(result: $result);
		}

		if ($id === null) {
			$id = $this->getIdOf(result: $result);
		}

		return $id;
	}//end extractObjectId()

	/**
	 * Non-empty getUuid() value of an object.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function uuidIdOf(object $result): string|null {
		if (method_exists($result, 'getUuid') === true) {
			$uuid = (string)$result->getUuid();
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end uuidIdOf()

	/**
	 * Non-empty id from an object's jsonSerialize() payload.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function payloadIdOf(object $result): string|null {
		if (method_exists($result, 'jsonSerialize') === true) {
			$payload = $result->jsonSerialize();
			if (is_array($payload) === true) {
				$id = (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
				if ($id !== '') {
					return $id;
				}
			}
		}

		return null;
	}//end payloadIdOf()

	/**
	 * Non-empty getId() value of an object.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function getIdOf(object $result): string|null {
		if (method_exists($result, 'getId') === true) {
			$id = (string)$result->getId();
			if ($id !== '') {
				return $id;
			}
		}

		return null;
	}//end getIdOf()

	/**
	 * Normalise an OR record into a flat array (copying id through).
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	public function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			return $this->objectToArray(object: $object);
		}

		return null;
	}//end toArray()

	/**
	 * Normalise an OR record object into a flat array.
	 *
	 * @param object $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>
	 */
	private function objectToArray(object $object): array {
		if (method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		$inner = $this->innerObjectOf(object: $object);
		if ($inner !== null) {
			return $inner;
		}

		return (array)$object;
	}//end objectToArray()

	/**
	 * The getObject() array of a record, with its uuid copied in as id.
	 *
	 * @param object $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>|null Null when the record has no array getObject().
	 */
	private function innerObjectOf(object $object): array|null {
		if (method_exists($object, 'getObject') === false) {
			return null;
		}

		$inner = $object->getObject();
		if (is_array($inner) === false) {
			return null;
		}

		return $this->withUuidId(object: $object, inner: $inner);
	}//end innerObjectOf()

	/**
	 * Copy the record's uuid into the inner array as id when it has none.
	 *
	 * @param object               $object OR ObjectService payload.
	 * @param array<string, mixed> $inner  The record's getObject() array.
	 *
	 * @return array<string, mixed>
	 */
	private function withUuidId(object $object, array $inner): array {
		if (method_exists($object, 'getUuid') === true) {
			$uuid = (string)$object->getUuid();
			if ($uuid !== '' && isset($inner['id']) === false) {
				$inner['id'] = $uuid;
			}
		}

		return $inner;
	}//end withUuidId()
}//end class
