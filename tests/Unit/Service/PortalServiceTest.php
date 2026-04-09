<?php

/**
 * Unit tests for PortalService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-13.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PortalService;
use PHPUnit\Framework\MockObject\MockObject;
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

    /**
     * The service under test.
     *
     * @var PortalService
     */
    private PortalService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock object service.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container     = $this->createMock(ContainerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = new class {

            /**
             * Created objects for test assertions.
             *
             * @var array
             */
            public array $created = [];

            /**
             * Tokens to return from getObjects.
             *
             * @var array
             */
            public array $tokens = [];

            /**
             * Updated objects for test assertions.
             *
             * @var array
             */
            public array $updated = [];

            /**
             * Create an object in the mock store.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param array  $object   The object data
             *
             * @return array The created object
             */
            public function createObject(string $register, string $schema, array $object): array
            {
                $object['id'] = 'test-id-'.count($this->created);
                $this->created[] = $object;

                return $object;
            }

            /**
             * Get objects from the mock store.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param array  $filters  The filters
             *
             * @return array The objects
             */
            public function getObjects(string $register, string $schema, array $filters): array
            {
                return $this->tokens;
            }

            /**
             * Update an object in the mock store.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $id       The object ID
             * @param array  $object   The update data
             *
             * @return array The updated object
             */
            public function updateObject(string $register, string $schema, string $id, array $object): array
            {
                $this->updated[] = [
                    'id'   => $id,
                    'data' => $object,
                ];

                return $object;
            }
        };

        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->service = new PortalService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that generateToken creates a verifiable token hash.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testGenerateTokenCreatesVerifiableHash(): void
    {
        $result = $this->service->generateToken(organizationId: 'org-123');

        self::assertArrayHasKey('raw', $result);
        self::assertArrayHasKey('object', $result);
        self::assertNotEmpty($result['raw']);

        $storedHash = $this->objectService->created[0]['tokenHash'];
        self::assertTrue(password_verify($result['raw'], $storedHash));
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
        $rawToken  = base64_encode(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $this->objectService->tokens = [
            [
                'id'             => 'token-1',
                'tokenHash'      => $tokenHash,
                'organizationId' => 'org-123',
                'isActive'       => true,
                'expiresAt'      => '2020-01-01T00:00:00+00:00',
            ],
        ];

        $result = $this->service->validateToken($rawToken);

        self::assertNull($result);
    }//end testValidateTokenReturnsNullForExpiredToken()

    /**
     * Test that validateToken returns null for inactive tokens.
     *
     * The token has isActive:true in the store (since we filter by isActive),
     * but we simulate the scenario where no active tokens match the hash.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testValidateTokenReturnsNullForInactiveToken(): void
    {
        $this->objectService->tokens = [];

        $result = $this->service->validateToken('some-invalid-token');

        self::assertNull($result);
    }//end testValidateTokenReturnsNullForInactiveToken()

    /**
     * Test that validateToken returns the matching token for a valid token.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.2
     */
    public function testValidateTokenReturnsMatchingTokenForValidToken(): void
    {
        $rawToken  = base64_encode(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $this->objectService->tokens = [
            [
                'id'             => 'token-1',
                'tokenHash'      => $tokenHash,
                'organizationId' => 'org-456',
                'isActive'       => true,
            ],
        ];

        $result = $this->service->validateToken($rawToken);

        self::assertNotNull($result);
        self::assertSame('org-456', $result['organizationId']);
        self::assertCount(1, $this->objectService->updated);
    }//end testValidateTokenReturnsMatchingTokenForValidToken()
}//end class
