<?php

/**
 * Shillinq Audit Log Service
 *
 * Service for writing immutable audit log entries to the AccessControl schema.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for writing immutable audit log entries to the AccessControl schema.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class AuditLogService
{


    /**
     * Constructor for AuditLogService.
     *
     * @param ContainerInterface $container The DI container
     * @param IRequest           $request   The current request
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IRequest $request,
        private LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Write an audit log entry. Fire-and-forget: errors are logged but never propagated.
     *
     * @param string      $action       The action type (create, read, update, delete, login, etc.)
     * @param string      $resourceType The schema name of the accessed resource
     * @param string|null $resourceId   The OpenRegister object ID
     * @param string      $result       The outcome (success, denied, error)
     * @param array|null  $details      Additional context
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function log(
        string $action,
        string $resourceType,
        ?string $resourceId,
        string $result,
        ?array $details = null,
    ): void {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            $data = [
                'action'       => $action,
                'resourceType' => $resourceType,
                'resourceId'   => $resourceId,
                'timestamp'    => (new \DateTime())->format('c'),
                'result'       => $result,
                'ipAddress'    => $this->request->getRemoteAddress(),
                'userAgent'    => $this->request->getHeader('User-Agent'),
            ];

            if ($details !== null) {
                $data['details'] = $details;
            }

            $objectService->saveObject(
                register: Application::APP_ID,
                schema: 'accessControl',
                object: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to write audit log entry',
                [
                    'action'    => $action,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try

    }//end log()


}//end class
