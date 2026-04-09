<?php

/**
 * Shillinq Team Controller
 *
 * OCS controller for managing Team objects and member invitations.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\DelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for Team CRUD and member invitation.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
 */
class TeamController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request           The request object
     * @param ContainerInterface $container         The DI container
     * @param DelegationService  $delegationService The delegation service
     * @param IUserSession       $userSession       The user session
     * @param IUserManager       $userManager       The user manager
     * @param LoggerInterface    $logger            The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private DelegationService $delegationService,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all teams.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $results       = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'team',
                filters: [],
            );
            return new JSONResponse(data: ['results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team index failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500,
            );
        }//end try
    }//end index()

    /**
     * Get a single team by ID.
     *
     * @param string $id The team object ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $team          = $objectService->getObject(
                register: Application::APP_ID,
                schema: 'team',
                id: $id,
            );
            return new JSONResponse(data: $team);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team show failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 404,
            );
        }//end try
    }//end show()

    /**
     * Create a new team.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function create(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();
            if (isset($data['createdAt']) === false) {
                $data['createdAt'] = date('c');
            }

            $team = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'team',
                object: $data,
            );
            return new JSONResponse(data: $team, statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team create failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end create()

    /**
     * Update an existing team.
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function update(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();
            $data['id']    = $id;
            $team          = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'team',
                object: $data,
            );
            return new JSONResponse(data: $team);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team update failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end update()

    /**
     * Delete a team.
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $objectService->deleteObject(
                register: Application::APP_ID,
                schema: 'team',
                id: $id,
            );
            return new JSONResponse(data: ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team destroy failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end destroy()

    /**
     * Invite a member to a team by provisioning their role.
     *
     * The email is resolved to a Nextcloud UID before creating the delegation.
     * Returns 422 if no Nextcloud account matches the provided email address.
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.2
     */
    public function invite(string $id): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $email  = ($data['email'] ?? '');
            $roleId = ($data['roleId'] ?? '');
            $user   = $this->userSession->getUser();

            $admin = 'system';
            if ($user !== null) {
                $admin = $user->getUID();
            }

            if (empty($email) === true) {
                return new JSONResponse(
                    data: ['error' => 'email is required'],
                    statusCode: 422,
                );
            }

            // Resolve the Nextcloud UID from the email address.
            $ncUsers = $this->userManager->getByEmail($email);
            if (empty($ncUsers) === true) {
                return new JSONResponse(
                    data: ['error' => 'No Nextcloud account found for the provided email address'],
                    statusCode: 422,
                );
            }

            $ncUserId = $ncUsers[0]->getUID();

            $accessRight = $this->delegationService->createDelegation(
                userId: $ncUserId,
                roleId: $roleId,
                grantedBy: $admin,
                start: new \DateTime(),
                end: new \DateTime('+1 year'),
                reason: 'Team invitation for team '.$id,
            );

            return new JSONResponse(data: $accessRight, statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: team invite failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end invite()
}//end class
