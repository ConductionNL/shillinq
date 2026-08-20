<?php

/**
 * Lease decidesk webhook delivery (Task 8.2).
 *
 * When LeaseReassessmentService persists a reassessment-event with
 * `status = pending-approval` (i.e. the RoU adjustment exceeds the
 * EUR 100,000 materiality threshold per REQ-LR-007) this service
 * delivers a webhook to decidesk so the board-decision flow can
 * approve / reject before GL posting is allowed.
 *
 * The endpoint URL is stored in app config under
 * `decidesk_webhook_url`; the bearer token sits in the Nextcloud
 * secrets store under `decidesk_webhook_token` per ADR-005 — never
 * plaintext, never logged. The delivery is best-effort: a
 * non-2xx response or a transport-level error is logged with the
 * event id but does NOT raise to the caller, because the persisted
 * event already carries the audit record the operator can resend.
 *
 * Cross-app integration: `bookkeeping-ifrs-16-lease` →
 * `decidesk` (board-decision flow). See proposal.md "Affected
 * Projects" and Task 8.2 in tasks.md.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers the decidesk approval webhook for material lease-reassessment events.
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 */
class LeaseDecideskWebhookService {

	/**
	 * IAppConfig key for the decidesk webhook endpoint URL.
	 *
	 * Stored in plaintext app config — non-secret.
	 *
	 * @var string
	 */
	public const KEY_WEBHOOK_URL = 'decidesk_webhook_url';

	/**
	 * Secrets-store identifier for the decidesk bearer token.
	 *
	 * The token is persisted via ICredentialsManager under this
	 * identifier — never plaintext, never logged.
	 *
	 * @var string
	 */
	public const CREDENTIAL_ID_TOKEN = 'decidesk_webhook_token';

	/**
	 * HTTP timeout in seconds — short enough that an unreachable decidesk
	 * does not block the reassessment flow on slow networks.
	 *
	 * @var int
	 */
	private const HTTP_TIMEOUT_SECONDS = 8;

	/**
	 * Construct the webhook delivery service.
	 *
	 * @param IAppConfig $appConfig App config store for the endpoint URL.
	 * @param ICredentialsManager $credentialsManager Secrets store for the bearer token.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param LoggerInterface $logger PSR logger; the token is never logged.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICredentialsManager $credentialsManager,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Return the configured decidesk webhook URL.
	 *
	 * Empty string when unset — the caller treats an empty URL as
	 * "webhook disabled, surface the event in-app only".
	 *
	 * @return string
	 */
	public function getWebhookUrl(): string {
		return $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::KEY_WEBHOOK_URL,
			default: ''
		);

	}//end getWebhookUrl()

	/**
	 * Persist the decidesk webhook URL.
	 *
	 * @param string $url The endpoint URL; empty string clears the setting.
	 *
	 * @return void
	 */
	public function setWebhookUrl(string $url): void {
		$this->appConfig->setValueString(
			app: Application::APP_ID,
			key: self::KEY_WEBHOOK_URL,
			value: trim($url)
		);

	}//end setWebhookUrl()

	/**
	 * Return the configured decidesk bearer token from the secrets store.
	 *
	 * Returns an empty string when no token is configured. Callers
	 * MUST NOT log the result.
	 *
	 * @return string
	 */
	public function getWebhookToken(): string {
		$secret = $this->credentialsManager->retrieve(
			userId: '',
			identifier: self::CREDENTIAL_ID_TOKEN
		);
		if (is_string($secret) === false || $secret === '') {
			return '';
		}

		return $secret;
	}//end getWebhookToken()

	/**
	 * Persist the decidesk bearer token in the secrets store.
	 *
	 * An empty string deletes the stored token. Per ADR-005 the value
	 * is stored via ICredentialsManager (encrypted at rest), not in
	 * IAppConfig.
	 *
	 * @param string $token The bearer token, or '' to clear.
	 *
	 * @return void
	 */
	public function setWebhookToken(string $token): void {
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

	}//end setWebhookToken()

	/**
	 * Build the JSON payload for the decidesk approval webhook.
	 *
	 * Pure-logic helper so callers and unit tests can inspect the
	 * payload without invoking the HTTP client. The shape mirrors
	 * the persisted LeaseReassessmentEvent so the decidesk side can
	 * link back via the `eventId` FK (REQ-LR-007).
	 *
	 * @param array<string,mixed> $event The persisted LeaseReassessmentEvent row.
	 *
	 * @return array<string,mixed> The webhook payload.
	 */
	public function buildPayload(array $event): array {
		return [
			'source' => Application::APP_ID,
			'kind' => 'lease-reassessment-approval-request',
			'eventId' => ($event['id'] ?? ($event['reassessmentNumber'] ?? null)),
			'reassessmentNumber' => ($event['reassessmentNumber'] ?? null),
			'eventType' => ($event['eventType'] ?? null),
			'leaseContractId' => ($event['sourceLease'] ?? ($event['lease'] ?? null)),
			'administrationId' => ($event['administrationId'] ?? null),
			'triggerDescription' => ($event['triggerDescription'] ?? ''),
			'preEventLiabilityCents' => ($event['preEventLiabilityCents'] ?? 0),
			'postEventLiabilityCents' => ($event['postEventLiabilityCents'] ?? 0),
			'rouAssetAdjustmentCents' => ($event['rouAssetAdjustmentCents'] ?? 0),
			'plImpactCents' => ($event['plImpactCents'] ?? 0),
			'remeasurementApproach' => ($event['remeasurementApproach'] ?? null),
			'approver' => ($event['approver'] ?? null),
			'requestedAt' => date(format: 'c'),
		];

	}//end buildPayload()

	/**
	 * Determine whether the given event should be delivered to decidesk.
	 *
	 * The reassessment service has already applied the materiality
	 * threshold; this method simply checks the status flag and the
	 * required webhook configuration so callers don't fire blank
	 * requests when the integration is disabled.
	 *
	 * @param array<string,mixed> $event The persisted event row.
	 *
	 * @return bool True when the event qualifies for delivery.
	 */
	public function shouldDeliver(array $event): bool {
		if (($event['status'] ?? null) !== 'pending-approval') {
			return false;
		}

		if ($this->getWebhookUrl() === '') {
			return false;
		}

		return true;
	}//end shouldDeliver()

	/**
	 * Deliver the approval webhook for a pending-approval event.
	 *
	 * Returns true on a 2xx response from decidesk; false on any
	 * non-2xx or transport-level error (the failure is logged but
	 * NEVER raised — the persisted event already carries the audit
	 * record and the operator can resend from the UI). The bearer
	 * token is sent in the `Authorization: Bearer <token>` header
	 * and never logged.
	 *
	 * @param array<string,mixed> $event The persisted event row to deliver.
	 *
	 * @return bool True on 2xx delivery, false otherwise.
	 */
	public function deliver(array $event): bool {
		if ($this->shouldDeliver(event: $event) === false) {
			return false;
		}

		$url = $this->getWebhookUrl();
		$token = $this->getWebhookToken();
		$payload = $this->buildPayload(event: $event);

		$headers = ['Content-Type' => 'application/json'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				$url,
				[
					'headers' => $headers,
					'body' => json_encode(value: $payload, flags: (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
					'timeout' => self::HTTP_TIMEOUT_SECONDS,
				]
			);

			$status = (int)$response->getStatusCode();
			if ($status >= 200 && $status < 300) {
				return true;
			}

			$this->logger->warning(
				'LeaseDecideskWebhookService: non-2xx response from decidesk',
				[
					'eventId' => ($event['id'] ?? ($event['reassessmentNumber'] ?? null)),
					'status' => $status,
				]
			);
			return false;
		} catch (Throwable $e) {
			// Fail soft: persist failure, do not raise.
			$this->logger->warning(
				'LeaseDecideskWebhookService: failed to deliver decidesk webhook',
				[
					'eventId' => ($event['id'] ?? ($event['reassessmentNumber'] ?? null)),
					'message' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end deliver()
}//end class
