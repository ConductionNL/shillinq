<?php

/**
 * Contract of AccountantsdossierExportService
 *
 * The public surface of AccountantsdossierExportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of AccountantsdossierExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccountantsdossierExportServiceInterface {

	/**
	 * Build and persist the dossier as a ZIP package + PKIO delegation (REQ-010).
	 *
	 * Writes the ZIP to `sys_get_temp_dir()` (caller streams or moves it
	 * into archival storage). The PKIO signature step is delegated to the
	 * configured signer URI — when no signer is configured the bundle is
	 * still complete and the envelope flags signaturePending=true so the
	 * caller can hand it off out-of-band.
	 *
	 * @param string $protocolId The Controleprotocol.id to bundle.
	 *
	 * @return array{
	 *     packageId:string,
	 *     protocolId:string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     sha256:string,
	 *     zipPath:string,
	 *     attachmentCount:int,
	 *     retentionYears:int,
	 *     signaturePending:bool,
	 *     signerUri:?string,
	 *     signedAt:?string,
	 *     thumbprint:?string
	 * }
	 *
	 * @throws RuntimeException When the bundle cannot be written.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function exportDossier(string $protocolId): array;
}//end interface
