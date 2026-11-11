<?php

/**
 * Innovatiebox Year
 *
 * The administration and financial year one innovatiebox request is about.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The trimmed administration and financial year of one request.
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 */
final class InnovatieboxYear {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The financial year as given.
	 *
	 * @var string
	 */
	public readonly string $financialYear;

	/**
	 * Read the request parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->financialYear = trim((string)$request->getParam('financialYear', ''));

	}//end __construct()

	/**
	 * The financial year as an integer.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function year(): int {
		return (int)$this->financialYear;
	}//end year()

	/**
	 * The administration and year as log context, with the failure.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function logContext(\Throwable $e): array {
		return [
			'administrationId' => $this->administrationId,
			'financialYear' => $this->financialYear,
			'exception' => $e->getMessage(),
		];
	}//end logContext()
}//end class
