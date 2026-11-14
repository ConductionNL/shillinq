<?php

/**
 * Contract of MaterialiseGlTransactionAction
 *
 * The public surface of MaterialiseGlTransactionAction that other components call, so they depend
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
 * Contract of MaterialiseGlTransactionAction.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MaterialiseGlTransactionActionInterface {

	/**
	 * App-config key: output VAT account (RGS 3.5 MKB 2110 BTW-schuld).
	 *
	 * @var string
	 */
	public const CFG_OUTPUT_VAT_ACCOUNT = 'ledger_output_vat_account';

	/**
	 * App-config key: input VAT account (RGS 3.5 MKB 1230 BTW-vordering).
	 *
	 * @var string
	 */
	public const CFG_INPUT_VAT_ACCOUNT = 'ledger_input_vat_account';
}//end interface
