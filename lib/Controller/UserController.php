<?php

/**
 * Shillinq User Controller
 *
 * OCS controller for managing User profile objects.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
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
 * Controller for User list, detail, update, and HR provisioning.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
 */
class UserController extends Controller
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
     * List all users.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $results       = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'user',
                filters: [],
            );
            return new JSONResponse(data: ['results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: user index failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500,
            );
        }//end try
    }//end index()

    /**
     * Get a single user by ID.
     *
     * @param string $id The user object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $user          = $objectService->getObject(
                register: Application::APP_ID,
                schema: 'user',
                id: $id,
            );
            return new JSONResponse(data: $user);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: user show failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 404,
            );
        }//end try
    }//end show()

    /**
     * Update a user.
     *
     * @param string $id The user object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
     */
    public function update(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();

            // Allowlist fields — prevent mass assignment of sensitive internal fields like isActive/isAdmin.
            $allowedData = [
                'id'          => $id,
                'displayName' => ($data['displayName'] ?? ''),
                'email'       => ($data['email'] ?? ''),
                'branch'      => ($data['branch'] ?? ''),
                'department'  => ($data['department'] ?? ''),
            ];

            $user = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'user',
                object: $allowedData,
            );
            return new JSONResponse(data: $user);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: user update failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end update()

    /**
     * Provision a user from an HR onboarding event.
     *
     * Accepts { employeeId, email, roleName } and creates or updates the User object.
     * The email field is mandatory — provisioning without a valid email is rejected.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.3
     */
    public function provision(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();
            $employeeId    = ($data['employeeId'] ?? '');

            if (empty($employeeId) === true) {
                return new JSONResponse(
                    data: ['error' => 'employeeId is required'],
                    statusCode: 422,
                );
            }

            // Email is mandatory — never fall back to a placeholder domain.
            $email = ($data['email'] ?? '');
            if (empty($email) === true || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return new JSONResponse(
                    data: ['error' => 'A valid email address is required'],
                    statusCode: 422,
                );
            }

            // Check if user already exists.
            $existing = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'user',
                filters: ['username' => $employeeId],
            );

            if (empty($data['email']) === true) {
                return new JSONResponse(
                    data: ['error' => 'email is required for user provisioning'],
                    statusCode: 422,
                );
            }

            $userData = [
                'username'    => $employeeId,
                'displayName' => ($data['displayName'] ?? $employeeId),
                'email'       => $email,
                'isActive'    => true,
                'createdAt'   => date('c'),
            ];

            if (empty($existing) === false) {
                $userData['id'] = ($existing[0]['id'] ?? null);
            }

            $user = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'user',
                object: $userData,
            );

            return new JSONResponse(data: $user, statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: user provision failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end provision()
}//end class
