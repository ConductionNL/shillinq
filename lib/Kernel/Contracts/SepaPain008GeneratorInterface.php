<?php

/**
 * Contract of SepaPain008Generator
 *
 * The public surface of SepaPain008Generator that other components call, so they depend
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

use OCA\Shillinq\PaymentRun\RenderedFile;

/**
 * Contract of SepaPain008Generator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SepaPain008GeneratorInterface {

	/**
	 * The short format label of the rendered file.
	 *
	 * @var string
	 */
	public const FORMAT = 'sepa-pain008';

	/**
	 * The sum of the collection amounts, rounded to cents.
	 *
	 * @param list<array<string, mixed>> $collections The collections.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function controlSum(array $collections): float;

	/**
	 * Render a batch and its collections into a pain.008.001.02 document.
	 *
	 * @param array<string, mixed>             $batch       The DirectDebitBatch.
	 * @param list<array<string, mixed>>       $collections Its collections, each with `mandate` and `remittanceInfo`.
	 * @param array<string, string>            $creditor    name, iban, bic (optional) and creditorIdentifier.
	 *
	 * @return RenderedFile
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function render(array $batch, array $collections, array $creditor): RenderedFile;
}//end interface
