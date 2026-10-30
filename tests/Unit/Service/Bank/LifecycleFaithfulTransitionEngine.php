<?php

/**
 * A transition engine that obeys the effective register's lifecycles.
 *
 * It reads the `x-openregister-lifecycle` of the schema as the app imports it
 * (RegisterSchema merges every register.d fragment), refuses a transition the
 * schema does not declare or whose `from` does not hold, and writes the target
 * state into the declared field of the in-memory store. A test that drives an
 * undeclared transition name or a state the schema cannot leave goes red here
 * exactly as OpenRegister's TransitionEngine would refuse it live.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use RuntimeException;

/**
 * Same method name and argument order as OpenRegister's TransitionEngine::transition().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LifecycleFaithfulTransitionEngine {
	/**
	 * Every transition that ran, in order.
	 *
	 * @var array<int,array{objectId:string,action:string,schema:string,from:string,to:string}>
	 */
	public array $ran = [];

	/**
	 * Constructor.
	 *
	 * @param InMemoryObjectServiceStub $store    The shared object store.
	 * @param array<int,string>         $schemas  The schemas to search for the object.
	 * @param callable|null             $onRan    Called after each transition with its record.
	 */
	public function __construct(
		private readonly InMemoryObjectServiceStub $store,
		private readonly array $schemas,
		private $onRan = null,
	) {
	}

	/**
	 * Run a declared transition.
	 *
	 * @param string              $objectId The object uuid.
	 * @param string              $action   The transition name.
	 * @param array<string,mixed> $data     Declared inputs (unused).
	 *
	 * @return object
	 */
	public function transition(string $objectId, string $action, array $data = []): object {
		foreach ($this->schemas as $schema) {
			$entity = $this->store->find($objectId, schema: $schema);
			if ($entity === null) {
				continue;
			}

			$object = $entity->getObject();
			$lifecycle = (RegisterSchema::schema(slug: $schema)['x-openregister-lifecycle'] ?? []);
			$field = (string)($lifecycle['field'] ?? 'lifecycleState');
			$declared = ($lifecycle['transitions'][$action] ?? null);
			if ($declared === null) {
				throw new RuntimeException($schema . ' declares no transition ' . $action);
			}

			$from = (string)($object[$field] ?? ($lifecycle['initialState'] ?? ''));
			if (in_array($from, (array)$declared['from'], true) === false) {
				throw new RuntimeException($schema . '.' . $action . ' cannot leave ' . $from);
			}

			$object[$field] = (string)$declared['to'];
			$this->store->setSchema($schema)->saveObject($object);
			$record = ['objectId' => $objectId, 'action' => $action, 'schema' => $schema, 'from' => $from, 'to' => (string)$declared['to']];
			$this->ran[] = $record;
			if ($this->onRan !== null) {
				($this->onRan)($record, $object);
			}

			return $entity;
		}//end foreach

		throw new RuntimeException('object ' . $objectId . ' not found');
	}
}//end class
