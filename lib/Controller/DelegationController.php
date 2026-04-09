<?php

/**
 * Shillinq Delegation Controller
 *
 * OCS controller for managing access delegations.
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
use OCA\Shillinq\Service\DelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for creating and revoking access delegations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
 */
class DelegationController extends Controller
{
    /**
     * Constructor for DelegationController.
     *
     * @param IRequest          $request           The request object
     * @param DelegationService $delegationService The delegation service
     * @param LoggerInterface   $logger            The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private DelegationService $delegationService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Create a new delegation.
     *
     * @RequiresRoleLevel(100)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function create(): JSONResponse
    {
        $userId    = $this->request->getParam('userId', '');
        $roleId    = $this->request->getParam('roleId', '');
        $grantedBy = $this->request->getParam('grantedBy', '');
        $startDate = $this->request->getParam('startDate', '');
        $endDate   = $this->request->getParam('endDate', '');
        $reason    = $this->request->getParam('reason', '');

        if (empty($userId) === true || empty($roleId) === true || empty($grantedBy) === true) {
            return new JSONResponse(['error' => 'userId, roleId, and grantedBy are required'], 422);
        }

        try {
            $accessRight = $this->delegationService->createDelegation(
                userId: $userId,
                roleId: $roleId,
                grantedBy: $grantedBy,
                start: new \DateTime($startDate),
                end: new \DateTime($endDate),
                reason: $reason,
            );

            return new JSONResponse($accessRight, 201);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        }

    }//end create()

    /**
     * Revoke (delete) a delegation.
     *
     * @param string $id The AccessRight object ID
     *
     * @return JSONResponse
     *
     * @RequiresRoleLevel(100)
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $updated = $this->delegationService->revokeDelegation($id);
            return new JSONResponse($updated);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }

    }//end destroy()
}//end class
