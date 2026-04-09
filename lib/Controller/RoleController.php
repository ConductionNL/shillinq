<?php

/**
 * Shillinq Role Controller
 *
 * OCS controller for managing Role objects.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */

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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class RoleController extends Controller
{


    /**
     * Constructor for RoleController.
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
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $roles = $objectService->findObjects(
            filters: [],
            register: Application::APP_ID,
            schema: 'role',
        );

        return new JSONResponse($roles);

    }//end index()


    /**
     * Get a single role by ID.
     *
     * @NoAdminRequired
     *
     * @param string $id The role object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function show(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $roles = $objectService->findObjects(
            filters: ['id' => $id],
            register: Application::APP_ID,
            schema: 'role',
        );

        if (empty($roles) === true) {
            return new JSONResponse(['error' => 'Role not found'], 404);
        }

        return new JSONResponse($roles[0]);

    }//end show()


    /**
     * Create a new role.
     *
     * @RequiresRoleLevel(100)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function create(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $data = $this->request->getParams();

        $role = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'role',
            object: $data,
        );

        return new JSONResponse($role, 201);

    }//end create()


    /**
     * Update an existing role.
     *
     * @RequiresRoleLevel(100)
     *
     * @param string $id The role object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function update(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $data = $this->request->getParams();
        $data['id'] = $id;

        $role = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'role',
            object: $data,
        );

        return new JSONResponse($role);

    }//end update()


    /**
     * Delete a role. Blocked if users are assigned.
     *
     * @RequiresRoleLevel(100)
     *
     * @param string $id The role object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function destroy(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        // Check for assigned users via active access rights.
        $assignedRights = $objectService->findObjects(
            filters: [
                'roleId'   => $id,
                'isActive' => true,
            ],
            register: Application::APP_ID,
            schema: 'accessRight',
        );

        if (empty($assignedRights) === false) {
            $usernames = array_map(
                static fn(array $r): string => ($r['userId'] ?? 'unknown'),
                $assignedRights
            );
            return new JSONResponse(
                [
                    'error'          => 'Cannot delete role: users are assigned',
                    'affectedUsers'  => $usernames,
                ],
                422
            );
        }

        $objectService->deleteObject(
            register: Application::APP_ID,
            schema: 'role',
            id: $id,
        );

        return new JSONResponse(['success' => true]);

    }//end destroy()


}//end class
