<?php

/**
 * Contract of SubscriptionCancellationService
 *
 * The public surface of SubscriptionCancellationService that other components call, so they depend
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
 * Contract of SubscriptionCancellationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubscriptionCancellationServiceInterface {

	/**
	 * The subscription is ended (or already was).
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Not this customer's running subscription.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * OpenRegister failed.
	 *
	 * @var string
	 */
	public const DOWNSTREAM_ERROR = 'downstream_error';

	/**
	 * End a customer's own subscription at their request.
	 *
	 * @param array<string, mixed> $claims    The verified portal subject claims.
	 * @param string               $profileId The RecurringInvoiceProfile id.
	 * @param string               $reasonCode The reason the customer chose, or ''.
	 * @param string               $reasonText The customer's note, or ''.
	 *
	 * @return string One of the constants above.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
	 */
	public function cancel(array $claims, string $profileId, string $reasonCode = '', string $reasonText = ''): string;
}//end interface
