<?php

/**
 * Shillinq Access Control Controller
 *
 * Read-only OCS controller for querying the audit log.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only controller for the AccessControl audit log.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.4
 */
class AccessControlController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List audit log entries with optional filters.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.4
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $filters = [];
            $params  = $this->request->getParams();

            if (empty($params['action']) === false) {
                $filters['action'] = $params['action'];
            }

            if (empty($params['result']) === false) {
                $filters['result'] = $params['result'];
            }

            $results = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'accessControl',
                filters: $filters,
            );

            return new JSONResponse(data: ['results' => $results]);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end index()

    /**
     * Get a single audit log entry by ID.
     *
     * @param string $id The audit log object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.4
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $entry         = $objectService->getObject(
                register: Application::APP_ID,
                schema: 'accessControl',
                id: $id,
            );
            return new JSONResponse(data: $entry);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 404,
            );
        }//end try
    }//end show()
}//end class
