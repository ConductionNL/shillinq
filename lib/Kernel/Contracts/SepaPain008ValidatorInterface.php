<?php

/**
 * Contract of SepaPain008Validator
 *
 * The public surface of SepaPain008Validator that other components call, so they depend
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
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of SepaPain008Validator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SepaPain008ValidatorInterface {

	/**
	 * Every reason the file may not be stored; empty when it is valid.
	 *
	 * @param string                     $xml         The rendered pain.008 document.
	 * @param list<array<string, mixed>> $collections The collections it was rendered from, each with `mandate`.
	 * @param array<string, string>      $creditor    name, iban, bic and creditorIdentifier.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function errors(string $xml, array $collections, array $creditor): array;
}//end interface
