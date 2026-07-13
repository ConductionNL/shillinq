<?php

/**
 * Invoice API Controller
 *
 * HTTP API for invoice-from-time-and-expense (issue #111, Task 19).
 * Exposes five endpoints:
 *
 *   POST   /api/v1/invoices/generate         — draft BillableInvoice
 *   GET    /api/v1/invoices                  — list (filterable)
 *   GET    /api/v1/invoices/{invoiceId}       — detail (invoice + lines)
 *   POST   /api/v1/invoices/{invoiceId}/post  — transition draft → posted
 *   GET    /api/v1/invoices/{invoiceId}/pdf   — export PDF (HTML body wrapped)
 *
 * Endpoints are authenticated (#[NoAdminRequired]). Per-object authorisation
 * is delegated to OpenRegister's ObjectService (administration-scoped
 * multitenancy); the controller never accepts a client-supplied
 * administrationId and always resolves it server-side.
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
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP API for BillableInvoice drafting + posting.
 */
class InvoiceApiController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                     $request               Request.
     * @param InvoiceGenerationService     $service               Drafting/posting service.
     * @param InvoicePdfGenerator          $pdfGenerator          PDF/HTML renderer.
     * @param ContainerInterface           $container             DI container.
     * @param IUserSession                 $session               User session.
     * @param AdministrationContextService $administrationContext Server-resolved tenant scope.
     * @param LoggerInterface              $logger                Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly InvoiceGenerationService $service,
        private readonly InvoicePdfGenerator $pdfGenerator,
        private readonly ContainerInterface $container,
        private readonly IUserSession $session,
        private readonly AdministrationContextService $administrationContext,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Draft a BillableInvoice (POST /api/v1/invoices/generate).
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $admin = $this->resolveAdministrationId();
            $body  = $this->decodeBody();

            $req     = InvoiceGenerationRequest::fromArray($admin, $body);
            $invoice = $this->service->draftInvoice($req);

            return new JSONResponse($invoice, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status  = Http::STATUS_BAD_REQUEST;
            if (str_contains($message, 'Conflict') === true) {
                $status = Http::STATUS_CONFLICT;
            }

            return new JSONResponse(['error' => $message], $status);
        } catch (\Throwable $e) {
            $this->logger->error('InvoiceApiController.generate failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end generate()

    /**
     * List invoices for the current administration (GET /api/v1/invoices).
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $admin   = $this->resolveAdministrationId();
            $filters = ['administrationId' => $admin];

            $billingModel = (string) $this->request->getParam('billingModel', '');
            if ($billingModel !== '') {
                $filters['billingModel'] = $billingModel;
            }

            $status = (string) $this->request->getParam('status', '');
            if ($status !== '') {
                $filters['status'] = $status;
            }

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $all           = $objectService
                ->setRegister('shillinq')
                ->setSchema('BillableInvoice')
                ->findAll(['filters' => $filters]);

            $items = [];
            if (is_array($all) === true) {
                $items = $all;
            }

            return new JSONResponse($items, Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('InvoiceApiController.index failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end index()

    /**
     * Show one invoice + its lines (GET /api/v1/invoices/{invoiceId}).
     *
     * @param string $invoiceId BillableInvoice id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $invoiceId): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $admin         = $this->resolveAdministrationId();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $invoice = $objectService
                ->setRegister('shillinq')
                ->setSchema('BillableInvoice')
                ->find($invoiceId);

            if (is_array($invoice) === false) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            if (((string) ($invoice['administrationId'] ?? '')) !== $admin) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $lines = $objectService
                ->setRegister('shillinq')
                ->setSchema('BillableInvoiceLine')
                ->findAll(['filters' => ['invoiceId' => $invoiceId]]);

            $lineItems = [];
            if (is_array($lines) === true) {
                $lineItems = $lines;
            }

            return new JSONResponse(
                [
                    'invoice'    => $invoice,
                    'lines'      => $lineItems,
                    'auditTrail' => [],
                ],
                Http::STATUS_OK
            );
        } catch (\Throwable $e) {
            $this->logger->error('InvoiceApiController.show failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end show()

    /**
     * Post an invoice (POST /api/v1/invoices/{invoiceId}/post).
     *
     * @param string $invoiceId Id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function post(string $invoiceId): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $admin         = $this->resolveAdministrationId();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $invoice       = $objectService->setRegister('shillinq')->setSchema('BillableInvoice')->find($invoiceId);

            if (is_array($invoice) === false) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            if (((string) ($invoice['administrationId'] ?? '')) !== $admin) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $updated = $this->service->postInvoice($invoice);

            return new JSONResponse($updated, Http::STATUS_OK);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status  = Http::STATUS_BAD_REQUEST;
            if (str_contains($message, 'already posted') === true) {
                $status = Http::STATUS_CONFLICT;
            }

            return new JSONResponse(['error' => $message], $status);
        } catch (\Throwable $e) {
            $this->logger->error('InvoiceApiController.post failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end post()

    /**
     * Export invoice PDF (GET /api/v1/invoices/{invoiceId}/pdf).
     *
     * @param string $invoiceId Id.
     *
     * @return DataDisplayResponse|JSONResponse
     */
    #[NoAdminRequired]
    public function pdf(string $invoiceId): DataDisplayResponse|JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $admin         = $this->resolveAdministrationId();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $invoice       = $objectService->setRegister('shillinq')->setSchema('BillableInvoice')->find($invoiceId);

            if (is_array($invoice) === false) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            if (((string) ($invoice['administrationId'] ?? '')) !== $admin) {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $lines     = $objectService
                ->setRegister('shillinq')
                ->setSchema('BillableInvoiceLine')
                ->findAll(['filters' => ['invoiceId' => $invoiceId]]);
            $lineItems = [];
            if (is_array($lines) === true) {
                $lineItems = $lines;
            }

            $pdf = $this->pdfGenerator->generatePdf(invoice: $invoice, lines: $lineItems);

            return new DataDisplayResponse(
                $pdf['html'],
                Http::STATUS_OK,
                [
                    'Content-Type'        => 'text/html; charset=utf-8',
                    'Content-Disposition' => sprintf('inline; filename="%s.html"', preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf['filename'])),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('InvoiceApiController.pdf failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end pdf()

    /**
     * Decode the JSON request body, falling back to POST/GET params.
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

        // Fall back to request params.
        $params = $this->request->getParams();
        if (is_array($params) === true) {
            return $params;
        }

        return [];

    }//end decodeBody()

    /**
     * Resolve the administration id server-side.
     *
     * @return string
     */
    private function resolveAdministrationId(): string
    {
        try {
            $context   = $this->administrationContext->buildContext();
            $candidate = (string) ($context['activeAdministrationId'] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        } catch (\Throwable $e) {
            // Fall through to default.
        }

        return 'default';

    }//end resolveAdministrationId()
}//end class
