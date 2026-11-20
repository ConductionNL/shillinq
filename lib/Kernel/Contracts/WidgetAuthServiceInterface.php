<?php

/**
 * Contract of WidgetAuthService
 *
 * The public surface of WidgetAuthService that other components call, so they depend
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
 * Contract of WidgetAuthService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WidgetAuthServiceInterface {

	/**
	 * Default rate limit (requests per minute) when the WidgetAccessKey
	 * record does not specify one. Mirrors REQ-WSW-001.
	 */
	public const DEFAULT_RATE_LIMIT = 100;

	/**
	 * Rate-limit window in seconds. Reset every minute per the rate-limit
	 * policy in tasks.md.
	 */
	public const RATE_LIMIT_WINDOW_SECONDS = 60;

	/**
	 * Check + increment the per-business rate-limit counter for the current
	 * minute window. Returns true if the request is allowed, false if the
	 * caller has exceeded the configured limit (REQ-WSW-001).
	 *
	 * Uses a distributed cache so the counter survives across workers; when
	 * the cache is unavailable the call fails closed (returns false) so a
	 * misconfigured cache cannot disable rate-limiting silently.
	 *
	 * @param string $businessId Partner business id resolved from the request.
	 * @param int $limit Configured per-key rate limit (per minute).
	 *
	 * @return array{allowed: bool, remaining: int, retryAfter: int}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function consumeRateLimit(string $businessId, int $limit = self::DEFAULT_RATE_LIMIT): array;

	/**
	 * Mint a new WidgetAccessKey for the given administration + businessId.
	 *
	 * Returns the freshly-generated plaintext key alongside the persisted
	 * record. The plaintext key is shown to the partner once and never
	 * persisted (REQ-WSW-009).
	 *
	 * @param string $administrationId Tenant boundary id.
	 * @param string $businessId Public partner business id.
	 * @param string $actor Nextcloud user id performing the action.
	 *
	 * @return array{success: bool, message?: string, businessId?: string, apiKey?: string, record?: array<string,mixed>}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function createApiKey(string $administrationId, string $businessId, string $actor): array;

	/**
	 * Administratively revoke an API key.
	 *
	 * Idempotent: re-revoking an already-revoked key is a no-op success.
	 *
	 * @param string $businessId Partner business id whose key is being revoked.
	 * @param string $actor Nextcloud user id performing the revocation.
	 *
	 * @return array{success: bool, message?: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function revokeApiKey(string $businessId, string $actor): array;

	/**
	 * Generate a new plaintext key and replace the active hash for businessId.
	 *
	 * The predecessor record is transitioned to status=rotating so its hash
	 * stays comparable during the 7-day grace period (REQ-WSW-009). Callers
	 * are responsible for clearing rotating records once grace has elapsed.
	 *
	 * @param string $businessId Partner business id whose key is being rotated.
	 * @param string $actor Nextcloud user id performing the rotation.
	 *
	 * @return array{success: bool, message?: string, apiKey?: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function rotateApiKey(string $businessId, string $actor): array;

	/**
	 * Validate an incoming `Authorization: Bearer ...` token against the
	 * stored bcrypt hash for the given business id (REQ-WSW-001).
	 *
	 * Returns `['valid' => true]` on success. On failure, returns
	 * `['valid' => false, 'error' => '...']` with a partner-safe message
	 * (never leaks why the key was rejected to the caller — only via the log).
	 *
	 * @param string $businessId Partner business id from the request query/body.
	 * @param string $apiKey Plaintext bearer token extracted from the header.
	 *
	 * @return array{valid: bool, error?: string, key?: array<string,mixed>}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function validateApiKey(string $businessId, string $apiKey): array;
}//end interface
