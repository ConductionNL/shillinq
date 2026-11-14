<?php

/**
 * Token Confirmation
 *
 * The appointment and the validated confirmation token of one confirm request.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * A confirm request whose token validated.
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 */
final class TokenConfirmation {
	/**
	 * The appointment to confirm.
	 *
	 * @var string
	 */
	public readonly string $appointmentId;

	/**
	 * The validated token record.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $token;

	/**
	 * Name a validated confirmation.
	 *
	 * @param string               $appointmentId The appointment to confirm.
	 * @param array<string, mixed> $token         The validated token record.
	 */
	public function __construct(string $appointmentId, array $token) {
		$this->appointmentId = $appointmentId;
		$this->token = $token;

	}//end __construct()

	/**
	 * The token's id ('' when it has none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	public function tokenId(): string {
		return (string)($this->token['tokenId'] ?? '');
	}//end tokenId()
}//end class
