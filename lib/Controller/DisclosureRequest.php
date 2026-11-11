<?php

/**
 * Disclosure Request
 *
 * The administration, fiscal period, format and language one lease
 * disclosure table is asked for.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * One disclosure request: trimmed scope, lower-cased format and language.
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 */
final class DisclosureRequest {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The fiscal period.
	 *
	 * @var string
	 */
	public readonly string $fiscalPeriod;

	/**
	 * The format (json by default).
	 *
	 * @var string
	 */
	public readonly string $format;

	/**
	 * The disclosure note language (en by default).
	 *
	 * @var string
	 */
	public readonly string $language;

	/**
	 * Read the request parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->fiscalPeriod = trim((string)$request->getParam('fiscal_period', ''));
		$this->format = strtolower(trim((string)$request->getParam('format', 'json')));
		$this->language = strtolower(trim((string)$request->getParam('language', 'en')));

	}//end __construct()
}//end class
