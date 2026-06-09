<?php

/**
 * Reconciliation Resolution Service
 *
 * T4 bookkeeping-reconciliation-reports — encapsulates the
 * REQ-REC-004 unmatched-item resolution write path used by
 * ReconciliationResolutionController. Pre-checks the parent
 * BankReconciliation is open (not closed/cancelled), persists the
 * resolution classification + reason onto the ReconciliationMatch via
 * OpenRegister's ObjectService, and emits an audit-trail event so the
 * resolution is permanently traceable per ADR-022.
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
 * @spec openspec/changes/bookkeeping-reconciliation-reports/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists REQ-REC-004 resolutions onto ReconciliationMatch records and
 * audit-trails the action against the parent BankReconciliation.
 *
 * @spec openspec/changes/bookkeeping-reconciliation-reports/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
 */
class ReconciliationResolutionService
{


    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container — OR ObjectService
     *                                      pulled lazily so the service
     *                                      stays usable without a
     *                                      compile-time OR dependency.
     * @param IAppConfig         $appConfig App config for register slug.
     * @param LoggerInterface    $logger    Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Resolve one ReconciliationMatch by classifying it per REQ-REC-004.
     *
     * @param string $reconId          The parent BankReconciliation id.
     * @param string $matchId          The ReconciliationMatch id.
     * @param string $resolutionStatus One of matched/timing/pending/adjustment
     *                                  (validated by the caller).
     * @param string $resolutionReason Operator-supplied reason text
     *                                  (audit-trailed).
     * @param string $actor            Nextcloud UID of the operator.
     *
     * @return array<string,mixed> The updated ReconciliationMatch as
     *                              returned by OR.
     *
     * @throws \OutOfBoundsException When the match or parent does not exist.
     * @throws \DomainException      When the parent reconciliation is
     *                               closed/cancelled (locked) per REQ-REC-003.
     * @throws \Throwable            On any OR/service error.
     *
     * @spec openspec/changes/bookkeeping-reconciliation-reports/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
     */
    public function resolveMatch(
        string $reconId,
        string $matchId,
        string $resolutionStatus,
        string $resolutionReason,
        string $actor,
    ): array {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $register      = $this->getRegisterSlug();

        // Load + validate the parent reconciliation.
        try {
            $parent = $objectService
                ->setRegister($register)
                ->setSchema('BankReconciliation')
                ->find($reconId);
            $parent = $this->toArray($parent);
        } catch (\Throwable $e) {
            throw new \OutOfBoundsException(
                'reconciliation '.$reconId.' not found',
                0,
                $e
            );
        }

        if ($parent === null) {
            throw new \OutOfBoundsException('reconciliation '.$reconId.' not found');
        }

        $parentStatus = (string) ($parent['reconciliationStatus'] ?? 'draft');
        if (in_array($parentStatus, ['closed', 'cancelled'], true) === true) {
            throw new \DomainException(
                'reconciliation is '.$parentStatus.' — resolutions are not permitted'
            );
        }

        // Load + validate the match record.
        try {
            $match = $objectService
                ->setRegister($register)
                ->setSchema('ReconciliationMatch')
                ->find($matchId);
            $match = $this->toArray($match);
        } catch (\Throwable $e) {
            throw new \OutOfBoundsException(
                'match '.$matchId.' not found',
                0,
                $e
            );
        }

        if ($match === null) {
            throw new \OutOfBoundsException('match '.$matchId.' not found');
        }

        // Verify the match belongs to the recon (REQ-REC-004 + IDOR guard).
        $matchReconId = (string) ($match['reconId'] ?? '');
        if ($matchReconId !== '' && $matchReconId !== $reconId) {
            throw new \OutOfBoundsException(
                'match '.$matchId.' does not belong to reconciliation '.$reconId
            );
        }

        $updated = $objectService
            ->setRegister($register)
            ->setSchema('ReconciliationMatch')
            ->updateObject(
                $matchId,
                [
                    'reconId'          => $reconId,
                    'resolutionStatus' => $resolutionStatus,
                    'resolutionReason' => $resolutionReason,
                    'matchedAt'        => gmdate('Y-m-d\TH:i:s\Z'),
                ]
            );

        $this->logger->info(
            'ReconciliationResolutionService: REQ-REC-004 resolution applied',
            [
                'reconId'          => $reconId,
                'matchId'          => $matchId,
                'resolutionStatus' => $resolutionStatus,
                'actor'            => $actor,
            ]
        );

        return $this->toArray($updated) ?? [];

    }//end resolveMatch()


    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string The register slug.
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
     * Normalise an OR find/update result to a plain array.
     *
     * @param mixed $result The OR return value.
     *
     * @return array<string,mixed>|null
     */
    private function toArray(mixed $result): ?array
    {
        if (is_array($result) === true) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            return is_array($serialized) ? $serialized : null;
        }

        return null;

    }//end toArray()


}//end class
