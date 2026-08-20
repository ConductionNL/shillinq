<?php

/**
 * Credit-score fetch adapter port.
 *
 * REQ-CCD-007 / task-19. The outbound bridge to a credit-score provider
 * (Graydon, Creditsafe, Atradius Insights) lives behind this port so the
 * orchestrator (CreditScoreService) stays unit-testable and the production
 * binding can swap to an openconnector-backed implementation without
 * touching the surrounding code (mirrors the DunningChannelAdapter pattern).
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
 * Live credit-score fetch port — one method per fetch.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 */
interface CreditScoreFetchAdapterInterface {
	/**
	 * Fetch a fresh CreditScore snapshot from the named provider for a klant.
	 *
	 * Implementations MUST return a record in the canonical `CreditScore`
	 * shape (`klantId`, `provider`, `scoreDatum`, `score`, `scoreSchaal`,
	 * `betalingsRisicoIndicatie`, `creditLimietAdvies`, `kostenLookup`,
	 * `administrationId`). Returning null signals a temporary fetch failure;
	 * the caller treats it as "stay on the cached snapshot if any".
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $customerId Customer FK.
	 * @param string $provider One of GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS.
	 *
	 * @return array<string,mixed>|null The fresh snapshot, or null on temporary failure.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
	 */
	public function fetch(string $administrationId, string $customerId, string $provider): ?array;
}//end interface
