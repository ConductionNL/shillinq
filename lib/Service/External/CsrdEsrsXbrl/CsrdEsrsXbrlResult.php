<?php

/**
 * Result value-object returned by a CsrdEsrsXbrlAdapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\CsrdEsrsXbrl
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\CsrdEsrsXbrl;

/**
 * Result of an EFRAG ESRS XBRL taxonomy / instance build attempt.
 *
 * `status` is one of `MAPPED`, `INSTANCE_BUILT`, `VALIDATED`,
 * `VALIDATION_BLOCKED`, `DEFERRED`, `ERROR`. `MAPPED` is the outcome of
 * a taxonomy-mapping call (esrs-data-point → XBRL fact concept);
 * `INSTANCE_BUILT` is the outcome of an iXBRL instance-document build
 * (Inline XBRL PDF with embedded fact markup);
 * `VALIDATION_BLOCKED` carries the mandatory-data-point error list
 * from EFRAG IG-3. `DEFERRED` is the dormant default.
 *
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 */
final class CsrdEsrsXbrlResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $status One of MAPPED |
	 *                       INSTANCE_BUILT
	 *                       | VALIDATED |
	 *                       VALIDATION_BLOCKED
	 *                       | DEFERRED |
	 *                       ERROR.
	 * @param string $documentId Adapter-side opaque id of
	 *                           the resulting taxonomy
	 *                           mapping or iXBRL instance
	 *                           (synthetic for dormant).
	 * @param string $taxonomyVersion EFRAG ESRS taxonomy
	 *                                version, e.g.
	 *                                `efrag-esrs-2024`.
	 * @param string $contentType MIME of the artefact
	 *                            —
	 *                            `application/xbrl+xml`
	 *                            for an instance,
	 *                            `application/json` for
	 *                            a mapping document.
	 * @param string $payloadRef Reference to the built
	 *                           artefact bytes (URI to
	 *                           docudesk,
	 *                           openconnector handle,
	 *                           or empty for dormant).
	 * @param array<int,string> $missingMandatory ID list of mandatory
	 *                                            EFRAG IG-3 data
	 *                                            points still missing
	 *                                            or unassured
	 *                                            (populated on
	 *                                            VALIDATION_BLOCKED).
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (mapping cache key, fact
	 *                                    count, validation
	 *                                    messages).
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $documentId,
		public readonly string $taxonomyVersion,
		public readonly string $contentType,
		public readonly string $payloadRef,
		public readonly array $missingMandatory,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
