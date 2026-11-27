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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

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
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (
				$create,
				$test
			): ObjectEntityInterface {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
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
			function (ObjectDeletion $deletion): bool {
				$uuid = $deletion->uuid;
				$register = $deletion->target->register;
				$schema = $deletion->target->schema;
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
