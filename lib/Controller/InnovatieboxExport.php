<?php

/**
 * Innovatiebox Export
 *
 * The year and method one innovatiebox SBR/PDF export is asked for.
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

/**
 * One export request: its scope and its allowlisted method.
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 */
final class InnovatieboxExport {
	/**
	 * The administration and financial year.
	 *
	 * @var InnovatieboxYear
	 */
	public readonly InnovatieboxYear $scope;

	/**
	 * The export method.
	 *
	 * @var string
	 */
	public readonly string $method;

	/**
	 * Name one export.
	 *
	 * @param InnovatieboxYear $scope  The administration and financial year.
	 * @param string           $method The export method.
	 */
	public function __construct(InnovatieboxYear $scope, string $method) {
		$this->scope = $scope;
		$this->method = $method;

	}//end __construct()

	/**
	 * The administration, year and method as log context, with the failure.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function logContext(\Throwable $e): array {
		return [
			'administrationId' => $this->scope->administrationId,
			'financialYear' => $this->scope->financialYear,
			'method' => $this->method,
			'exception' => $e->getMessage(),
		];
	}//end logContext()
}//end class
