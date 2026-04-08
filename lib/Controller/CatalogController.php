<?php

/**
 * Shillinq Catalog Controller
 *
 * Controller for catalog search and import operations.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CatalogSearchService;
use OCA\Shillinq\Service\CatalogImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for catalog search and CSV import operations.
 *
 * Provides endpoints for searching catalog items by query string and
 * category, as well as importing catalog items from uploaded CSV files.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.1
 */
class CatalogController extends Controller
{

    /**
     * Constructor for the CatalogController.
     *
     * @param IRequest             $request        The request object
     * @param CatalogSearchService $searchService  The catalog search service
     * @param CatalogImportService $importService  The catalog import service
     * @param ContainerInterface   $container       The DI container
     * @param IUserSession         $userSession     The user session
     * @param LoggerInterface      $logger          The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CatalogSearchService $searchService,
        private CatalogImportService $importService,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * Search catalog items by query string and optional category.
     *
     * Reads the `q` and `categoryId` request parameters and delegates
     * to the CatalogSearchService.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The search results
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.1
     */
    public function search(): JSONResponse
    {
        try {
            $query      = $this->request->getParam('q', '');
            $categoryId = $this->request->getParam('categoryId', null);

            $results = $this->searchService->search(
                query: $query,
                categoryId: $categoryId,
            );

            return new JSONResponse(data: $results);
        } catch (\Exception $e) {
            $this->logger->error('CatalogController::search failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }
    }//end search()


    /**
     * Import catalog items from an uploaded CSV file.
     *
     * Validates that the catalog is not archived before proceeding.
     * Returns 422 if the catalog has been archived.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The catalog ID
     *
     * @return JSONResponse The import result
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.1
     */
    public function import(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Verify catalog is not archived.
            $catalog     = $objectService->findOne(objectType: 'catalog', id: $id);
            $catalogData = is_array($catalog) ? $catalog : $catalog->jsonSerialize();

            if (($catalogData['status'] ?? '') === 'archived') {
                return new JSONResponse(
                    data: ['error' => 'Cannot import into an archived catalog'],
                    statusCode: 422,
                );
            }

            // Read uploaded CSV file.
            $file = $this->request->getUploadedFile('file');
            if ($file === null || !isset($file['tmp_name'])) {
                return new JSONResponse(
                    data: ['error' => 'No CSV file uploaded'],
                    statusCode: 400,
                );
            }

            $result = $this->importService->import(
                catalogId: $id,
                filePath: $file['tmp_name'],
            );

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error('CatalogController::import failed', [
                'catalogId' => $id,
                'exception' => $e,
            ]);
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }
    }//end import()
}//end class
