<?php

/**
 * Shillinq Delegation Controller
 *
 * OCS controller for creating and revoking access delegations.
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
use OCA\Shillinq\Service\DelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for delegation create and revoke operations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
 */
class DelegationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request object
     * @param DelegationService $delegationService The delegation service
     * @param IUserSession      $userSession       The user session
     * @param LoggerInterface   $logger            The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private DelegationService $delegationService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a new delegation.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $user = $this->userSession->getUser();

            $admin = 'system';
            if ($user !== null) {
                $admin = $user->getUID();
            }

            $accessRight = $this->delegationService->createDelegation(
                userId: ($data['userId'] ?? ''),
                roleId: ($data['roleId'] ?? ''),
                grantedBy: ($data['grantedBy'] ?? $admin),
                start: new \DateTime($data['startDate'] ?? 'now'),
                end: new \DateTime($data['endDate'] ?? '+30 days'),
                reason: ($data['reason'] ?? ''),
            );

            return new JSONResponse(data: $accessRight, statusCode: 201);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 422,
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400,
            );
        }//end try
    }//end create()

    /**
     * Revoke (delete) a delegation.
     *
     * @param string $id The access right object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.5
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();

            $revokedBy = 'system';
            if ($user !== null) {
                $revokedBy = $user->getUID();
            }

            $result = $this->delegationService->revokeDelegation(
                accessRightId: $id,
                revokedBy: $revokedBy,
            );
            return new JSONResponse(data: $result);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400,
            );
        }//end try
    }//end destroy()
}//end class
