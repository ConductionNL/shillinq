<?php

/**
 * Shillinq Role Controller
 *
 * OCS controller for managing Role objects.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
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
 * Controller for Role CRUD operations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
 */
class RoleController extends Controller
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
     * List all roles.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $results       = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'role',
                filters: [],
            );
            return new JSONResponse(data: ['results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: operation failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500,
            );
        }//end try
    }//end index()

    /**
     * Get a single role by ID.
     *
     * @param string $id The role object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $role          = $objectService->getObject(
                register: Application::APP_ID,
                schema: 'role',
                id: $id,
            );
            return new JSONResponse(data: $role);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: operation failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 404,
            );
        }//end try
    }//end show()

    /**
     * Create a new role.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
     */
    public function create(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();
            $role          = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'role',
                object: [
                    'name'        => ($data['name'] ?? ''),
                    'description' => ($data['description'] ?? ''),
                    'level'       => ($data['level'] ?? null),
                ],
            );
            return new JSONResponse(data: $role, statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: operation failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end create()

    /**
     * Update an existing role.
     *
     * @param string $id The role object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
     */
    public function update(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();
            $role          = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'role',
                object: [
                    'id'          => $id,
                    'name'        => ($data['name'] ?? ''),
                    'description' => ($data['description'] ?? ''),
                    'level'       => ($data['level'] ?? null),
                ],
            );
            return new JSONResponse(data: $role);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: operation failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end update()

    /**
     * Delete a role if no users are assigned to it.
     *
     * @param string $id The role object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.1
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            // Check for assigned users.
            $assignedRights = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'accessRight',
                filters: [
                    'roleId'   => $id,
                    'isActive' => true,
                ],
            );

            if (empty($assignedRights) === false) {
                $usernames = array_map(
                    static fn($r) => ($r['userId'] ?? 'unknown'),
                    $assignedRights,
                );
                return new JSONResponse(
                    data: [
                        'error'    => 'Cannot delete role with assigned users',
                        'affected' => $usernames,
                    ],
                    statusCode: 422,
                );
            }

            $objectService->deleteObject(
                register: Application::APP_ID,
                schema: 'role',
                id: $id,
            );
            return new JSONResponse(data: ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: operation failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end destroy()
}//end class
