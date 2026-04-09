<?php

/**
 * Shillinq Portal Service
 *
 * Handles token generation, validation, and scoped data retrieval for portal access.
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for portal token management and scoped data retrieval.
 *
 * @spec openspec/changes/general/tasks.md#task-11.1
 */
class PortalService
{
    /**
     * Constructor for PortalService.
     *
     * @param ContainerInterface $container The service container
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
     * Generate a new portal token for an organisation.
     *
     * Creates a 32-byte random token, stores its hash as a PortalToken object,
     * and returns the raw base64-encoded token for one-time display.
     *
     * @param string      $organizationId The organisation's OpenRegister object ID
     * @param string|null $description    Human-readable label for the token
     * @param string|null $expiresAt      Optional expiry datetime string
     * @param array       $permissions    Scoped permissions array
     *
     * @return array{raw: string, object: array} The raw token and stored object
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function generateToken(
        string $organizationId,
        ?string $description=null,
        ?string $expiresAt=null,
        array $permissions=[],
    ): array {
        $rawBytes  = random_bytes(32);
        $rawToken  = base64_encode($rawBytes);
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $objectService = $this->getObjectService();

        $tokenData = [
            'tokenHash'      => $tokenHash,
            'organizationId' => $organizationId,
            'description'    => ($description ?? ''),
            'isActive'       => true,
            'permissions'    => $permissions,
        ];

        if ($expiresAt !== null) {
            $tokenData['expiresAt'] = $expiresAt;
        }

        $object = $objectService->createObject(
            register: 'shillinq',
            schema: 'PortalToken',
            object: $tokenData,
        );

        return [
            'raw'    => $rawToken,
            'object' => $object,
        ];
    }//end generateToken()

    /**
     * Validate a raw portal token and return the matching PortalToken object.
     *
     * Iterates all active, non-expired tokens and checks with password_verify().
     * Updates lastUsedAt on successful match.
     *
     * @param string $rawToken The raw base64-encoded token
     *
     * @return array|null The matching PortalToken object or null if invalid
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function validateToken(string $rawToken): ?array
    {
        $objectService = $this->getObjectService();

        $tokens = $objectService->getObjects(
            register: 'shillinq',
            schema: 'PortalToken',
            filters: ['isActive' => true],
        );

        $now = new \DateTimeImmutable();

        foreach ($tokens as $token) {
            if (empty($token['expiresAt']) === false) {
                $expiresAt = new \DateTimeImmutable($token['expiresAt']);
                if ($expiresAt < $now) {
                    continue;
                }
            }

            if (password_verify($rawToken, $token['tokenHash']) === true) {
                $objectService->updateObject(
                    register: 'shillinq',
                    schema: 'PortalToken',
                    id: $token['id'],
                    object: ['lastUsedAt' => $now->format('c')],
                );

                return $token;
            }
        }

        return null;
    }//end validateToken()

    /**
     * Get invoices scoped to a portal token's organisation.
     *
     * @param array $portalToken The validated PortalToken object
     *
     * @return array List of invoice objects
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function getScopedInvoices(array $portalToken): array
    {
        $objectService = $this->getObjectService();

        return $objectService->getObjects(
            register: 'shillinq',
            schema: 'Invoice',
            filters: ['organizationId' => $portalToken['organizationId']],
        );
    }//end getScopedInvoices()

    /**
     * Get payments scoped to a portal token's organisation.
     *
     * @param array $portalToken The validated PortalToken object
     *
     * @return array List of payment objects
     *
     * @spec openspec/changes/general/tasks.md#task-11.1
     */
    public function getScopedPayments(array $portalToken): array
    {
        $objectService = $this->getObjectService();

        return $objectService->getObjects(
            register: 'shillinq',
            schema: 'Payment',
            filters: ['organizationId' => $portalToken['organizationId']],
        );
    }//end getScopedPayments()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return mixed The ObjectService instance
     */
    private function getObjectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
