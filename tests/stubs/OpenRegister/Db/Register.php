<?php

/**
 * Minimal Register stub mirroring the id accessor of OCA\OpenRegister\Db\Register on openregister main.
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
	 * Constructor.
	 *
	 * @param int|null $id The register's database id.
	 */
	public function __construct(
		private readonly ?int $id=null,
	) {
	}//end __construct()

	/**
	 * The register's database id.
	 *
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}//end getId()
}//end class
