<?php

/**
 * DeepLinkRegistryServiceInterface stub mirroring OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface on openregister main.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Stub for OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface used by shillinq tests.
 */
interface DeepLinkRegistryServiceInterface {
	/**
	 * Add one deep link registration.
	 *
	 * @param DeepLinkRegistration $registration The registration.
	 *
	 * @return void
	 */
	public function add(DeepLinkRegistration $registration): void;

	/**
	 * Ask every app to register its deep links.
	 *
	 * @param IEventDispatcher|null $dispatcher The dispatcher.
	 *
	 * @return void
	 */
	public function requestRegistrations(?IEventDispatcher $dispatcher = null): void;
}//end interface
