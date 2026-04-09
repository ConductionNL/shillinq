<?php

/**
 * Shillinq User Controller
 *
 * OCS controller for managing Shillinq User profiles.
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
 * Controller for User profile operations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class UserController extends Controller
{
    /**
     * Constructor for UserController.
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
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $users         = $objectService->findObjects(
            filters: [],
            register: Application::APP_ID,
            schema: 'user',
        );

        return new JSONResponse($users);

    }//end index()

    /**
     * Get a single user by ID.
     *
     * @param string $id The user object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function show(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $users         = $objectService->findObjects(
            filters: ['id' => $id],
            register: Application::APP_ID,
            schema: 'user',
        );

        if (empty($users) === true) {
            return new JSONResponse(['error' => 'User not found'], 404);
        }

        return new JSONResponse($users[0]);

    }//end show()

    /**
     * Update an existing user.
     *
     * @param string $id The user object ID
     *
     * @return JSONResponse
     *
     * @RequiresRoleLevel(80)
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function update(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $data          = $this->request->getParams();
        $data['id']    = $id;

        $user = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'user',
            object: $data,
        );

        return new JSONResponse($user);

    }//end update()

    /**
     * Provision a user from an HR onboarding event.
     *
     * @RequiresRoleLevel(100)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function provision(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        $employeeId = $this->request->getParam('employeeId', '');
        $roleName   = $this->request->getParam('roleName', '');

        if (empty($employeeId) === true) {
            return new JSONResponse(['error' => 'employeeId is required'], 422);
        }

        // Check if user already exists.
        $existing = $objectService->findObjects(
            filters: ['username' => $employeeId],
            register: Application::APP_ID,
            schema: 'user',
        );

        $userData = [
            'username'    => $employeeId,
            'email'       => $employeeId.'@example.com',
            'displayName' => $employeeId,
            'isActive'    => true,
            'createdAt'   => (new \DateTime())->format('c'),
        ];

        if (empty($existing) === false) {
            $userData = array_merge($existing[0], $userData);
        }

        $user = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'user',
            object: $userData,
        );

        return new JSONResponse($user, 201);

    }//end provision()
}//end class
