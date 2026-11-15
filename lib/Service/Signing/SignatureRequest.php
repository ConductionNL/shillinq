<?php

/**
 * Signature Request
 *
 * What a finance object asks docudesk to sign: the subject schema, the document
 * class and reference, the signers, and the eIDAS level and signing mode.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;


/**
 * One document signing request raised for a finance object.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class SignatureRequest {

	/**
	 * NC Files reference to the document to sign; empty falls back to the object's own.
	 *
	 * @var string
	 */
	public string $documentReference = '';

	/**
	 * Signer descriptors for the signing chain.
	 *
	 * @var array<int,mixed>
	 */
	public array $signers = [];

	/**
	 * eIDAS level hint (simple|advanced|qualified).
	 *
	 * @var string
	 */
	public string $signatureLevel = 'advanced';

	/**
	 * docudesk signing mode hint (e.g. 'sequential').
	 *
	 * @var string
	 */
	public string $signingMode = 'sequential';

	/**
	 * Id of the finance object being signed, once read from it.
	 *
	 * @var string
	 */
	public string $subjectId = '';

	/**
	 * Constructor.
	 *
	 * @param string $subjectSchema The finance schema slug (e.g. 'ACMReport').
	 * @param string $documentClass Document class hint for docudesk (e.g. 'acm-report').
	 */
	public function __construct(
		public readonly string $subjectSchema,
		public readonly string $documentClass,
	) {

	}//end __construct()
}//end class
