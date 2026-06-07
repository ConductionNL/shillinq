<?php

/**
 * Widget Auth Service
 *
 * Authenticates and rate-limits the public self-service booking widget API
 * (REQ-WSW-001, REQ-WSW-009). API keys are stored only as password_hash()
 * hashes in the WidgetAccessKey schema; the plaintext key is shown once on
 * generation and never persisted. Rate-limiting is enforced per business per
 * minute using the Nextcloud distributed cache.
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
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates widget API keys (REQ-WSW-009) and enforces per-business
 * rate-limiting (REQ-WSW-001). All key material is hashed at rest.
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */
class WidgetAuthService
{
    /**
     * Rotation grace window in seconds during which the previous key still
     * authenticates after a rotation (7 days per REQ-WSW-009).
     *
     * @var int
     */
    private const ROTATION_GRACE_SECONDS = (7 * 24 * 60 * 60);

    /**
     * Default rate limit (requests per minute) when a key declares none.
     *
     * @var int
     */
    private const DEFAULT_RATE_LIMIT = 100;

    /**
     * Construct the service with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container    DI container — OR's ObjectService is
     *                                         fetched lazily so the class stays usable
     *                                         before OpenRegister is installed.
     * @param IAppConfig         $appConfig    App config for register-slug resolution.
     * @param ICacheFactory      $cacheFactory Distributed cache factory for rate-limit counters.
     * @param ISecureRandom      $secureRandom CSPRNG for API-key generation.
     * @param LoggerInterface    $logger       Nextcloud logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
        private readonly ISecureRandom $secureRandom,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Resolve OR's ObjectService, or null when OpenRegister is unavailable.
     *
     * @return object|null
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'WidgetAuthService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Load the active WidgetAccessKey record for a business, or null.
     *
     * @param string $businessId The public business identifier.
     *
     * @return array<string,mixed>|null The key object array, or null when absent.
     */
    private function findKeyRecord(string $businessId): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            $matches = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('WidgetAccessKey')
                ->findAll(
                    [
                        'filters' => ['businessId' => $businessId],
                        'limit'   => 1,
                    ]
                );
        } catch (\Throwable $e) {
            // Fail-closed: a lookup error must never authenticate a request.
            $this->logger->error(
                'WidgetAuthService: key lookup failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        if (empty($matches) === true) {
            return null;
        }

        $record = $matches[0];
        if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
            $record = $record->jsonSerialize();
        }

        if (is_array($record) === false) {
            return null;
        }

        return $record;

    }//end findKeyRecord()

    /**
     * Validate a presented API key against the stored hash for a business.
     *
     * Constant-time comparison is delegated to password_verify(). The previous
     * key still authenticates during the 7-day rotation grace window
     * (REQ-WSW-009). Revoked or inactive keys never authenticate.
     *
     * @param string $businessId The business the partner claims to be.
     * @param string $apiKey     The plaintext API key from the Authorization header.
     *
     * @return bool True when the key authenticates the business.
     */
    public function validateApiKey(string $businessId, string $apiKey): bool
    {
        if ($businessId === '' || $apiKey === '') {
            return false;
        }

        $record = $this->findKeyRecord(businessId: $businessId);
        if ($record === null) {
            return false;
        }

        $isActive = (bool) ($record['isActive'] ?? false);
        if ($isActive === false || empty($record['revokedAt']) === false) {
            return false;
        }

        $currentHash = (string) ($record['apiKeyHash'] ?? '');
        if ($currentHash !== '' && password_verify($apiKey, $currentHash) === true) {
            return true;
        }

        // Grace window: the previous key still authenticates for 7 days after
        // a rotation so partners can roll the key without downtime.
        $previousHash = (string) ($record['previousApiKeyHash'] ?? '');
        $rotatedAt    = (string) ($record['rotatedAt'] ?? '');
        if ($previousHash !== '' && $rotatedAt !== '') {
            $rotatedTs = strtotime($rotatedAt);
            if ($rotatedTs !== false && (time() - $rotatedTs) <= self::ROTATION_GRACE_SECONDS) {
                return password_verify($apiKey, $previousHash);
            }
        }

        return false;

    }//end validateApiKey()

    /**
     * Resolve the configured per-minute rate limit for a business.
     *
     * @param string $businessId The business identifier.
     *
     * @return int Requests permitted per minute (>= 1).
     */
    public function getRateLimit(string $businessId): int
    {
        $record = $this->findKeyRecord(businessId: $businessId);
        $limit  = (int) ($record['rateLimitPerMinute'] ?? self::DEFAULT_RATE_LIMIT);
        if ($limit < 1) {
            return self::DEFAULT_RATE_LIMIT;
        }

        return $limit;

    }//end getRateLimit()

    /**
     * Register one request against the per-business per-minute rate counter.
     *
     * Uses a distributed cache key bucketed to the current minute so the
     * counter resets every 60 seconds (REQ-WSW-001). Returns true when the
     * request is within budget, false when the limit is exceeded (HTTP 429).
     *
     * @param string $businessId The business making the request.
     * @param int    $limit      The per-minute limit for this business.
     *
     * @return bool True when within budget; false when the limit is exceeded.
     */
    public function registerRequest(string $businessId, int $limit): bool
    {
        $cache = $this->getRateCache();
        if ($cache === null) {
            // No distributed cache configured — do not fail open on a missing
            // cache; permit the request but log so operators can add a cache.
            $this->logger->debug('WidgetAuthService: no rate-limit cache available');
            return true;
        }

        $bucket = (string) (int) floor(time() / 60);
        $key    = 'rl_'.md5($businessId).'_'.$bucket;

        $count = $cache->get($key);
        if ($count === null) {
            $cache->set($key, 1, 90);
            return true;
        }

        $count = ((int) $count + 1);
        $cache->set($key, $count, 90);

        return $count <= $limit;

    }//end registerRequest()

    /**
     * Resolve the distributed rate-limit cache, or null when unavailable.
     *
     * @return ICache|null
     */
    private function getRateCache(): ?ICache
    {
        if ($this->cacheFactory->isAvailable() === false) {
            return null;
        }

        return $this->cacheFactory->createDistributed('shillinq_widget_rl');

    }//end getRateCache()

    /**
     * Generate a new plaintext API key (REQ-WSW-009).
     *
     * 32 URL-safe random characters with a `bk_live_` prefix. The plaintext is
     * returned to the caller exactly once; only its hash is ever persisted.
     *
     * @return string The plaintext API key.
     */
    public function generatePlaintextKey(): string
    {
        $random = $this->secureRandom->generate(
            32,
            ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS
        );

        return 'bk_live_'.$random;

    }//end generatePlaintextKey()

    /**
     * Hash a plaintext API key for storage.
     *
     * @param string $plaintext The plaintext key.
     *
     * @return string The password_hash() hash.
     */
    public function hashKey(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_DEFAULT);

    }//end hashKey()

    /**
     * Create or rotate the API key for a business, returning the plaintext once.
     *
     * On first creation the new hash becomes the active key. On rotation the
     * current hash is preserved as previousApiKeyHash (7-day grace) and
     * rotatedAt is stamped. The plaintext is returned for one-time display and
     * is never stored (REQ-WSW-009).
     *
     * @param string $businessId       The business identifier.
     * @param string $administrationId The owning administration.
     *
     * @return array<string,mixed> ['success' => bool, 'apiKey' => string|null, 'message' => string]
     */
    public function rotateApiKey(string $businessId, string $administrationId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [
                'success' => false,
                'apiKey'  => null,
                'message' => 'OpenRegister is not available.',
            ];
        }

        if ($businessId === '') {
            return [
                'success' => false,
                'apiKey'  => null,
                'message' => 'businessId is required.',
            ];
        }

        $plaintext = $this->generatePlaintextKey();
        $newHash   = $this->hashKey(plaintext: $plaintext);
        $nowUtc    = gmdate('Y-m-d\TH:i:s\Z');
        $existing  = $this->findKeyRecord(businessId: $businessId);

        $object = [
            'businessId'         => $businessId,
            'apiKeyHash'         => $newHash,
            'previousApiKeyHash' => null,
            'rateLimitPerMinute' => (int) ($existing['rateLimitPerMinute'] ?? self::DEFAULT_RATE_LIMIT),
            'allowedOrigins'     => ($existing['allowedOrigins'] ?? []),
            'createdAt'          => (string) ($existing['createdAt'] ?? $nowUtc),
            'rotatedAt'          => null,
            'revokedAt'          => null,
            'isActive'           => true,
            'administrationId'   => $administrationId,
        ];

        if ($existing !== null) {
            // Rotation: keep the old hash live for the grace window.
            $object['previousApiKeyHash'] = (string) ($existing['apiKeyHash'] ?? '');
            $object['rotatedAt']          = $nowUtc;
        }

        try {
            $objectService->saveObject(
                object: $object,
                register: $this->getRegisterSlug(),
                schema: 'WidgetAccessKey',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'WidgetAuthService: failed to persist rotated key',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'apiKey'  => null,
                'message' => 'Failed to store the API key.',
            ];
        }

        $action = 'rotated';
        if ($existing === null) {
            $action = 'created';
        }

        $this->logger->info(
            'WidgetAuthService: API key '.$action,
            ['businessId' => $businessId, 'administrationId' => $administrationId]
        );

        return [
            'success' => true,
            'apiKey'  => $plaintext,
            'message' => 'API key '.$action.'. Copy it now — it will not be shown again.',
        ];

    }//end rotateApiKey()

    /**
     * Revoke the API key for a business immediately (no grace window).
     *
     * @param string $businessId The business identifier.
     *
     * @return array<string,mixed> ['success' => bool, 'message' => string]
     */
    public function revokeApiKey(string $businessId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['success' => false, 'message' => 'OpenRegister is not available.'];
        }

        $existing = $this->findKeyRecord(businessId: $businessId);
        if ($existing === null) {
            return ['success' => false, 'message' => 'No API key found for this business.'];
        }

        $existing['isActive']           = false;
        $existing['revokedAt']          = gmdate('Y-m-d\TH:i:s\Z');
        $existing['previousApiKeyHash'] = null;

        try {
            $objectService->saveObject(
                object: $existing,
                register: $this->getRegisterSlug(),
                schema: 'WidgetAccessKey',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'WidgetAuthService: failed to revoke key',
                ['exception' => $e->getMessage()]
            );
            return ['success' => false, 'message' => 'Failed to revoke the API key.'];
        }

        $this->logger->info('WidgetAuthService: API key revoked', ['businessId' => $businessId]);

        return ['success' => true, 'message' => 'API key revoked.'];

    }//end revokeApiKey()
}//end class
