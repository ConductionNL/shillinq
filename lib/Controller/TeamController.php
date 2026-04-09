<?php

/**
 * Shillinq Team Controller
 *
 * OCS controller for managing Team objects and member invitations.
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
use OCA\Shillinq\Service\DelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for Team CRUD operations and member invitation.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class TeamController extends Controller
{


    /**
     * Constructor for TeamController.
     *
     * @param IRequest          $request           The request object
     * @param ContainerInterface $container         The DI container
     * @param DelegationService $delegationService The delegation service
     * @param LoggerInterface   $logger            The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private DelegationService $delegationService,
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
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $teams = $objectService->findObjects(
            filters: [],
            register: Application::APP_ID,
            schema: 'team',
        );

        return new JSONResponse($teams);

    }//end index()


    /**
     * Get a single team by ID.
     *
     * @NoAdminRequired
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function show(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $teams = $objectService->findObjects(
            filters: ['id' => $id],
            register: Application::APP_ID,
            schema: 'team',
        );

        if (empty($teams) === true) {
            return new JSONResponse(['error' => 'Team not found'], 404);
        }

        return new JSONResponse($teams[0]);

    }//end show()


    /**
     * Create a new team.
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

        if (isset($data['createdAt']) === false) {
            $data['createdAt'] = (new \DateTime())->format('c');
        }

        $team = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'team',
            object: $data,
        );

        return new JSONResponse($team, 201);

    }//end create()


    /**
     * Update an existing team.
     *
     * @RequiresRoleLevel(100)
     *
     * @param string $id The team object ID
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

        $team = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'team',
            object: $data,
        );

        return new JSONResponse($team);

    }//end update()


    /**
     * Delete a team.
     *
     * @RequiresRoleLevel(100)
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function destroy(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $objectService->deleteObject(
            register: Application::APP_ID,
            schema: 'team',
            id: $id,
        );

        return new JSONResponse(['success' => true]);

    }//end destroy()


    /**
     * Invite a member to the team.
     *
     * @RequiresRoleLevel(80)
     *
     * @param string $id The team object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function invite(string $id): JSONResponse
    {
        $email  = $this->request->getParam('email', '');
        $roleId = $this->request->getParam('roleId', '');

        if (empty($email) === true || empty($roleId) === true) {
            return new JSONResponse(['error' => 'email and roleId are required'], 422);
        }

        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        // Create or update user.
        $users = $objectService->findObjects(
            filters: ['email' => $email],
            register: Application::APP_ID,
            schema: 'user',
        );

        if (empty($users) === true) {
            $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'user',
                object: [
                    'email'       => $email,
                    'username'    => explode('@', $email)[0],
                    'displayName' => explode('@', $email)[0],
                    'isActive'    => true,
                    'createdAt'   => (new \DateTime())->format('c'),
                ],
            );
        }

        return new JSONResponse(['success' => true, 'email' => $email], 201);

    }//end invite()


}//end class
