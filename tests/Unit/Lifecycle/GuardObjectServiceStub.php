<?php

/**
 * Schema-aware ObjectService stub for EU-fondsen guard tests.
 *
 * Mirrors the OpenRegister ObjectService fluent contract (setRegister →
 * setSchema → findAll) used by the EU-fondsen lifecycle guards, returning a
 * different record set per schema so a single stub can back guards that query
 * several schemas in sequence (ADR-022 real-API shape).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

/**
 * Records-by-schema stub for the OpenRegister ObjectService fluent API.
 */
final class GuardObjectServiceStub {

	/**
	 * Records keyed by schema slug.
	 *
	 * @var array<string,array<mixed>>
	 */
	private array $recordsBySchema;

	/**
	 * The currently selected schema slug.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
	 */
	public function __construct(array $recordsBySchema) {
		$this->recordsBySchema = $recordsBySchema;
	}//end __construct()

	/**
	 * Named constructor for readability at call sites.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
	 *
	 * @return self
	 */
	public static function make(array $recordsBySchema): self {
		return new self($recordsBySchema);
	}//end make()

	/**
	 * Fluent register setter (no-op).
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter — selects the record set returned by findAll().
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Return the records for the currently selected schema.
	 *
	 * @param array<string,mixed> $params Query parameters (unused in stub).
	 *
	 * @return array<mixed>
	 */
	public function findAll(array $params = []): array {
		return ($this->recordsBySchema[$this->schema] ?? []);
	}//end findAll()
}//end class
