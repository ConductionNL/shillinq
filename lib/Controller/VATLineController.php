<?php

/**
 * VAT Line Controller
 *
 * Tier-3 BTW-regel read-only API for the bookkeeping-vat-btw-filing
 * change (issue #127). Exposes the VAT lines belonging to one return
 * or one declaration:
 *
 *   GET /api/vat-returns/{returnId}/lines
 *   GET /api/vat-declarations/{declarationId}/lines
 *
 * Both endpoints are authenticated (#[NoAdminRequired]); the per-object
 * multitenancy is enforced by OpenRegister's ObjectService.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only API for VAT lines belonging to a return or declaration.
 *
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 */
class VATLineController extends Controller
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object.
     * @param ContainerInterface $container DI container for OR's ObjectService.
     * @param IUserSession       $session   User session for the authentication guard.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IUserSession $session,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the VATLine rows belonging to one VATReturn.
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function listByReturn(string $returnId): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        return $this->findLines(filterKey: 'returnId', filterValue: $returnId);

    }//end listByReturn()

    /**
     * List the VATLine rows belonging to one VATDeclaration.
     *
     * @param string $declarationId The VATDeclaration id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function listByDeclaration(string $declarationId): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->validId(id: $declarationId) === false) {
            return new JSONResponse(['error' => 'declarationId is required'], Http::STATUS_BAD_REQUEST);
        }

        return $this->findLines(filterKey: 'declarationId', filterValue: $declarationId);

    }//end listByDeclaration()

    /**
     * Apply the filter against the VATLine schema.
     *
     * @param string $filterKey   The filter field name.
     * @param string $filterValue The filter value.
     *
     * @return JSONResponse
     */
    private function findLines(string $filterKey, string $filterValue): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $lines         = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATLine')
                ->findAll(['filters' => [$filterKey => $filterValue]]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATLineController: failed to list VAT lines',
                [$filterKey => $filterValue, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to list VAT lines'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'data'  => $lines,
                'total' => count($lines),
            ],
            Http::STATUS_OK
        );

    }//end findLines()

    /**
     * Validate an opaque id.
     *
     * @param string $id Candidate identifier.
     *
     * @return bool True when the identifier is non-empty and well-formed.
     */
    private function validId(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return (preg_match(pattern: self::ID_PATTERN, subject: $id) === 1);

    }//end validId()
}//end class
