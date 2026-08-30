<?php

/**
 * BZK SiSa (Single Information Single Audit) upload port.
 *
 * Dutch municipalities + co-financed grant recipients file an annual
 * SiSa report to the Ministry of BZK (Binnenlandse Zaken en
 * Koninkrijksrelaties) via the BZK SiSa upload portal. Shillinq
 * materialises the report payload from the `SisaReport`,
 * `AuditDocument`, `ComplianceAuditTrail`, and `ManagementLetter`
 * schemas declared by the `bookkeeping-sisa-reporting` capability and
 * hands the envelope to this adapter for transport to BZK.
 *
 * The default binding is dormant: it logs the intent + returns a
 * DEFERRED outcome so the surrounding SiSa lifecycle can advance into
 * `submitted` without contacting BZK. Real transport wires through
 * openconnector source slug `bzk-sisa` once configured.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Sisa
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Sisa;

/**
 * BZK SiSa upload port.
 *
 * @spec openspec/specs/bookkeeping-sisa-reporting/spec.md
 */
interface BzkSisaUploadAdapterInterface {
	/**
	 * Upload a SiSa annual report envelope to BZK.
	 *
	 * @param array<string,mixed> $payload The SiSa envelope — reportNumber,
	 *                                     fiscalYear, administrationId,
	 *                                     onTimeSettlementPercent, findings[],
	 *                                     auditOpinion, managementLetterId,
	 *                                     reportXmlBytes, signedPdfBytes,
	 *                                     checksum.
	 *
	 * @return BzkSisaUploadResult The dispatch outcome.
	 */
	public function upload(array $payload): BzkSisaUploadResult;

	/**
	 * Whether the adapter is dormant.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
