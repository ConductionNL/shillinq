<?php

/**
 * Shillinq Recertification Controller
 *
 * OCS controller for managing access recertification campaigns.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
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
 * Controller for recertification campaign listing and review submission.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
 */
class RecertificationController extends Controller
{
    /**
     * Constructor.
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
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $results       = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'accessRecertification',
                filters: [],
            );
            return new JSONResponse(data: ['results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: recertification index failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500,
            );
        }//end try
    }//end index()

    /**
     * Create a new recertification campaign.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
     */
    public function create(): JSONResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $data          = $this->request->getParams();

            // Allowlist fields — never trust the full request payload to prevent mass assignment.
            $allowedData = [
                'name'        => ($data['name'] ?? ''),
                'description' => ($data['description'] ?? ''),
                'dueDate'     => ($data['dueDate'] ?? null),
                'scope'       => ($data['scope'] ?? ''),
            ];

            $campaign = $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'accessRecertification',
                object: $allowedData,
            );
            return new JSONResponse(data: $campaign, statusCode: 201);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: recertification create failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end create()

    /**
     * Submit review decisions for a campaign.
     *
     * @param string $id The campaign object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
     */
    public function review(string $id): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $decisions = ($data['decisions'] ?? []);

            $result = $this->recertificationService->processReviewDecisions(
                campaignId: $id,
                decisions: $decisions,
            );

            return new JSONResponse(data: ['results' => $result]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: recertification review failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 400,
            );
        }//end try
    }//end review()
}//end class
