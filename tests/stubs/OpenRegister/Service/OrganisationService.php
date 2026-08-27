<?php

/**
 * Minimal OrganisationService stub for unit tests and static analysis.
 *
 * Only the surface `ActorForwardedJob` needs. shillinq never calls this
 * service directly — it appears solely as a constructor type the deferred job
 * must forward to its parent.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for OCA\OpenRegister\Service\OrganisationService.
 */
class OrganisationService {

	/**
	 * Return the active organisation uuid.
	 *
	 * @return string|null
	 */
	public function getActiveOrganisationUuid(): ?string {
		return null;

	}//end getActiveOrganisationUuid()

	/**
	 * Set the active organisation.
	 *
	 * @param string|null $uuid Organisation uuid.
	 *
	 * @return void
	 */
	public function setActiveOrganisation(?string $uuid): void {

	}//end setActiveOrganisation()
}//end class
