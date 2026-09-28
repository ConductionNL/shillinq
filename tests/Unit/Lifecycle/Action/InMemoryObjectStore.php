<?php

/**
 * An in-memory store behind a mock of OpenRegister's ObjectServiceInterface.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Wires a PHPUnit mock of the REAL contract interface (so only real method
 * names can be configured) to a per-schema array store.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InMemoryObjectStore {

	/**
	 * Rows per schema slug.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every saveObject() call as [schema, object].
	 *
	 * @var list<array{0: string, 1: array<string,mixed>}>
	 */
	public array $saved = [];

	/**
	 * Every deleteObject() call as [schema, uuid].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public array $deleted = [];

	/**
	 * Schema slug set by the last setSchema().
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Id counter for saved rows.
	 *
	 * @var integer
	 */
	private int $next = 0;

	/**
	 * Schema slug whose saves throw, to exercise the roll-back.
	 *
	 * @var string|null
	 */
	public ?string $failOnSchema = null;

	/**
	 * Build the mock bound to this store.
	 *
	 * @param TestCase $test The running test (for createMock through reflection).
	 *
	 * @return ObjectServiceInterface
	 */
	public function mock(TestCase $test): ObjectServiceInterface {
		$create = new \ReflectionMethod($test, 'createMock');
		$create->setAccessible(true);
		$service = $create->invoke($test, ObjectServiceInterface::class);

		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($service) {
				$this->schema = (string)$schema;
				return $service;
			}
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = (array)($config['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null
			) use (
				$create,
				$test
			): ObjectEntityInterface {
				$slug = (string)$schema;
				if ($slug === $this->failOnSchema) {
					throw new \RuntimeException('store refused ' . $slug);
				}

				$object['id'] = ($object['id'] ?? ($slug . '-' . (++$this->next)));
				$this->rows[$slug][] = $object;
				$this->saved[] = [$slug, $object];

				$entity = $create->invoke($test, ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);
		$service->method('deleteObject')->willReturnCallback(
			function (string $uuid, string|int|null $register = null, string|int|null $schema = null): bool {
				$this->deleted[] = [(string)$schema, $uuid];
				return true;
			}
		);

		return $service;
	}//end mock()

	/**
	 * The saved rows of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function savedOf(string $schema): array {
		$out = [];
		foreach ($this->saved as [$slug, $object]) {
			if ($slug === $schema) {
				$out[] = $object;
			}
		}

		return $out;
	}//end savedOf()
}//end class
