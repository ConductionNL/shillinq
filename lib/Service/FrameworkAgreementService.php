<?php

/**
 * Framework Agreement Service
 *
 * Generic framework-agreement management, abstracted from the retired purchaseq
 * `raamovereenkomst-minicompetitie` slug (purchaseq#5) as a jurisdiction-neutral
 * control: a supplier contract with a spend ceiling that PurchaseOrder call-offs
 * draw down against (REQ-PG-004). Persists via OpenRegister's ObjectService
 * (ADR-022). Money is integer euro cents.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister-backed framework-agreement service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/procurement-governance/spec.md
 */
class FrameworkAgreementService
{
    /**
     * Construct the service with DI dependencies.
     *
     * @param ContainerInterface              $container             DI container for lazy ObjectService resolution.
     * @param IAppConfig                      $appConfig             App config for register slug resolution.
     * @param AdministrationContextService    $administrationContext Tenant access/identity resolver.
     * @param FrameworkAgreementDrawdownGuard $drawdownGuard         Ceiling guard (reused, unmodified).
     * @param LoggerInterface                 $logger                Logger for diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly AdministrationContextService $administrationContext,
        private readonly FrameworkAgreementDrawdownGuard $drawdownGuard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a framework agreement.
     *
     * @param string              $administrationId Administration (tenant) scope.
     * @param array<string,mixed> $payload          agreementNumber?, supplierId, title, ceilingAmount (cents), validFrom, validUntil.
     *
     * @return array<string,mixed> The persisted FrameworkAgreement (statusCode=active, drawnAmount=0).
     *
     * @throws RuntimeException On missing access or invalid ceiling.
     *
     * @spec openspec/specs/procurement-governance/spec.md
     */
    public function createAgreement(string $administrationId, array $payload): array
    {
        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            throw new RuntimeException('Administration not found');
        }

        $supplierId = trim((string) ($payload['supplierId'] ?? ''));
        if ($supplierId === '') {
            throw new RuntimeException('supplierId is required');
        }

        $ceiling = (int) ($payload['ceilingAmount'] ?? 0);
        if ($ceiling <= 0) {
            throw new RuntimeException('ceilingAmount must be positive (integer cents)');
        }

        $agreementNumber = trim((string) ($payload['agreementNumber'] ?? ''));
        if ($agreementNumber === '') {
            $agreementNumber = $this->generateAgreementNumber(administrationId: $administrationId);
        }

        $record = [
            'agreementNumber'  => $agreementNumber,
            'administrationId' => $administrationId,
            'supplierId'       => $supplierId,
            'title'            => trim((string) ($payload['title'] ?? '')),
            'ceilingAmount'    => $ceiling,
            'drawnAmount'      => 0,
            'currency'         => (string) ($payload['currency'] ?? 'EUR'),
            'validFrom'        => trim((string) ($payload['validFrom'] ?? '')),
            'validUntil'       => trim((string) ($payload['validUntil'] ?? '')),
            'statusCode'       => 'active',
        ];

        return $this->saveObject(schema: 'FrameworkAgreement', object: $record);

    }//end createAgreement()

    /**
     * Record a PurchaseOrder call-off against a framework agreement (REQ-PG-004).
     *
     * Gated by FrameworkAgreementDrawdownGuard::assertWithinCeiling() (reused,
     * unmodified) — a call-off past the remaining ceiling throws and nothing is
     * written. On success `drawnAmount` is incremented.
     *
     * @param string $administrationId     Administration scope.
     * @param string $frameworkAgreementId FrameworkAgreement reference (agreementNumber or id).
     * @param int    $addCents             Call-off amount in integer euro cents.
     *
     * @return array<string,mixed> The updated FrameworkAgreement.
     *
     * @throws RuntimeException When the call-off exceeds the ceiling or the agreement is unusable.
     *
     * @spec openspec/specs/procurement-governance/spec.md
     */
    public function recordCallOff(string $administrationId, string $frameworkAgreementId, int $addCents): array
    {
        if ($addCents <= 0) {
            throw new RuntimeException('Call-off amount must be positive (integer cents)');
        }

        $agreement = $this->drawdownGuard->assertWithinCeiling(
            administrationId: $administrationId,
            frameworkAgreementId: $frameworkAgreementId,
            addCents: $addCents
        );

        $agreement['drawnAmount'] = ((int) ($agreement['drawnAmount'] ?? 0) + $addCents);

        return $this->saveObject(schema: 'FrameworkAgreement', object: $agreement);

    }//end recordCallOff()

    /**
     * Generate a per-administration framework-agreement number.
     *
     * @param string $administrationId Administration scope.
     *
     * @return string
     */
    private function generateAgreementNumber(string $administrationId): string
    {
        $existing = $this->findAll(
            schema: 'FrameworkAgreement',
            filters: ['administrationId' => $administrationId]
        );
        $sequence = str_pad((string) (count($existing) + 1), 6, '0', STR_PAD_LEFT);

        return 'FA-'.date('Y').'-'.$administrationId.'-'.$sequence;

    }//end generateAgreementNumber()

    /**
     * Persist an object via the real ObjectService API.
     *
     * @param string              $schema OR schema slug.
     * @param array<string,mixed> $object Object payload.
     *
     * @return array<string,mixed>
     */
    private function saveObject(string $schema, array $object): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $result        = $objectService
                ->setRegister($this->register())
                ->setSchema($schema)
                ->saveObject($object);

            if (is_array($result) === true) {
                return $result;
            }

            return $object;
        } catch (\Throwable $e) {
            $this->logger->error(
                'FrameworkAgreementService: failed to persist object',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Failed to persist '.$schema);
        }

    }//end saveObject()

    /**
     * Fetch all matching records via the real ObjectService API (findAll).
     *
     * @param string              $schema  OR schema slug.
     * @param array<string,mixed> $filters Equality filters.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findAll(string $schema, array $filters): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService
                ->setRegister($this->register())
                ->setSchema($schema)
                ->findAll(['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'FrameworkAgreementService: failed to query OpenRegister',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $result[] = $row;
            }
        }

        return $result;

    }//end findAll()

    /**
     * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
     *
     * @return string
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;

    }//end register()
}//end class
