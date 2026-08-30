<?php

/**
 * Incasso-bureau outbound adapter port.
 *
 * REQ-CCD-008 / task-20. The stage-5 OVERDRACHT_INCASSO POST to a configured
 * incasso bureau (Bos Incasso, Atradius Collections, Intrum) lives behind
 * this narrow port so the orchestrator (DunningRunService) stays
 * unit-testable and the production binding can swap to an
 * openconnector-backed implementation without touching surrounding code.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

/**
 * Incasso-bureau outbound port — one method per dossier POST.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 */
interface IncassoBureauAdapterInterface {
	/**
	 * POST a dossier bundle to the configured incasso bureau.
	 *
	 * Implementations MUST treat the bundle as the source of truth for the
	 * handover: it carries the invoice header, every DunningRun, the latest
	 * IncassoKostenBerekening, any DunningPauseDispute events, and the
	 * evidenceRefs URIs needed to satisfy the bureau's Wki / Wsnp claims.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK (for back-correlation).
	 * @param array<string,mixed> $dossier The dossier bundle (composed by IncassoDossierComposer).
	 *
	 * @return DunningChannelSendResult The dispatch attempt outcome. On success the
	 *                                  `extras` array MUST carry `dossierId` (provider id).
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult;
}//end interface
