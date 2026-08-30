<?php

/**
 * Pipelinq Connection Configuration Service.
 *
 * Persists and surfaces the pipelinq integration connection settings
 * (API endpoint + API token). The endpoint URL is stored in
 * plaintext app config; the token is stored in the Nextcloud secrets
 * store (ICredentialsManager) per ADR-005 — never plaintext, never
 * written to logs. Surfaces a `testConnection()` health-check used by
 * the admin settings "Test Connection" action.
 *
 * Member 1 of the bookings-pipelinq-customer-bridge chain (ADR-032);
 * config-only — no HTTP adapter logic. The adapter lands in member 02.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use OCA\Shillinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pipelinq connection configuration service.
 *
 * Provides typed getters/setters for the endpoint URL and the API
 * token plus a `testConnection()` health-check used by the admin
 * settings UI. The token is stored in the Nextcloud secrets store
 * (never plaintext) per ADR-005.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */
class PipelinqConfig {

	/**
	 * IAppConfig key for the pipelinq API endpoint URL.
	 *
	 * Stored in plaintext app config — non-secret.
	 *
	 * @var string
	 */
	public const KEY_ENDPOINT = 'pipelinq_api_endpoint';

	/**
	 * Secrets-store identifier for the pipelinq API token.
	 *
	 * The token is persisted via ICredentialsManager under this
	 * identifier — never plaintext, never logged.
	 *
	 * @var string
	 */
	public const CREDENTIAL_ID_TOKEN = 'pipelinq_api_token';

	/**
	 * Default health-check sub-path appended to the endpoint when
	 * no explicit health URL is configured.
	 *
	 * @var string
	 */
	public const HEALTH_PATH = '/health';

	/**
	 * Health-check HTTP timeout in seconds — short enough that an
	 * unreachable pipelinq does not block the admin UI.
	 *
	 * @var int
	 */
	private const HEALTH_TIMEOUT_SECONDS = 5;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config store
	 *                              for the plaintext
	 *                              endpoint URL.
	 * @param ICredentialsManager $credentialsManager Secrets store for
	 *                                                the API token.
	 * @param IClientService $clientService Nextcloud HTTP
	 *                                      client factory
	 *                                      used by
	 *                                      testConnection().
	 * @param LoggerInterface $logger PSR logger; the
	 *                                token is never
	 *                                included in log
	 *                                payloads.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICredentialsManager $credentialsManager,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Return the configured pipelinq API endpoint URL.
	 *
	 * Empty string when unset.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function getPipelinqEndpoint(): string {
		return $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::KEY_ENDPOINT,
			default: ''
		);

	}//end getPipelinqEndpoint()

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
	public function setPipelinqEndpoint(string $url): void {
		$this->appConfig->setValueString(
			app: Application::APP_ID,
			key: self::KEY_ENDPOINT,
			value: trim($url)
		);

	}//end setPipelinqEndpoint()

	/**
	 * Return the configured pipelinq API token from the secrets store.
	 *
	 * Returns an empty string when no token is configured. Callers
	 * MUST NOT log the result.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
	 */
	public function getPipelinqToken(): string {
		$secret = $this->credentialsManager->retrieve(
			userId: '',
			identifier: self::CREDENTIAL_ID_TOKEN
		);
		if (is_string($secret) === false || $secret === '') {
			return '';
		}

		return $secret;
	}//end getPipelinqToken()

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
	public function setPipelinqToken(string $token): void {
		$trimmed = trim($token);
		if ($trimmed === '') {
			$this->credentialsManager->delete(
				userId: '',
				identifier: self::CREDENTIAL_ID_TOKEN
			);

			return;
		}

		$this->credentialsManager->store(
			userId: '',
			identifier: self::CREDENTIAL_ID_TOKEN,
			credentials: $trimmed
		);

	}//end setPipelinqToken()

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
	public function hasPipelinqToken(): bool {
		return $this->getPipelinqToken() !== '';
	}//end hasPipelinqToken()

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
	public function testConnection(): array {
		$endpoint = $this->getPipelinqEndpoint();
		if ($endpoint === '') {
			return [
				'success' => false,
				'status' => 0,
				'message' => 'No pipelinq endpoint configured.',
			];
		}

		$token = $this->getPipelinqToken();
		if ($token === '') {
			return [
				'success' => false,
				'status' => 0,
				'message' => 'No pipelinq API token configured.',
			];
		}

		$healthUrl = rtrim($endpoint, '/') . self::HEALTH_PATH;

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$healthUrl,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept' => 'application/json',
					],
					'timeout' => self::HEALTH_TIMEOUT_SECONDS,
					'connect_timeout' => self::HEALTH_TIMEOUT_SECONDS,
				]
			);
		} catch (Throwable $e) {
			// Sanitise: log only the exception class + message — never
			// the URL with embedded token or the request headers.
			$this->logger->warning(
				'Shillinq: pipelinq health-check failed',
				[
					'exception' => get_class($e),
					'message' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'status' => 0,
				'message' => 'Pipelinq health-check failed: ' . $e->getMessage(),
			];
		}//end try

		$status = $response->getStatusCode();
		if ($status >= 200 && $status < 300) {
			return [
				'success' => true,
				'status' => $status,
				'message' => 'Pipelinq connection succeeded.',
			];
		}

		return [
			'success' => false,
			'status' => $status,
			'message' => 'Pipelinq returned HTTP ' . $status . '.',
		];

	}//end testConnection()
}//end class
