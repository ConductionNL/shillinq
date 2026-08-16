<?php

/**
 * In-memory ObjectService stub for procurement-governance service tests.
 *
 * Honours equality filters on findAll and stamps / updates ids on saveObject,
 * mirroring the fluent OpenRegister ObjectService surface the services consume
 * (setRegister -> setSchema -> findAll / saveObject).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

/**
 * Minimal in-memory ObjectService test double.
 */
final class InMemoryObjectServiceStub {

	/**
	 * Schema => rows.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $data;

	/**
	 * Captured saves.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $saved = [];

	/**
	 * Active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Auto-increment id counter.
	 *
	 * @var integer
	 */
	private int $idCounter = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Seed rows.
	 */
	public function __construct(array $data = []) {
		$this->data = $data;
	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Return rows for the active schema, applying equality filters.
	 *
	 * @param array<string,mixed> $params Query params.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $params = []): array {
		$rows = ($this->data[$this->schema] ?? []);
		$filters = ($params['filters'] ?? []);
		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * Capture a saved object; stamp an id when absent, update in place otherwise.
	 *
	 * @param array<string,mixed> $object Object payload.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object): array {
		if (isset($object['id']) === false || $object['id'] === '') {
			$this->idCounter++;
			$object['id'] = 'obj-' . $this->idCounter;
			$this->data[$this->schema][] = $object;
			$this->saved[] = ['schema' => $this->schema, 'object' => $object];
			return $object;
		}

		foreach (($this->data[$this->schema] ?? []) as $index => $row) {
			if (($row['id'] ?? null) === $object['id']) {
				$this->data[$this->schema][$index] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}
		}

		$this->data[$this->schema][] = $object;
		$this->saved[] = ['schema' => $this->schema, 'object' => $object];
		return $object;
	}//end saveObject()
}//end class
