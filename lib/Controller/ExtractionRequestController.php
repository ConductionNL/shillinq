<?php

/**
 * Extraction Request Controller
 *
 * Change receipt-extraction-consume (REQ-RXC-004 / REQ-RXC-005) — a thin
 * proxy so the BillImportModal / Receipt capture frontend never needs
 * docudesk credentials directly (design.md "API Design"):
 *
 *  - `request()` (POST /api/v1/extraction/request) forwards a (re-)extraction
 *    request to docudesk via {@see DocudeskExtractionClient} (REQ-RXC-005).
 *  - `confirm()` (PUT /api/v1/extraction/drafts/{id}) records an operator
 *    correction on an existing extraction draft via
 *    {@see ExtractionPrefillService::recordCorrection()} (REQ-RXC-004) and
 *    persists it through the real OR ObjectService API.
 *
 * Both actions are `#[NoAdminRequired]` and IDOR-safe (ADR-005): the target
 * draft's `administrationId` is always checked against the caller's
 * accessible administrations via `AdministrationContextService::canAccess()`
 * before any read/write — never trusting a client-supplied administration
 * scope.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP API for the extraction re-request proxy and correction commit
 * (receipt-extraction-consume).
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ExtractionRequestController extends Controller
{
    /**
     * The OpenRegister register slug for shillinq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'shillinq';

    /**
     * Schemas this endpoint may operate on (REQ-RXC-001 docType targets).
     *
     * @var array<string>
     */
    private const ALLOWED_SCHEMAS = [
        ExtractionPrefillService::SCHEMA_SUPPLIER_INVOICE,
        ExtractionPrefillService::SCHEMA_RECEIPT,
    ];

    /**
     * Constructor.
     *
     * @param IRequest                     $request               Request.
     * @param DocudeskExtractionClient     $extractionClient      Outbound docudesk request client.
     * @param ExtractionPrefillService     $prefillService        Correction-recording service.
     * @param AdministrationContextService $administrationContext Server-resolved tenant scope (ADR-005).
     * @param IUserSession                 $session               User session.
     * @param ContainerInterface           $container             DI container (OR ObjectService).
     * @param LoggerInterface              $logger                Logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly DocudeskExtractionClient $extractionClient,
        private readonly ExtractionPrefillService $prefillService,
        private readonly AdministrationContextService $administrationContext,
        private readonly IUserSession $session,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * (Re-)request docudesk extraction for a document (REQ-RXC-005).
     *
     * Accepts `{documentUri, docType, id?}`. When `id` is supplied it MUST
     * resolve to an existing draft the caller may access (IDOR guard); when
     * omitted (first-ever extraction of a document not yet drafted in
     * shillinq) the request proceeds without an administration check — there
     * is nothing shillinq-side to scope yet, and the resulting draft is
     * created by the listener once the event arrives.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
     */
    #[NoAdminRequired]
    public function request(): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $documentUri = trim((string) $this->request->getParam('documentUri', ''));
        $docType     = trim((string) $this->request->getParam('docType', ''));
        $id          = trim((string) $this->request->getParam('id', ''));

        if ($documentUri === '' || in_array($docType, ['receipt', 'supplier-invoice'], true) === false) {
            return new JSONResponse(
                ['error' => 'documentUri and a valid docType (receipt|supplier-invoice) are required'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if ($id !== '') {
            $schema = $this->prefillService->schemaForDocType(docType: $docType);
            $guard  = $this->guardDraftAccess(schema: (string) $schema, id: $id);
            if ($guard !== null) {
                return $guard;
            }
        }

        $result = $this->extractionClient->requestExtraction(documentUri: $documentUri, docType: $docType);
        if ($result['success'] === false) {
            return new JSONResponse(
                ['error' => $result['error'] ?? 'docudesk extraction request failed', 'accepted' => false],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        return new JSONResponse(['accepted' => true], Http::STATUS_ACCEPTED);

    }//end request()

    /**
     * Commit an operator correction on an existing extraction draft (REQ-RXC-004).
     *
     * @param string $id     The draft's OR object id.
     * @param string $schema Schema query param (SupplierInvoice|Receipt).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
     */
    #[NoAdminRequired]
    public function confirm(string $id, string $schema=''): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
            return new JSONResponse(['error' => 'Unknown or missing schema'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $guard = $this->guardDraftAccess(schema: $schema, id: $id);
        if ($guard !== null) {
            return $guard;
        }

        $existing = $this->findById(schema: $schema, id: $id);
        if ($existing === null) {
            return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
        }

        $incomingFields = $this->decodeBody();
        // AdministrationId, id and the confidence/provenance bookkeeping
        // fields are never operator-editable via this endpoint.
        unset(
            $incomingFields['administrationId'],
            $incomingFields['id'],
            $incomingFields['fieldConfidence'],
            $incomingFields['overallConfidence'],
            $incomingFields['extractedFieldsOriginal'],
            $incomingFields['humanCorrected'],
            $incomingFields['extractionStatus']
        );

        $updated = $this->prefillService->recordCorrection(existingDraft: $existing, incomingFields: $incomingFields);

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema($schema)
                ->saveObject($updated);
        } catch (Throwable $e) {
            $this->logger->error('ExtractionRequestController.confirm failed to persist: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to save correction'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if (is_array($saved) === true) {
            $record = $saved;
        } else {
            $record = $updated;
        }

        return new JSONResponse(['record' => $record], Http::STATUS_OK);

    }//end confirm()

    /**
     * Load a draft by id and, when found, guard that the caller may access
     * its administration (IDOR guard, ADR-005). Returns a masking 404
     * JSONResponse when access is denied or the draft is missing/malformed,
     * or NULL when the caller may proceed.
     *
     * @param string $schema OR schema slug.
     * @param string $id     OR object id.
     *
     * @return JSONResponse|null
     */
    private function guardDraftAccess(string $schema, string $id): ?JSONResponse
    {
        if ($schema === '' || $id === '') {
            return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
        }

        $existing = $this->findById(schema: $schema, id: $id);
        if ($existing === null) {
            return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
        }

        $administrationId = (string) ($existing['administrationId'] ?? '');
        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            // Masked 404, never 403 — never disclose that another tenant's
            // draft exists (REQ-MA-001).
            return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
        }

        return null;

    }//end guardDraftAccess()

    /**
     * Find an object by id via the real ObjectService API.
     *
     * @param string $schema OR schema slug.
     * @param string $id     OR object id.
     *
     * @return array<string,mixed>|null
     */
    private function findById(string $schema, string $id): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema($schema)
                ->findAll(['filters' => ['id' => $id]]);
        } catch (Throwable $e) {
            return null;
        }

        if (is_array($rows) === false) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) === true) {
                return $row;
            }
        }

        return null;

    }//end findById()

    /**
     * Decode the JSON request body, falling back to POST params.
     *
     * @return array<string,mixed>
     */
    private function decodeBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        }

        $params = $this->request->getParams();
        if (is_array($params) === true) {
            return $params;
        }

        return [];

    }//end decodeBody()
}//end class
