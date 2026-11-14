<?php

/**
 * Bindings of the Kernel contracts to their Listener implementations
 *
 * @category  Listener
 * @package   OCA\Shillinq\Listener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Kernel\Contracts\PeppolDeliveryStatusListenerInterface;
use OCA\Shillinq\Kernel\Contracts\PosStockDecrementListenerInterface;
use OCA\Shillinq\Kernel\Contracts\TenderNedAwardDetectedListenerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class ListenerContractRegistrar {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(PeppolDeliveryStatusListenerInterface::class, PeppolDeliveryStatusListener::class);
		$context->registerServiceAlias(PosStockDecrementListenerInterface::class, PosStockDecrementListener::class);
		$context->registerServiceAlias(TenderNedAwardDetectedListenerInterface::class, TenderNedAwardDetectedListener::class);
	}//end register()
}//end class
