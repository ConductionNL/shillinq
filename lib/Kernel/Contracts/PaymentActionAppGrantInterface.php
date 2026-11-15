<?php

/**
 * Contract of PaymentActionAppGrant
 *
 * The public surface of PaymentActionAppGrant that other components call, so they depend
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
 * Contract of PaymentActionAppGrant.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentActionAppGrantInterface {

	/**
	 * True when the named app carries the action.
	 *
	 * @param string $appId The calling app's id.
	 * @param string $action The action, one of PaymentActionAuthorizer's ACTION_* constants.
	 *
	 * @return bool True when the app carries it.
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function allows(string $appId, string $action): bool;
}//end interface
