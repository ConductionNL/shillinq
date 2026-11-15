<?php

/**
 * Signoff Request
 *
 * What a finance object asks decidesk to decide: the subject schema and the
 * decision type, and the subject id and label once read from the object.
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
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Signing;

/**
 * One governance sign-off request raised for a finance object.
 *
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
 */
final class SignoffRequest {

	/**
	 * Id of the finance object, once read from it.
	 *
	 * @var string
	 */
	public string $subjectId = '';

	/**
	 * Label of the finance object, once read from it.
	 *
	 * @var string
	 */
	public string $subjectLabel = '';

	/**
	 * Constructor.
	 *
	 * @param string $subjectSchema The finance schema slug (e.g. 'ACMReport').
	 * @param string $decisionType  Decision type hint for decidesk (e.g. 'sign-off').
	 */
	public function __construct(
		public readonly string $subjectSchema,
		public readonly string $decisionType,
	) {

	}//end __construct()
}//end class
