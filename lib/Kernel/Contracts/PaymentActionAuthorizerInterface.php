<?php

/**
 * Contract of PaymentActionAuthorizer
 *
 * The public surface of PaymentActionAuthorizer that other components call, so they depend
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
 * Contract of PaymentActionAuthorizer.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentActionAuthorizerInterface {

	/**
	 * Raising a payment request on an object.
	 *
	 * @var string
	 */
	public const ACTION_REQUEST = 'payment.request';

	/**
	 * Administering a request: sending its link, settling it by other means.
	 *
	 * @var string
	 */
	public const ACTION_ADMINISTER = 'payment.administer';

	/**
	 * The calling user's id, or an empty string when there is no session.
	 *
	 * @return string The user id.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function callerId(): string;

	/**
	 * True when the calling user carries the action.
	 *
	 * @param string $action The action, one of the ACTION_* constants.
	 *
	 * @return bool True when the caller carries it.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 */
	public function may(string $action): bool;
}//end interface
