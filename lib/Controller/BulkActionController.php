<?php

/**
 * Shillinq Bulk Action Controller
 *
 * OCS API controller for batch operations on OpenRegister objects.
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
 * Controller for bulk operations on entities.
 *
 * @spec openspec/changes/general/tasks.md#task-8.2
 */
class BulkActionController extends Controller
{

    /**
     * Schemas that may be targeted by bulk operations.
     *
     * @var array<string>
     */
    private const ALLOWED_SCHEMAS = ['Invoice', 'ExpenseClaim', 'Payment', 'AutomationRule'];
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
     * Bulk approve objects of a given schema.
     *
     * @param string $schema The schema name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function approve(string $schema): JSONResponse
    {
        if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
            return new JSONResponse(['error' => 'Schema not permitted for bulk operations'], 400);
        }

        $ids = $this->request->getParam('ids', []);

        if (empty($ids) === true) {
            return new JSONResponse(
                ['error' => 'ids array is required'],
                400
            );
        }

        $result = $this->bulkActionService->bulkApprove($schema, $ids);

        return new JSONResponse($result);
    }//end approve()

    /**
     * Bulk delete objects of a given schema.
     *
     * @param string $schema The schema name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function delete(string $schema): JSONResponse
    {
        if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
            return new JSONResponse(['error' => 'Schema not permitted for bulk operations'], 400);
        }

        $ids = $this->request->getParam('ids', []);

        if (empty($ids) === true) {
            return new JSONResponse(
                ['error' => 'ids array is required'],
                400
            );
        }

        $result = $this->bulkActionService->bulkDelete($schema, $ids);

        return new JSONResponse($result);
    }//end delete()

    /**
     * Bulk assign objects of a given schema to a user.
     *
     * @param string $schema The schema name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-8.2
     */
    public function assign(string $schema): JSONResponse
    {
        if (in_array($schema, self::ALLOWED_SCHEMAS, true) === false) {
            return new JSONResponse(['error' => 'Schema not permitted for bulk operations'], 400);
        }

        $ids        = $this->request->getParam('ids', []);
        $assigneeId = $this->request->getParam('assigneeId');

        if (empty($ids) === true || empty($assigneeId) === true) {
            return new JSONResponse(
                ['error' => 'ids array and assigneeId are required'],
                400
            );
        }

        $result = $this->bulkActionService->bulkAssign($schema, $ids, $assigneeId);

        return new JSONResponse($result);
    }//end assign()
}//end class
