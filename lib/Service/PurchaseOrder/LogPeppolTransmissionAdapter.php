<?php

/**
 * Log-only Peppol Transmission Adapter (default DI binding)
 *
 * Default binding for {@see PeppolTransmissionAdapterInterface} in dev / CI
 * environments where the openconnector Peppol Access Point is not reachable.
 *
 * `lookupParticipant()` consults the OpenRegister `Vendor` schema (when present)
 * for a `peppolParticipantId` property, returning it verbatim when found and
 * `null` otherwise — the orchestration layer then falls back to PDF + email.
 *
 * `submitOrder()` logs a single redacted line through the standard logger and
 * fabricates a Peppol-shaped URN (`urn:uuid:...`) so the orchestration code can
 * still record `peppolMessageId` deterministically. Production deployments swap
 * this for an HTTP-backed adapter that posts the UBL to the access point.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Log-only Peppol adapter (default DI binding).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 */
final class LogPeppolTransmissionAdapter implements PeppolTransmissionAdapterInterface
{

    /**
     * DI container — used to lazily fetch OR's ObjectService for the
     * Vendor lookup. The container indirection keeps this class testable
     * without forcing a hard dependency on OpenRegister when the schema is
     * absent (e.g. greenfield deployments).
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * App config (resolves the register slug, mirrors PurchaseOrderService).
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Logger sink.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor.
     *
     * @param ContainerInterface   $container DI container — OR's ObjectService
     *                                        is fetched lazily.
     * @param IAppConfig           $appConfig App config for the register slug.
     * @param LoggerInterface|null $logger    Optional logger (defaults to NullLogger).
     *
     * @return void
     */
    public function __construct(
        ContainerInterface $container,
        IAppConfig $appConfig,
        ?LoggerInterface $logger=null
    ) {
        $this->container = $container;
        $this->appConfig = $appConfig;
        $this->logger    = ($logger ?? new NullLogger());

    }//end __construct()


    /**
     * Resolve the Peppol participant id of a supplier from the Vendor schema.
     *
     * Returns `null` when the schema is missing, the supplier has no row, or
     * the row does not carry a non-empty `peppolParticipantId`. Any thrown
     * lookup error is treated as "not registered" — the caller falls back to
     * PDF + email rather than failing the transmission outright (REQ-PO3W-002
     * graceful degradation).
     *
     * @param string $administrationId Administration scope.
     * @param string $supplierId       The PurchaseOrder.supplierId to look up.
     *
     * @return string|null The Peppol participant id, or null when not registered.
     */
    public function lookupParticipant(string $administrationId, string $supplierId): ?string
    {
        if ($administrationId === '' || $supplierId === '') {
            return null;
        }

        try {
            $register      = $this->register();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService
                ->setRegister($register)
                ->setSchema('Vendor')
                ->findAll(
                    [
                        'filters' => [
                            'administrationId' => $administrationId,
                            'id'               => $supplierId,
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->info(
                'shillinq.peppol.lookup.skipped',
                [
                    'reason'    => 'vendor_schema_unavailable',
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $participantId = trim((string) ($row['peppolParticipantId'] ?? ''));
            if ($participantId !== '') {
                return $participantId;
            }
        }

        return null;

    }//end lookupParticipant()


    /**
     * @inheritDoc
     */
    public function submitOrder(string $participantId, string $ublOrderXml): string
    {
        // Length only — never log the UBL body (may contain supplier PII).
        $this->logger->info(
            'shillinq.peppol.submit',
            [
                'participantId' => $participantId,
                'ublLength'     => strlen($ublOrderXml),
            ]
        );

        // Deterministic URN derived from the participant + payload hash so the
        // dev adapter remains reproducible across reruns.
        $hash = substr(hash('sha256', ($participantId.':'.$ublOrderXml)), 0, 32);
        $urn  = sprintf(
            'urn:uuid:%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );

        return $urn;

    }//end submitOrder()


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
