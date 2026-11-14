<?php

/**
 * Bindings of the Kernel contracts to their Guard implementations
 *
 * @category  Guard
 * @package   OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Kernel\Contracts\DBAOpdrachtGuardInterface;
use OCA\Shillinq\Kernel\Contracts\DBAScoreCalculatorInterface;
use OCA\Shillinq\Kernel\Contracts\UrencriteriumYearGuardInterface;
use OCA\Shillinq\Kernel\Contracts\UrenDagregistratieGuardInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Shillinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 */
final class GuardContractRegistrar {

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
		$context->registerServiceAlias(DBAOpdrachtGuardInterface::class, DBAOpdrachtGuard::class);
		$context->registerServiceAlias(DBAScoreCalculatorInterface::class, DBAScoreCalculator::class);
		$context->registerServiceAlias(UrenDagregistratieGuardInterface::class, UrenDagregistratieGuard::class);
		$context->registerServiceAlias(UrencriteriumYearGuardInterface::class, UrencriteriumYearGuard::class);
	}//end register()
}//end class
