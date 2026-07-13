<?php

/**
 * Docudesk Extraction Client
 *
 * Change receipt-extraction-consume (REQ-RXC-005) — the ONLY outbound
 * coupling to docudesk: a single HTTP POST to docudesk's canonical
 * `POST /api/extraction/financial` endpoint (owned by docudesk's
 * financial-document-field-extraction spec, REQ-FIN-01) requesting a
 * (re-)extraction with `callbackEvent: true`. shillinq never calls
 * docudesk's internal extraction logic directly (ADR-022) — the resulting
 * `nl.conduction.docudesk.extraction.completed` event flows back through
 * {@see \OCA\Shillinq\Listener\ExtractionCompletedListener}
 * (REQ-RXC-001).
 *
 * Both apps are installed in the same Nextcloud instance, so the request is
 * addressed via `IURLGenerator::linkToRouteAbsolute('docudesk.extraction.financial')`
 * — the standard NC intra-instance route-name convention
 * (`{appId}.{controller}.{method}`, mirroring docudesk's
 * `appinfo/routes.php` entry `['name' => 'extraction#financial', ...]`) —
 * rather than a configured remote endpoint. This throws when docudesk is not
 * installed (its routes are never registered); the client treats that the
 * same as any other transport failure: fail-soft, never fatal to the caller
 * (proposal.md Risk 3 — the PDF path already falls back to the honest 422
 * deferral; the re-request is simply retriable).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin, fail-soft HTTP client for docudesk's financial-extraction request endpoint.
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
 */
class DocudeskExtractionClient
{
    /**
     * Docudesk route name for the financial extraction endpoint (NC
     * `{appId}.{controller}.{method}` convention for
     * `['name' => 'extraction#financial', 'url' => 'api/extraction/financial', 'verb' => 'POST']`).
     *
     * @var string
     */
    public const ROUTE_NAME = 'docudesk.extraction.financial';

    /**
     * Request timeout in seconds — the endpoint itself only needs to accept
     * the request and dispatch OCR/extraction asynchronously
     * (`callbackEvent: true`), so a short budget is enough.
     *
     * @var int
     */
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService NC HTTP client factory.
     * @param IURLGenerator   $urlGenerator  Resolves docudesk's intra-instance route.
     * @param LoggerInterface $logger        Logger; the request never carries credentials to log.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Request a (re-)extraction for a document (REQ-RXC-005).
     *
     * POSTs `{documentUri, docType, callbackEvent: true}` to docudesk's
     * `POST /api/extraction/financial`. Always sets `callbackEvent: true` —
     * shillinq only consumes the async event path (REQ-RXC-001), never the
     * synchronous response body.
     *
     * @param string $documentUri The docudesk source document URI.
     * @param string $docType     `receipt` or `supplier-invoice`.
     *
     * @return array{success: bool, statusCode: int, error: string|null} Outcome; never throws.
     *
     * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
     */
    public function requestExtraction(string $documentUri, string $docType): array
    {
        try {
            $url = $this->urlGenerator->linkToRouteAbsolute(self::ROUTE_NAME);
        } catch (Throwable $e) {
            $this->logger->warning(
                'DocudeskExtractionClient: docudesk route unavailable — docudesk may not be installed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success'    => false,
                'statusCode' => 0,
                'error'      => 'docudesk is not available',
            ];
        }

        $client = $this->clientService->newClient();
        $body   = [
            'documentUri'   => $documentUri,
            'docType'       => $docType,
            'callbackEvent' => true,
        ];

        try {
            $response = $client->post(
                $url,
                [
                    'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
                    'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'headers'         => [
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                    ],
                    'body'            => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]
            );

            $status = $response->getStatusCode();
            $this->logger->info(
                'DocudeskExtractionClient: extraction requested',
                ['documentUri' => $documentUri, 'docType' => $docType, 'status' => $status]
            );

            return [
                'success'    => ($status >= 200 && $status < 300),
                'statusCode' => $status,
                'error'      => null,
            ];
        } catch (Throwable $e) {
            $status = 0;
            if (method_exists($e, 'getResponse') === true) {
                $errorResponse = $e->getResponse();
                if ($errorResponse !== null) {
                    $status = $errorResponse->getStatusCode();
                }
            }

            $this->logger->warning(
                'DocudeskExtractionClient: extraction request failed',
                ['documentUri' => $documentUri, 'docType' => $docType, 'status' => $status, 'exception' => $e->getMessage()]
            );

            return [
                'success'    => false,
                'statusCode' => $status,
                'error'      => 'docudesk request failed',
            ];
        }//end try

    }//end requestExtraction()
}//end class
