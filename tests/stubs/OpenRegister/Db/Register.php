<?php

/**
 * Minimal Register stub mirroring the id accessors of OCA\OpenRegister\Db\Register on openregister main.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for OCA\OpenRegister\Db\Register used by shillinq tests.
 */
class Register {
	/**
	 * The register's database id.
	 *
	 * @var int|null
	 */
	private ?int $id = null;

	/**
	 * Set the database id, as the Nextcloud Entity setter does.
	 *
	 * @param int|null $id The register's database id.
	 *
	 * @return void
	 */
	public function setId(?int $id): void {
		$this->id = $id;
	}//end setId()

	/**
	 * The register's database id.
	 *
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}//end getId()
}//end class
