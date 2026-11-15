<?php

/**
 * Credit Score Query
 *
 * The administration, customer and provider one credit-score lookup or
 * refresh is about.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

/**
 * One customer's credit score at one provider.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 */
class CreditScoreQuery {

	/**
	 * Administration scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Customer FK.
	 *
	 * @var string
	 */
	public string $customerId = '';

	/**
	 * GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS.
	 *
	 * @var string
	 */
	public string $provider = '';
}//end class
