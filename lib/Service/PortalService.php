<?php

/**
 * Shillinq Portal Service
 *
 * Handles token generation, validation, and scoped data retrieval for the
 * client/supplier self-service portal.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-11.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for portal token generation, validation, and scoped data retrieval.
 *
 * @spec openspec/changes/general/tasks.md#task-11.1
 */
class PortalService
{
    /**
     * Constructor for PortalService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a new portal token for the given organisation.
     *
     * Creates a 32-byte random token, stores its hash as a PortalToken object
     * in OpenRegister, and returns the raw base64 token for one-time display.
     *
     * @param string      $organizationId The organisation object ID.
     * @param string|null $description    Optional human label.
     * @param string|null $expiresAt      Optional expiry datetime string.
     * @param array       $permissions    Scoped permissions list.
     *
     * @return array{rawToken: string, tokenObject: array} The raw token and stored object.
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function generateToken(
        string $organizationId,
        ?string $description=null,
        ?string $expiresAt=null,
        array $permissions=[],
    ): array {
        $rawBytes = random_bytes(32);
        $rawToken = base64_encode($rawBytes);
        $hash     = password_hash($rawToken, PASSWORD_DEFAULT);

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $data = [
            'tokenHash'      => $hash,
            'organizationId' => $organizationId,
            'isActive'       => true,
            'permissions'    => $permissions,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        if ($expiresAt !== null) {
            $data['expiresAt'] = $expiresAt;
        }

        $tokenObject = $objectService->createObject(
            schema: 'PortalToken',
            data: $data,
        );

        $this->logger->info(
            'Portal token generated for organisation {orgId}',
            ['orgId' => $organizationId]
        );

        return [
            'rawToken'    => $rawToken,
            'tokenObject' => $tokenObject,
        ];
    }//end generateToken()

    /**
     * Validate a raw portal token.
     *
     * Iterates all active, non-expired PortalToken objects and checks
     * password_verify() against each tokenHash. Returns the matching
     * PortalToken or null.
     *
     * @param string $rawToken The raw base64-encoded token.
     *
     * @return array|null The matching PortalToken object or null.
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function validateToken(string $rawToken): ?array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $tokens = $objectService->getObjects(
            schema: 'PortalToken',
            filters: ['isActive' => true],
        );

        $now = new DateTimeImmutable();

        foreach ($tokens as $token) {
            // Skip expired tokens.
            if (empty($token['expiresAt']) === false) {
                $expiry = new DateTimeImmutable($token['expiresAt']);
                if ($expiry < $now) {
                    continue;
                }
            }

            if (password_verify($rawToken, $token['tokenHash']) === true) {
                // Update lastUsedAt.
                $objectService->updateObject(
                    id: $token['id'],
                    data: ['lastUsedAt' => $now->format('c')],
                );

                return $token;
            }
        }//end foreach

        return null;
    }//end validateToken()

    /**
     * Get invoices scoped to the given organisation ID.
     *
     * @param string $organizationId The organisation object ID.
     *
     * @return array List of invoice objects.
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function getScopedInvoices(string $organizationId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        return $objectService->getObjects(
            schema: 'Invoice',
            filters: ['organizationId' => $organizationId],
        );
    }//end getScopedInvoices()

    /**
     * Get payments scoped to the given organisation ID.
     *
     * @param string $organizationId The organisation object ID.
     *
     * @return array List of payment objects.
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function getScopedPayments(string $organizationId): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        return $objectService->getObjects(
            schema: 'Payment',
            filters: ['organizationId' => $organizationId],
        );
    }//end getScopedPayments()
}//end class
