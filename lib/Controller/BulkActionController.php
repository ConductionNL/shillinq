<?php

/**
 * Shillinq Bulk Action Controller
 *
 * OCS API controller for batch operations (approve, delete, assign).
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
 *
 * @spec openspec/changes/general/tasks.md#task-8.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BulkActionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for batch operations on OpenRegister objects.
 *
 * @spec openspec/changes/general/tasks.md#task-8.2
 */
class BulkActionController extends Controller
{

    /**
     * Constructor for BulkActionController.
     *
     * @param IRequest          $request           The request object
     * @param BulkActionService $bulkActionService The bulk action service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private BulkActionService $bulkActionService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Bulk approve objects of the given schema.
     *
     * @NoAdminRequired
     *
     * @param string $schema The schema name.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function approve(string $schema): JSONResponse
    {
        $ids = $this->request->getParam('ids', []);
        if (is_array($ids) === false || count($ids) === 0) {
            return new JSONResponse(['error' => 'ids array is required'], 400);
        }

        $result = $this->bulkActionService->bulkApprove($schema, $ids);

        return new JSONResponse($result);
    }//end approve()

    /**
     * Bulk delete objects of the given schema.
     *
     * @NoAdminRequired
     *
     * @param string $schema The schema name.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function delete(string $schema): JSONResponse
    {
        $ids = $this->request->getParam('ids', []);
        if (is_array($ids) === false || count($ids) === 0) {
            return new JSONResponse(['error' => 'ids array is required'], 400);
        }

        $result = $this->bulkActionService->bulkDelete($schema, $ids);

        return new JSONResponse($result);
    }//end delete()

    /**
     * Bulk assign objects to a new assignee.
     *
     * @NoAdminRequired
     *
     * @param string $schema The schema name.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function assign(string $schema): JSONResponse
    {
        $ids        = $this->request->getParam('ids', []);
        $assigneeId = $this->request->getParam('assigneeId', '');

        if (is_array($ids) === false || count($ids) === 0) {
            return new JSONResponse(['error' => 'ids array is required'], 400);
        }

        if (empty($assigneeId) === true) {
            return new JSONResponse(['error' => 'assigneeId is required'], 400);
        }

        $result = $this->bulkActionService->bulkAssign($schema, $ids, $assigneeId);

        return new JSONResponse($result);
    }//end assign()
}//end class
