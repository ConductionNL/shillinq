<?php

/**
 * Dunning channel-adapter port.
 *
 * Per REQ-CCD-002, every DunningRun is dispatched via one of four channels:
 *   - EMAIL
 *   - EMAIL+POSTREGISTRATIE  (e-mail + a registered docudesk PDF)
 *   - AANGETEKENDE_POST      (PostNL Track & Trace registered post — REQ-CCD-009)
 *   - INCASSOBUREAU_API      (handover dossier to an incasso bureau — REQ-CCD-008)
 *
 * The adapter is wrapped behind a narrow port so the orchestrator
 * (DunningRunService) stays unit-testable and the production binding
 * (LogDunningChannelAdapter) can be swapped for an openconnector-backed
 * implementation without touching the surrounding code.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

/**
 * Channel-adapter port — one method per dispatch.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 */
interface DunningChannelAdapterInterface {
	/**
	 * Send a dunning run through the named channel.
	 *
	 * @param string $channel One of EMAIL / EMAIL+POSTREGISTRATIE / AANGETEKENDE_POST / INCASSOBUREAU_API.
	 * @param array<string,mixed> $payload Channel-specific payload — for EMAIL: subject/body/recipientEmail;
	 *                                     for AANGETEKENDE_POST: recipientAdres/letterPdfRef; for
	 *                                     INCASSOBUREAU_API: dossier bundle (invoice, dunning runs, incasso
	 *                                     kosten, klant, evidence URIs).
	 *
	 * @return DunningChannelSendResult The dispatch attempt outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 */
	public function send(string $channel, array $payload): DunningChannelSendResult;
}//end interface
