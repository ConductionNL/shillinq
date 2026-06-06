<?php

/**
 * VAT Declaration Controller
 *
 * Tier-3 BTW-rubriek read-only API for the bookkeeping-vat-btw-filing
 * change (issue #127). Exposes the declarations belonging to one
 * VAT return:
 *
 *   GET /api/vat-returns/{returnId}/declarations
 *
 * The endpoint is authenticated (#[NoAdminRequired]); the per-object
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only API for VAT declarations belonging to a return.
 *
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 */
class VATDeclarationController extends Controller
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object.
     * @param ContainerInterface $container DI container for OR's ObjectService.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the VATDeclaration rows belonging to one VATReturn.
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
        if ($returnId === '' || preg_match(pattern: self::ID_PATTERN, subject: $returnId) !== 1) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $declarations  = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATDeclaration')
                ->findAll(['filters' => ['returnId' => $returnId]]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATDeclarationController: failed to list declarations',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to list declarations'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'data'  => $declarations,
                'total' => count($declarations),
            ],
            Http::STATUS_OK
        );

    }//end listByReturn()
}//end class
