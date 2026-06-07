<?php

/**
 * Unit tests for WidgetAuthService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WidgetAuthService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers API-key generation/hashing/validation (REQ-WSW-009), the 7-day
 * rotation grace window, revocation, and per-business rate-limiting (REQ-WSW-001).
 */
class WidgetAuthServiceTest extends TestCase
{
    /**
     * Mock container.
     *
     * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

    /**
     * Mock cache factory.
     *
     * @var ICacheFactory&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheFactory;

    /**
     * The service under test.
     *
     * @var WidgetAuthService
     */
    private WidgetAuthService $service;

    /**
     * Build the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container    = $this->createMock(ContainerInterface::class);
        $appConfig          = $this->createMock(IAppConfig::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $secureRandom       = $this->createMock(ISecureRandom::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $appConfig->method('getValueString')->willReturn('shillinq');
        $secureRandom->method('generate')->willReturn('abcdefghijklmnopqrstuvwxyz012345');

        $this->service = new WidgetAuthService(
            container: $this->container,
            appConfig: $appConfig,
            cacheFactory: $this->cacheFactory,
            secureRandom: $secureRandom,
            logger: $logger,
        );

    }//end setUp()

    /**
     * Generated keys carry the bk_live_ prefix; the hash verifies the plaintext.
     *
     * @return void
     */
    public function testGenerateAndHashRoundTrip(): void
    {
        $plaintext = $this->service->generatePlaintextKey();
        self::assertStringStartsWith('bk_live_', $plaintext);

        $hash = $this->service->hashKey($plaintext);
        self::assertTrue(password_verify($plaintext, $hash));
        self::assertFalse(password_verify('wrong-key', $hash));

    }//end testGenerateAndHashRoundTrip()

    /**
     * A valid active key authenticates; a wrong key and a revoked key do not.
     *
     * @return void
     */
    public function testValidateApiKey(): void
    {
        $hash = password_hash('bk_live_secret', PASSWORD_DEFAULT);

        $activeRecord = [
            'businessId' => 'salon-demo',
            'apiKeyHash' => $hash,
            'isActive'   => true,
            'revokedAt'  => null,
        ];
        $this->container->method('get')->willReturn($this->objectServiceReturning([$activeRecord]));

        self::assertTrue($this->service->validateApiKey('salon-demo', 'bk_live_secret'));
        self::assertFalse($this->service->validateApiKey('salon-demo', 'bk_live_wrong'));
        self::assertFalse($this->service->validateApiKey('', 'bk_live_secret'));

    }//end testValidateApiKey()

    /**
     * A revoked key never authenticates even with the correct secret.
     *
     * @return void
     */
    public function testRevokedKeyDoesNotAuthenticate(): void
    {
        $hash   = password_hash('bk_live_secret', PASSWORD_DEFAULT);
        $record = [
            'businessId' => 'salon-demo',
            'apiKeyHash' => $hash,
            'isActive'   => false,
            'revokedAt'  => '2026-05-01T00:00:00Z',
        ];
        $this->container->method('get')->willReturn($this->objectServiceReturning([$record]));

        self::assertFalse($this->service->validateApiKey('salon-demo', 'bk_live_secret'));

    }//end testRevokedKeyDoesNotAuthenticate()

    /**
     * The previous key authenticates within, but not after, the 7-day grace window.
     *
     * @return void
     */
    public function testRotationGraceWindow(): void
    {
        $prevHash = password_hash('bk_live_old', PASSWORD_DEFAULT);
        $newHash  = password_hash('bk_live_new', PASSWORD_DEFAULT);

        // Rotated 1 day ago — old key still valid.
        $within = [
            'businessId'         => 'salon-demo',
            'apiKeyHash'         => $newHash,
            'previousApiKeyHash' => $prevHash,
            'rotatedAt'          => gmdate('Y-m-d\TH:i:s\Z', (time() - 86400)),
            'isActive'           => true,
            'revokedAt'          => null,
        ];
        $this->container->method('get')->willReturn($this->objectServiceReturning([$within]));

        self::assertTrue($this->service->validateApiKey('salon-demo', 'bk_live_new'), 'new key works');
        self::assertTrue($this->service->validateApiKey('salon-demo', 'bk_live_old'), 'old key valid in grace');

    }//end testRotationGraceWindow()

    /**
     * The previous key is rejected once the 7-day grace window has elapsed.
     *
     * @return void
     */
    public function testRotationGraceWindowExpires(): void
    {
        $prevHash = password_hash('bk_live_old', PASSWORD_DEFAULT);
        $newHash  = password_hash('bk_live_new', PASSWORD_DEFAULT);

        // Rotated 8 days ago — old key no longer valid.
        $expired = [
            'businessId'         => 'salon-demo',
            'apiKeyHash'         => $newHash,
            'previousApiKeyHash' => $prevHash,
            'rotatedAt'          => gmdate('Y-m-d\TH:i:s\Z', (time() - (8 * 86400))),
            'isActive'           => true,
            'revokedAt'          => null,
        ];
        $this->container->method('get')->willReturn($this->objectServiceReturning([$expired]));

        self::assertTrue($this->service->validateApiKey('salon-demo', 'bk_live_new'));
        self::assertFalse($this->service->validateApiKey('salon-demo', 'bk_live_old'), 'old key expired');

    }//end testRotationGraceWindowExpires()

    /**
     * Rate-limiting permits the first N requests and blocks the (N+1)th in a minute.
     *
     * @return void
     */
    public function testRateLimitingBlocksAfterLimit(): void
    {
        $store = [];
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            static function ($key) use (&$store) {
                return $store[$key] ?? null;
            }
        );
        $cache->method('set')->willReturnCallback(
            static function ($key, $value, $ttl=0) use (&$store) {
                $store[$key] = $value;
                return true;
            }
        );

        $this->cacheFactory->method('isAvailable')->willReturn(true);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $limit = 3;
        self::assertTrue($this->service->registerRequest('salon-demo', $limit));
        self::assertTrue($this->service->registerRequest('salon-demo', $limit));
        self::assertTrue($this->service->registerRequest('salon-demo', $limit));
        self::assertFalse($this->service->registerRequest('salon-demo', $limit), '4th request over limit 3');

    }//end testRateLimitingBlocksAfterLimit()

    /**
     * Build a fluent ObjectService stub whose findAll() returns the given records.
     *
     * @param array<int,array<string,mixed>> $records The WidgetAccessKey records.
     *
     * @return object
     */
    private function objectServiceReturning(array $records): object
    {
        return new class($records) {
            /** @var array<int,array<string,mixed>> */
            private array $records;

            /**
             * @param array<int,array<string,mixed>> $records
             */
            public function __construct(array $records)
            {
                $this->records = $records;
            }

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->records;
            }
        };

    }//end objectServiceReturning()
}//end class
