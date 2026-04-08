<?php

/**
 * Shillinq Audit Log Service
 *
 * Fire-and-forget audit logging for security-relevant events.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for writing AccessControl audit log entries via OpenRegister.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.2
 */
class AuditLogService
{

    /**
     * Constructor.
     *
     * @param ContainerInterface $container   The DI container
     * @param IRequest           $request     The current request
     * @param IUserSession       $userSession The user session
     * @param LoggerInterface    $logger      The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private IRequest $request,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Write an audit log entry to OpenRegister.
     *
     * Failures are caught and logged but never propagated to the caller.
     *
     * @param string      $action       The action performed
     * @param string      $resourceType The schema name of the resource
     * @param string|null $resourceId   The OpenRegister object ID
     * @param string      $result       The outcome (success, denied, error)
     * @param array|null  $details      Additional context
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.2
     */
    public function log(
        string $action,
        string $resourceType,
        ?string $resourceId,
        string $result,
        ?array $details = null,
    ): void {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $data = [
                'action'       => $action,
                'resourceType' => $resourceType,
                'resourceId'   => ($resourceId ?? ''),
                'timestamp'    => date('c'),
                'result'       => $result,
                'ipAddress'    => $this->request->getRemoteAddress(),
                'userAgent'    => ($this->request->getHeader('User-Agent') ?? ''),
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
                'Shillinq: audit log write failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end log()
}//end class
