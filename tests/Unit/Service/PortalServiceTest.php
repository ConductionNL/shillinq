<?php

/**
 * Unit tests for PortalService.
 *
 * @spec openspec/changes/general/tasks.md#task-13.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PortalService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalService.
 *
 * @spec openspec/changes/general/tasks.md#task-13.2
 */
class PortalServiceTest extends TestCase
{

    private ContainerInterface $container;

    private LoggerInterface $logger;

    private PortalService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->service   = new PortalService($this->container, $this->logger);
    }//end setUp()

    /**
     * Test that generateToken creates a verifiable hash.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testGenerateTokenCreatesVerifiableHash(): void
    {
        // Use an anonymous class mock that stores the hash for verification.
        $mockObjectService = new class {

            public ?string $lastHash = null;

            /**
             * Create object mock.
             *
             * @return array
             */
            public function createObject(string $schema = '', array $data = []): array
            {
                $this->lastHash = ($data['tokenHash'] ?? null);
                return array_merge(['id' => 'test-id'], $data);
            }//end createObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->generateToken('org-123', 'Test token');

        $this->assertArrayHasKey('rawToken', $result);
        $this->assertArrayHasKey('tokenObject', $result);
        $this->assertNotEmpty($result['rawToken']);

        // Verify the stored hash is verifiable with the raw token.
        $this->assertTrue(
            password_verify($result['rawToken'], $mockObjectService->lastHash),
            'The stored hash must be verifiable with the raw token'
        );
    }//end testGenerateTokenCreatesVerifiableHash()

    /**
     * Test that validateToken returns null for expired tokens.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testValidateTokenReturnsNullForExpiredToken(): void
    {
        $rawToken = base64_encode(random_bytes(32));
        $hash     = password_hash($rawToken, PASSWORD_DEFAULT);

        $mockObjectService = new class ($hash) {

            private string $hash;

            /**
             * Constructor.
             *
             * @param string $hash The hash to store.
             *
             * @return void
             */
            public function __construct(string $hash)
            {
                $this->hash = $hash;
            }//end __construct()

            /**
             * Get objects mock.
             *
             * @return array
             */
            public function getObjects(string $schema = '', array $filters = []): array
            {
                return [
                    [
                        'id'        => 'token-1',
                        'tokenHash' => $this->hash,
                        'isActive'  => true,
                        'expiresAt' => '2020-01-01T00:00:00+00:00',
                    ],
                ];
            }//end getObjects()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->validateToken($rawToken);

        $this->assertNull($result, 'Expired token must return null');
    }//end testValidateTokenReturnsNullForExpiredToken()

    /**
     * Test that validateToken returns null when no tokens match.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testValidateTokenReturnsNullForInvalidToken(): void
    {
        $mockObjectService = new class {

            /**
             * Get objects mock.
             *
             * @return array
             */
            public function getObjects(string $schema = '', array $filters = []): array
            {
                return [
                    [
                        'id'        => 'token-1',
                        'tokenHash' => password_hash('different-token', PASSWORD_DEFAULT),
                        'isActive'  => true,
                    ],
                ];
            }//end getObjects()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->validateToken('wrong-token');

        $this->assertNull($result, 'Invalid token must return null');
    }//end testValidateTokenReturnsNullForInvalidToken()

    /**
     * Test that validateToken returns the token object for a valid token.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testValidateTokenReturnsObjectForValidToken(): void
    {
        $rawToken = base64_encode(random_bytes(32));
        $hash     = password_hash($rawToken, PASSWORD_DEFAULT);

        $mockObjectService = new class ($hash) {

            private string $hash;

            /**
             * Constructor.
             *
             * @param string $hash The hash to store.
             *
             * @return void
             */
            public function __construct(string $hash)
            {
                $this->hash = $hash;
            }//end __construct()

            /**
             * Get objects mock.
             *
             * @return array
             */
            public function getObjects(string $schema = '', array $filters = []): array
            {
                return [
                    [
                        'id'             => 'token-1',
                        'tokenHash'      => $this->hash,
                        'isActive'       => true,
                        'organizationId' => 'org-456',
                    ],
                ];
            }//end getObjects()

            /**
             * Update object mock.
             *
             * @return void
             */
            public function updateObject(string $id = '', array $data = []): void
            {
                // No-op for test.
            }//end updateObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->validateToken($rawToken);

        $this->assertNotNull($result, 'Valid token must return the token object');
        $this->assertEquals('org-456', $result['organizationId']);
    }//end testValidateTokenReturnsObjectForValidToken()
}//end class
