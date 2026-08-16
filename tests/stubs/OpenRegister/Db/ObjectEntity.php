<?php

/**
 * Minimal ObjectEntity stub for unit tests that build OR Event payloads
 * without depending on the OpenRegister app being autoloaded. Mirrors
 * the public surface the shillinq listener tests exercise.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for OCA\OpenRegister\Db\ObjectEntity used by shillinq tests.
 */
class ObjectEntity implements \OCA\OpenRegister\Contract\ObjectEntityInterface {
		/**
		 * @return ?string
		 */
		public function getOrganisation(): ?string {
			return $this->organisation ?? null;
		}

		/**
		 * @return ?string
		 */
		public function getOwner(): ?string {
			return $this->owner ?? null;
		}

		/**
		 * @return array<string,mixed>
		 */
		public function jsonSerialize(): array {
			return (array)($this->object ?? []);
		}


	/**
	 * The decoded object payload.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $object = null;

	/**
	 * The schema slug.
	 *
	 * @var string|null
	 */
	private ?string $schema = null;

	/**
	 * The register slug.
	 *
	 * @var string|null
	 */
	private ?string $register = null;

	/**
	 * The numeric or string id.
	 *
	 * @var integer|string|null
	 */
	private $id = null;

	/**
	 * The uuid.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * Return the decoded object payload.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getObject(): array {
		// The contract declares `array`, not `?array`. A return type may be
		// narrowed by an implementor but never widened, so `?array` here is a
		// fatal at class load -- which is what it was doing.
		return ($this->object ?? []);
	}//end getObject()

	/**
	 * Set the decoded object payload.
	 *
	 * @param array<string,mixed>|null $object The payload.
	 *
	 * @return self
	 */
	public function setObject(?array $object): self {
		$this->object = $object;
		return $this;
	}//end setObject()

	/**
	 * Return the schema slug.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string {
		return $this->schema;
	}//end getSchema()

	/**
	 * Set the schema slug.
	 *
	 * @param string|null $schema The schema slug.
	 *
	 * @return self
	 */
	public function setSchema(?string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Return the register slug.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string {
		return $this->register;
	}//end getRegister()

	/**
	 * Set the register slug.
	 *
	 * @param string|null $register The register slug.
	 *
	 * @return self
	 */
	public function setRegister(?string $register): self {
		$this->register = $register;
		return $this;
	}//end setRegister()

	/**
	 * Return the id.
	 *
	 * @return integer|string|null
	 */
	public function getId() {
		return $this->id;
	}//end getId()

	/**
	 * Set the id.
	 *
	 * @param integer|string|null $id The id.
	 *
	 * @return self
	 */
	public function setId($id): self {
		$this->id = $id;
		return $this;
	}//end setId()

	/**
	 * Return the uuid.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Set the uuid.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return self
	 */
	public function setUuid(?string $uuid): self {
		$this->uuid = $uuid;
		return $this;
	}//end setUuid()
}//end class
