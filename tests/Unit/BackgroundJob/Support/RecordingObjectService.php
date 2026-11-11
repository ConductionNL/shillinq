<?php

/**
 * A recording stand-in for OpenRegister's ObjectService in job tests.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob\Support;

/**
 * Records every call in order and answers from per-schema queues.
 *
 * findAll() and find() answers are queued per schema; an answer that is a
 * Throwable is thrown. saveObject() answers the object, or throws the next
 * queued Throwable for the schema.
 */
final class RecordingObjectService {

	/**
	 * Calls in order.
	 *
	 * @var array<int,string>
	 */
	public array $calls = [];

	/**
	 * Objects saved, in order, as schema => object.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	public array $saved = [];

	/**
	 * Queued findAll() answers per schema.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	public array $findAllAnswers = [];

	/**
	 * find() answers per id.
	 *
	 * @var array<string,mixed>
	 */
	public array $findAnswers = [];

	/**
	 * Queued saveObject() failures per schema.
	 *
	 * @var array<string,array<int,\Throwable>>
	 */
	public array $saveFailures = [];

	/**
	 * The schema the service is scoped to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Scope to a register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->calls[] = 'register:' . $register;
		return $this;
	}

	/**
	 * Scope to a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->calls[] = 'schema:' . $schema;
		$this->schema = $schema;
		return $this;
	}

	/**
	 * List objects.
	 *
	 * @param array<string,mixed> $config The query.
	 *
	 * @return array<int,mixed>
	 */
	public function findAll(array $config): array {
		$this->calls[] = 'findAll:' . $this->schema . ':' . json_encode($config);
		$queue = ($this->findAllAnswers[$this->schema] ?? []);
		$answer = array_shift($queue);
		$this->findAllAnswers[$this->schema] = $queue;
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return ($answer ?? []);
	}

	/**
	 * Find one object.
	 *
	 * @param string $id The id.
	 *
	 * @return mixed
	 */
	public function find(string $id): mixed {
		$this->calls[] = 'find:' . $this->schema . ':' . $id;
		$answer = ($this->findAnswers[$id] ?? null);
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}

	/**
	 * Save an object.
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object): array {
		$this->calls[] = 'save:' . $this->schema;
		$queue = ($this->saveFailures[$this->schema] ?? []);
		$failure = array_shift($queue);
		$this->saveFailures[$this->schema] = $queue;
		if ($failure instanceof \Throwable) {
			throw $failure;
		}

		$this->saved[] = [$this->schema, $object];
		return $object;
	}
}//end class
