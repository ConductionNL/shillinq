<?php

/**
 * Widget Auth Service
 *
 * Authenticates partner requests to the Booking Self-service Widget API
 * via business_id + API key pairs (REQ-WSW-001 / REQ-WSW-009) and enforces
 * the per-business rate limit (REQ-WSW-001 / rate-limit policy).
 *
 * Keys are bcrypt-hashed at rest; plaintext is shown to the partner once at
 * creation and never persisted. Rotation creates a new key while leaving the
 * predecessor active for 7 days as a grace period; revocation is immediate
 * and terminal. All lifecycle operations write a WidgetAccessKeyAuditEntry.
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
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * API key + rate-limit gateway for the Booking Self-service Widget endpoints.
 *
 * Fail-closed semantics per ADR-005: every uncertain path returns false /
 * "rate limited" so a misconfigured cache or missing OR adapter cannot make
 * the endpoints publicly reachable.
 *
 * The class deliberately keeps the bcrypt comparison + rate-limit + audit
 * trail concerns in one cohesive collaborator; splitting would add indirection
 * without lowering real branch count.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WidgetAuthService {

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
	 * Bcrypt cost factor used when hashing new API keys.
	 */
	private const BCRYPT_COST = 10;

	/**
	 * Length, in bytes, of the random source used to mint plaintext keys.
	 * 24 raw bytes produces a 32-character base64 representation per
	 * REQ-WSW-009.
	 */
	private const KEY_RANDOM_BYTES = 24;

	/**
	 * Prefix used to brand minted plaintext keys for partner-facing display.
	 */
	private const KEY_PREFIX = 'bk_live_';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ICacheFactory $cacheFactory Distributed cache factory (rate-limit counters).
	 * @param ITimeFactory $time Time provider (testable now()).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ICacheFactory $cacheFactory,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 */
	public function validateApiKey(string $businessId, string $apiKey): array {
		if ($businessId === '' || $apiKey === '') {
			return ['valid' => false, 'error' => 'Invalid or missing API key'];
		}

		$record = $this->findActiveKeyByBusinessId(businessId: $businessId);
		if ($record === null) {
			$this->logger->info(
				'Shillinq widget auth: no active key for business',
				['businessId' => $businessId]
			);
			return ['valid' => false, 'error' => 'Invalid or missing API key'];
		}

		$hash = (string)($record['apiKeyHash'] ?? '');
		if ($hash === '') {
			return ['valid' => false, 'error' => 'Invalid or missing API key'];
		}

		if (password_verify($apiKey, $hash) !== true) {
			$this->logger->info(
				'Shillinq widget auth: bearer token does not match active hash',
				['businessId' => $businessId]
			);
			return ['valid' => false, 'error' => 'Invalid or missing API key'];
		}

		$status = (string)($record['status'] ?? '');
		if ($status === 'revoked') {
			return ['valid' => false, 'error' => 'Invalid or missing API key'];
		}

		return ['valid' => true, 'key' => $record];
	}//end validateApiKey()

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
	 */
	public function consumeRateLimit(string $businessId, int $limit = self::DEFAULT_RATE_LIMIT): array {
		$cache = $this->getRateLimitCache();
		if ($cache === null) {
			$this->logger->warning(
				'Shillinq widget auth: rate-limit cache unavailable, failing closed'
			);
			return [
				'allowed' => false,
				'remaining' => 0,
				'retryAfter' => self::RATE_LIMIT_WINDOW_SECONDS,
			];
		}

		$window = ((int)floor($this->time->getTime() / self::RATE_LIMIT_WINDOW_SECONDS));
		$cacheKey = 'widget-rl:' . $businessId . ':' . $window;
		$currentRaw = $cache->get($cacheKey);
		$current = ((int)($currentRaw ?? 0));
		$next = ($current + 1);
		$retryAfter = self::RATE_LIMIT_WINDOW_SECONDS;

		if ($next > $limit) {
			return [
				'allowed' => false,
				'remaining' => 0,
				'retryAfter' => $retryAfter,
			];
		}

		$cache->set($cacheKey, $next, self::RATE_LIMIT_WINDOW_SECONDS);

		$remaining = ($limit - $next);
		if ($remaining < 0) {
			$remaining = 0;
		}

		return [
			'allowed' => true,
			'remaining' => $remaining,
			'retryAfter' => $retryAfter,
		];

	}//end consumeRateLimit()

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
	 */
	public function createApiKey(string $administrationId, string $businessId, string $actor): array {
		if ($administrationId === '' || $businessId === '') {
			return ['success' => false, 'message' => 'administrationId and businessId are required.'];
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return ['success' => false, 'message' => 'OpenRegister is not available.'];
		}

		$existing = $this->findActiveKeyByBusinessId(businessId: $businessId);
		if ($existing !== null) {
			return ['success' => false, 'message' => 'An active key already exists for this businessId.'];
		}

		$plaintext = $this->generatePlaintextKey();
		$hash = $this->hashKey(plaintext: $plaintext);
		$prefix = substr($plaintext, 0, 8);

		$record = [
			'administrationId' => $administrationId,
			'businessId' => $businessId,
			'apiKeyHash' => $hash,
			'apiKeyPrefix' => $prefix,
			'rateLimit' => self::DEFAULT_RATE_LIMIT,
			'allowedOrigins' => [],
			'createdAt' => gmdate('c', $this->time->getTime()),
			'status' => 'active',
		];

		$saved = $this->saveObject(schema: 'WidgetAccessKey', data: $record);
		if ($saved === null) {
			return ['success' => false, 'message' => 'Failed to persist WidgetAccessKey.'];
		}

		$this->writeAudit(
			administrationId: $administrationId,
			businessId: $businessId,
			action: 'created',
			actor: $actor
		);

		return [
			'success' => true,
			'businessId' => $businessId,
			'apiKey' => $plaintext,
			'record' => $saved,
		];

	}//end createApiKey()

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
	 */
	public function rotateApiKey(string $businessId, string $actor): array {
		$existing = $this->findActiveKeyByBusinessId(businessId: $businessId);
		if ($existing === null) {
			return ['success' => false, 'message' => 'No active key found for businessId.'];
		}

		$administrationId = (string)($existing['administrationId'] ?? '');
		if ($administrationId === '') {
			return ['success' => false, 'message' => 'Active key has no administrationId.'];
		}

		// Transition predecessor to rotating (7-day grace period).
		$previousId = ($existing['id'] ?? null);
		if ($previousId !== null) {
			$existing['status'] = 'rotating';
			$existing['rotatedAt'] = gmdate('c', $this->time->getTime());
			$this->updateObject(schema: 'WidgetAccessKey', id: (string)$previousId, data: $existing);
		}

		$plaintext = $this->generatePlaintextKey();
		$hash = $this->hashKey(plaintext: $plaintext);
		$prefix = substr($plaintext, 0, 8);

		$record = [
			'administrationId' => $administrationId,
			'businessId' => $businessId,
			'apiKeyHash' => $hash,
			'apiKeyPrefix' => $prefix,
			'rateLimit' => ((int)($existing['rateLimit'] ?? self::DEFAULT_RATE_LIMIT)),
			'allowedOrigins' => ($existing['allowedOrigins'] ?? []),
			'createdAt' => gmdate('c', $this->time->getTime()),
			'status' => 'active',
		];

		$saved = $this->saveObject(schema: 'WidgetAccessKey', data: $record);
		if ($saved === null) {
			return ['success' => false, 'message' => 'Failed to persist rotated WidgetAccessKey.'];
		}

		$this->writeAudit(
			administrationId: $administrationId,
			businessId: $businessId,
			action: 'rotated',
			actor: $actor
		);

		return [
			'success' => true,
			'businessId' => $businessId,
			'apiKey' => $plaintext,
		];

	}//end rotateApiKey()

	/**
	 * Administratively revoke an API key.
	 *
	 * Idempotent: re-revoking an already-revoked key is a no-op success.
	 *
	 * @param string $businessId Partner business id whose key is being revoked.
	 * @param string $actor Nextcloud user id performing the revocation.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public function revokeApiKey(string $businessId, string $actor): array {
		$record = $this->findKeyByBusinessId(businessId: $businessId);
		if ($record === null) {
			return ['success' => false, 'message' => 'No WidgetAccessKey found for businessId.'];
		}

		if (((string)($record['status'] ?? '')) === 'revoked') {
			return ['success' => true, 'message' => 'Already revoked.'];
		}

		$recordId = ($record['id'] ?? null);
		if ($recordId === null) {
			return ['success' => false, 'message' => 'Record is missing id.'];
		}

		$record['status'] = 'revoked';
		$record['revokedAt'] = gmdate('c', $this->time->getTime());
		$this->updateObject(schema: 'WidgetAccessKey', id: (string)$recordId, data: $record);

		$this->writeAudit(
			administrationId: ((string)($record['administrationId'] ?? '')),
			businessId: $businessId,
			action: 'revoked',
			actor: $actor
		);

		return ['success' => true];
	}//end revokeApiKey()

	/**
	 * Resolve the active WidgetAccessKey record for a businessId.
	 *
	 * Returns the row whose status is either 'active' or 'rotating' (the
	 * predecessor key is still honoured during its 7-day grace period).
	 * Null when no honourable key exists.
	 *
	 * @param string $businessId Partner business id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findActiveKeyByBusinessId(string $businessId): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget auth: ObjectService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		try {
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('WidgetAccessKey')
				->findAll(
					[
						'filters' => ['businessId' => $businessId],
						'limit' => 25,
					]
				);

			foreach ($records as $candidate) {
				$row = $this->toArray(object: $candidate);
				$status = (string)($row['status'] ?? '');
				if ($status === 'active' || $status === 'rotating') {
					return $row;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget auth: lookup failed',
				['exception' => $e->getMessage()]
			);
		}//end try

		return null;
	}//end findActiveKeyByBusinessId()

	/**
	 * Resolve any WidgetAccessKey record for a businessId regardless of status.
	 *
	 * @param string $businessId Partner business id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findKeyByBusinessId(string $businessId): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('WidgetAccessKey')
				->findAll(
					[
						'filters' => ['businessId' => $businessId],
						'limit' => 1,
					]
				);
			foreach ($records as $candidate) {
				return $this->toArray(object: $candidate);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget auth: lookup failed',
				['exception' => $e->getMessage()]
			);
		}//end try

		return null;
	}//end findKeyByBusinessId()

	/**
	 * Hash a plaintext key with bcrypt.
	 *
	 * @param string $plaintext Plaintext key.
	 *
	 * @return string
	 */
	public function hashKey(string $plaintext): string {
		return (string)password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
	}//end hashKey()

	/**
	 * Generate a base64 32-character plaintext key with the `bk_live_` prefix.
	 *
	 * @return string
	 */
	public function generatePlaintextKey(): string {
		$raw = random_bytes(self::KEY_RANDOM_BYTES);
		$token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
		$merged = (self::KEY_PREFIX . $token);

		return $merged;
	}//end generatePlaintextKey()

	/**
	 * Persist a WidgetAccessKeyAuditEntry.
	 *
	 * Best-effort: a failure to write the audit entry is logged but does not
	 * undo the lifecycle operation that triggered it. The key operation is
	 * still observable via the lifecycle field on the WidgetAccessKey record.
	 *
	 * @param string $administrationId Tenant id.
	 * @param string $businessId businessId of the affected key.
	 * @param string $action One of 'created' | 'rotated' | 'revoked'.
	 * @param string $actor Nextcloud user id performing the action.
	 *
	 * @return void
	 */
	private function writeAudit(string $administrationId, string $businessId, string $action, string $actor): void {
		$entry = [
			'administrationId' => $administrationId,
			'businessId' => $businessId,
			'action' => $action,
			'actor' => $actor,
			'occurredAt' => gmdate('c', $this->time->getTime()),
		];

		$this->saveObject(schema: 'WidgetAccessKeyAuditEntry', data: $entry);

	}//end writeAudit()

	/**
	 * Resolve the rate-limit cache (distributed when available, in-memory fallback).
	 *
	 * @return ICache|null
	 */
	private function getRateLimitCache(): ?ICache {
		try {
			if ($this->cacheFactory->isLocalCacheAvailable() === true) {
				return $this->cacheFactory->createLocal('shillinq-widget-rl');
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq widget auth: cache factory threw',
				['exception' => $e->getMessage()]
			);
		}

		return null;
	}//end getRateLimitCache()

	/**
	 * Save an object via OR ObjectService and return the persisted array.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $data Payload to persist.
	 *
	 * @return array<string,mixed>|null
	 */
	private function saveObject(string $schema, array $data): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$saved = $objectService->saveObject(
				object: $data,
				register: $registerSlug,
				schema: $schema,
			);

			return $this->toArray(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget auth: saveObject failed',
				[
					'schema' => $schema,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}

	}//end saveObject()

	/**
	 * Update an object via OR ObjectService.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $id Persisted object id.
	 * @param array<string,mixed> $data Updated payload.
	 *
	 * @return array<string,mixed>|null
	 */
	private function updateObject(string $schema, string $id, array $data): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$saved = $objectService->updateObject(
				id: $id,
				object: $data,
				register: $registerSlug,
				schema: $schema,
			);

			return $this->toArray(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq widget auth: updateObject failed',
				[
					'schema' => $schema,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end updateObject()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object Either an array or an OR entity exposing jsonSerialize().
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			/*
			 * @var array<string,mixed> $object
			 */

			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				/*
				 * @var array<string,mixed> $serialised
				 */

				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
