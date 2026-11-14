<?php

/**
 * Contract of PipelinqConfig
 *
 * The public surface of PipelinqConfig that other components call, so they depend
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
 * Contract of PipelinqConfig.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PipelinqConfigInterface {

	/**
	 * Return the configured pipelinq API endpoint URL.
	 *
	 * Empty string when unset.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function getPipelinqEndpoint(): string;

	/**
	 * Indicates whether a non-empty token is stored.
	 *
	 * Used by the admin settings UI to render a masked placeholder
	 * when a token is already configured (the token itself is never
	 * returned to the frontend).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function hasPipelinqToken(): bool;

	/**
	 * Persist the pipelinq API endpoint URL.
	 *
	 * Trims the value before saving. An empty string clears the
	 * setting; no normalisation beyond trim is performed at this
	 * layer — the HTTP adapter (member 02) owns request-URL
	 * construction.
	 *
	 * @param string $url The endpoint URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function setPipelinqEndpoint(string $url): void;

	/**
	 * Persist the pipelinq API token in the secrets store.
	 *
	 * An empty string deletes the stored token. The token is never
	 * written to logs. Per ADR-005 the value is stored via
	 * ICredentialsManager (encrypted at rest by Nextcloud), not in
	 * IAppConfig.
	 *
	 * @param string $token The API token, or '' to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function setPipelinqToken(string $token): void;

	/**
	 * Issue a pipelinq health-check request and return the outcome.
	 *
	 * On HTTP 200 the result has `success: true`; any non-2xx, a
	 * client error, or a timeout returns `success: false` with the
	 * status code and a sanitised error message (no token, no
	 * stack trace).
	 *
	 * Used by the admin settings "Test Connection" button. The
	 * adapter member (02) replaces this with a typed contract; for
	 * config-only purposes a plain GET is sufficient.
	 *
	 * @return array<string,mixed> The outcome (success, status, message).
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function testConnection(): array;
}//end interface
