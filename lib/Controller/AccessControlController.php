<?php

/**
 * Shillinq Access Control Controller
 *
 * Read-only OCS controller for the audit log.
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
 * Read-only controller for querying the AccessControl audit log.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class AccessControlController extends Controller
{
    /**
     * Constructor for AccessControlController.
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
     * @RequiresRoleLevel(60)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        $filters = [];
        $action  = $this->request->getParam('action');
        $result  = $this->request->getParam('result');

        if (empty($action) === false) {
            $filters['action'] = $action;
        }

        if (empty($result) === false) {
            $filters['result'] = $result;
        }

        $events = $objectService->findObjects(
            filters: $filters,
            register: Application::APP_ID,
            schema: 'accessControl',
        );

        return new JSONResponse($events);

    }//end index()

    /**
     * Get a single audit log entry.
     *
     * @param string $id The access control event ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @RequiresRoleLevel(60)
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function show(string $id): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $events        = $objectService->findObjects(
            filters: ['id' => $id],
            register: Application::APP_ID,
            schema: 'accessControl',
        );

        if (empty($events) === true) {
            return new JSONResponse(['error' => 'Audit event not found'], 404);
        }

        return new JSONResponse($events[0]);

    }//end show()
}//end class
