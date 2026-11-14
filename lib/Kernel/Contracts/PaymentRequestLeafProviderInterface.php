<?php

/**
 * Contract of PaymentRequestLeafProvider
 *
 * The public surface of PaymentRequestLeafProvider that other components call, so they depend
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

use OCA\Shillinq\Kernel\Contracts\PaymentActionAuthorizerInterface;
use RuntimeException;

/**
 * Contract of PaymentRequestLeafProvider.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRequestLeafProviderInterface {

	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'shillinq-payment-requests';

	/**
	 * The action a caller needs before a request may be raised.
	 *
	 * @var string
	 */
	public const ACTION_REQUEST = PaymentActionAuthorizerInterface::ACTION_REQUEST;

	/**
	 * Append one payment request to the host object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The request fields: amount, currency, requestType, description, debtor, dueAt.
	 *
	 * @return array<string, mixed> The created request.
	 *
	 * @throws RuntimeException When the caller lacks the payment.request action.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array;
}//end interface
