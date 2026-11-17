<?php

/**
 * Bindings of the Kernel contracts to their Integration implementations
 *
 * @category  Integration
 * @package   OCA\Shillinq\Integration
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

namespace OCA\Shillinq\Integration;

use OCA\Shillinq\Kernel\Contracts\ContractLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRequestLeafProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Integration
 * @package  OCA\Shillinq\Integration
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class IntegrationContractRegistrar {

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
		$context->registerServiceAlias(ContractLeafProviderInterface::class, ContractLeafProvider::class);
		$context->registerServiceAlias(FinancialDocumentLeafProviderInterface::class, FinancialDocumentLeafProvider::class);
		$context->registerServiceAlias(PaymentRequestLeafProviderInterface::class, PaymentRequestLeafProvider::class);
	}//end register()
}//end class
