<?php

/**
 * CBS Iv3 (Informatie voor derden) submission port.
 *
 * Iv3 is the Dutch municipality / public-body quarterly + annual
 * statistical report that BZK collects from local governments via the
 * CBS Iv3 portal. Although the transport endpoint is operated by CBS,
 * the dataset shape + categorisation differ enough from CBS Bestanden
 * that we keep a dedicated port.
 *
 * The default binding is a dormant log-only stub; production wiring
 * arrives through openconnector once a source slug `cbs-iv3` is
 * configured.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Cbs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Cbs;

/**
 * CBS Iv3 quarterly/annual submission port.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 */
interface CbsIv3AdapterInterface {
	/**
	 * Submit an Iv3 quarterly or annual report.
	 *
	 * @param array<string,mixed> $payload The Iv3 envelope — periodType
	 *                                     (KWARTAAL|JAAR), periodValue,
	 *                                     organizationCode, lines[] (functie + categorie + bedrag),
	 *                                     reportingXmlBytes, checksum.
	 *
	 * @return CbsSubmissionResult The dispatch outcome.
	 */
	public function submit(array $payload): CbsSubmissionResult;

	/**
	 * Whether the adapter is dormant.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
