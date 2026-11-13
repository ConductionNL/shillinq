<?php

/**
 * Contract of ConfirmationTokenService
 *
 * The public surface of ConfirmationTokenService that other components call, so they depend
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
 * Contract of ConfirmationTokenService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConfirmationTokenServiceInterface {

	/**
	 * Generate a token + persist it + send the confirmation email. Used by
	 * appointment.create flow (REQ-BCF-001/003).
	 *
	 * @param array<string, mixed> $appointment Appointment record (must
	 *                                          contain `appointmentId`,
	 *                                          `startTime`, `endTime`;
	 *                                          `customerId`, `serviceName`,
	 *                                          `customerEmail` optional).
	 * @param array<string, mixed> $customer Customer descriptor — at
	 *                                       minimum `id` and `email`.
	 *                                       Optional `userId`, `name`.
	 *
	 * @return array{tokenId:string,sent:bool} Outcome — `sent` is FALSE
	 *                                         when openconnector is unavailable (email is logged for
	 *                                         operator review).
	 */
	public function issueAndSend(array $appointment, array $customer): array;

	/**
	 * Mark the given token as redeemed.
	 *
	 * @param array<string, mixed> $token OR record for the token to redeem.
	 *
	 * @return array<string, mixed> The updated record.
	 */
	public function markRedeemed(array $token): array;

	/**
	 * Resend the confirmation email — revoke current token, issue a new one
	 * + dispatch (REQ-BCF-006).
	 *
	 * @param array<string, mixed> $appointment Appointment record.
	 * @param array<string, mixed> $customer Customer descriptor.
	 * @param string $actor User id that triggered resend
	 *                      (the customer or an admin).
	 *
	 * @return array{tokenId:string,sent:bool} Outcome — same shape as
	 *                                         {@see ConfirmationTokenService::issueAndSend()}.
	 */
	public function resend(array $appointment, array $customer, string $actor = 'system'): array;

	/**
	 * Validate a presented (plaintext) token against the active token for
	 * the given appointment without mutating any state.
	 *
	 * @param string $appointmentId Appointment id.
	 * @param string $plaintext Plaintext token from the URL / portal.
	 *
	 * @return array{ok:bool,reason:string,token?:array<string,mixed>} Status — `ok`
	 *                                                                 TRUE iff the token verifies, is active and not expired. `reason`
	 *                                                                 encodes the failure mode for the controller to map onto an HTTP
	 *                                                                 status (REQ-BCF-004 scenarios).
	 */
	public function validate(string $appointmentId, string $plaintext): array;
}//end interface
