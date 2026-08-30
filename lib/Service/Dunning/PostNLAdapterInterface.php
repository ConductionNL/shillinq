<?php

/**
 * PostNL aangetekende-post outbound adapter port.
 *
 * REQ-CCD-009 / task-21. Stage-4 ingebrekestelling (AANGETEKENDE_POST kanaal)
 * needs a bewijs-van-ontvangst barcode + Track & Trace polling; the
 * orchestrator (DunningRunService) reaches the PostNL API via this narrow
 * port so the production binding can swap to an openconnector-backed
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

/**
 * PostNL outbound port — one method per registered-letter send.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
 */
interface PostNLAdapterInterface {
	/**
	 * Submit a registered letter to PostNL.
	 *
	 * The implementation MUST return a DunningChannelSendResult whose
	 * `extras` carry `barcode` (the 3S-prefixed Track & Trace code) and
	 * `trackingUrl`. On a temporary failure return deliveryStatus=FAILED
	 * with a `errorMessage` so the caller can queue a retry.
	 *
	 * @param array<string,mixed> $payload Channel-specific payload — recipientAdres + letterPdfRef.
	 *
	 * @return DunningChannelSendResult The dispatch attempt outcome.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-21
	 */
	public function sendRegisteredLetter(array $payload): DunningChannelSendResult;
}//end interface
