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
	 * The active organisation (real signature; the stub has none).
	 *
	 * @param array|null $preloadedOrgs Organisations already loaded.
	 *
	 * @return object|null The organisation entity in OpenRegister.
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?object {
		return null;

	}//end getActiveOrganisation()

	/**
	 * Set the active organisation (real signature).
	 *
	 * @param string $organisationUuid Organisation uuid.
	 *
	 * @return bool
	 */
	public function setActiveOrganisation(string $organisationUuid): bool {
		return true;

	}//end setActiveOrganisation()
}//end class
