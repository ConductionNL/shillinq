<?php

/**
 * Test double for OpenRegister's ObjectEntityInterface.
 *
 * ADR-084 moved the leaf apps off an untyped `ContainerInterface` hop and onto
 * OpenRegister's published contract. One consequence is easy to miss and was
 * missed here: `find()` and `saveObject()` are declared
 * `: ?ObjectEntityInterface` / `: ObjectEntityInterface`, so a double that hands
 * back a bare array no longer stands in for the real service — and a double that
 * hands back an unconfigured mock serialises to nothing at all, which reads in a
 * test as "the record was not found" rather than "the double was not wired".
 *
 * This class is the smallest honest entity: it carries a payload and returns it
 * from `jsonSerialize()` and `getObject()`, which is exactly what the production
 * conversions call.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * An ObjectEntityInterface carrying a plain array payload.
 */
final class ObjectEntityStub implements ObjectEntityInterface {

	/**
	 * The stored object payload.
	 *
	 * @var array<string,mixed>
	 */
	private array $payload;

	/**
	 * Owning register slug.
	 *
	 * @var string|null
	 */
	private ?string $register;

	/**
	 * Owning schema slug.
	 *
	 * @var string|null
	 */
	private ?string $schema;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload  The stored object payload.
	 * @param string|null         $register Owning register slug.
	 * @param string|null         $schema   Owning schema slug.
	 */
	public function __construct(array $payload, ?string $register = null, ?string $schema = null) {
		$this->payload = $payload;
		$this->register = $register;
		$this->schema = $schema;

	}//end __construct()

	/**
	 * The payload, as the production conversions read it.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->payload;

	}//end jsonSerialize()

	/**
	 * The payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return $this->payload;

	}//end getObject()

	/**
	 * The object UUID, taken from the payload when present.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		$uuid = ($this->payload['uuid'] ?? $this->payload['id'] ?? null);
		if ($uuid === null) {
			return null;
		}

		return (string)$uuid;

	}//end getUuid()

	/**
	 * The owning register slug.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string {
		return $this->register;

	}//end getRegister()

	/**
	 * The owning schema slug.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string {
		return $this->schema;

	}//end getSchema()

	/**
	 * The owning organisation, taken from the payload when present.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string {
		$organisation = ($this->payload['organisation'] ?? null);
		if ($organisation === null) {
			return null;
		}

		return (string)$organisation;

	}//end getOrganisation()

	/**
	 * The owner, taken from the payload when present.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string {
		$owner = ($this->payload['owner'] ?? null);
		if ($owner === null) {
			return null;
		}

		return (string)$owner;

	}//end getOwner()
}//end class
