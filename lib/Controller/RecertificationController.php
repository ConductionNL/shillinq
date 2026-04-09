<?php

/**
 * Shillinq Recertification Controller
 *
 * OCS controller for managing access recertification campaigns.
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
use OCA\Shillinq\Service\RecertificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for recertification campaign management and review submission.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class RecertificationController extends Controller
{
    /**
     * Constructor for RecertificationController.
     *
     * @param IRequest               $request                The request object
     * @param ContainerInterface     $container              The DI container
     * @param RecertificationService $recertificationService The recertification service
     * @param LoggerInterface        $logger                 The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private RecertificationService $recertificationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * List all recertification campaigns.
     *
     * @NoAdminRequired
     * @RequiresRoleLevel(80)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function index(): JSONResponse
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        $campaigns     = $objectService->findObjects(
            filters: [],
            register: Application::APP_ID,
            schema: 'accessRecertification',
        );

        return new JSONResponse($campaigns);

    }//end index()

    /**
     * Create a new recertification campaign.
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
        $data          = $this->request->getParams();

        $campaign = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'accessRecertification',
            object: $data,
        );

        return new JSONResponse($campaign, 201);

    }//end create()

    /**
     * Submit review decisions for a recertification campaign.
     *
     * @param string $id The campaign object ID
     *
     * @return JSONResponse
     *
     * @RequiresRoleLevel(80)
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function review(string $id): JSONResponse
    {
        $decisions = $this->request->getParam('decisions', []);

        if (empty($decisions) === true) {
            return new JSONResponse(['error' => 'decisions array is required'], 422);
        }

        $summary = $this->recertificationService->processReviewDecisions($id, $decisions);

        return new JSONResponse($summary);

    }//end review()
}//end class
