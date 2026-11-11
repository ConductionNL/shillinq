<?php

/**
 * Rate Query
 *
 * One {@see RateCardResolver::resolveRate()} lookup: the card, the resource
 * type, the date and the caller's server-resolved administration scope.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A rate lookup.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
class RateQuery {

	/**
	 * FK to RateCard (client-supplied).
	 *
	 * @var string
	 */
	public string $rateCardId = '';

	/**
	 * Resource type, e.g. 'senior_consultant'.
	 *
	 * @var string
	 */
	public string $resourceType = '';

	/**
	 * ISO date the rate should apply on.
	 *
	 * @var string
	 */
	public string $date = '';

	/**
	 * Caller's server-resolved administration scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';
}//end class
