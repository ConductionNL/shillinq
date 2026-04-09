<?php

/**
 * Shillinq Permission Gate Middleware
 *
 * Intercepts OCS requests to enforce role-level access and audit logging.
 *
 * @category  Middleware
 * @package   OCA\Shillinq\Middleware
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Middleware;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Exception\PermissionGateException;
use OCA\Shillinq\Service\AuditLogService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware that gates every Shillinq API request on role level.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.1
 */
class PermissionGateMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param IRequest           $request         The current request
     * @param IUserSession       $userSession     The user session
     * @param ContainerInterface $container       The DI container
     * @param AuditLogService    $auditLogService The audit log service
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private IRequest $request,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Called before the controller method is executed.
     *
     * Checks user active status and role level against the endpoint annotation.
     *
     * @param \OCP\AppFramework\Controller $controller The controller being called
     * @param string                       $methodName The method being called
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.1
     */
    public function beforeController($controller, $methodName): void
    {
        // Only gate controllers in our namespace.
        if (str_contains(get_class($controller), 'OCA\\Shillinq\\Controller\\') === false) {
            return;
        }

        // Skip the dashboard controller (serves the SPA).
        if (str_contains(get_class($controller), 'DashboardController') === true) {
            return;
        }

        // Skip the settings controller (handled by NC admin check).
        if (str_contains(get_class($controller), 'SettingsController') === true) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $ncUserId = $user->getUID();

        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            // Resolve the Shillinq user object.
            $users = $objectService->findObjects(
                Application::APP_ID,
                'user',
                ['username' => $ncUserId],
            );

            if (empty($users) === false) {
                $shillinqUser = $users[0];

                // Check isActive.
                if (($shillinqUser['isActive'] ?? true) === false) {
                    $this->auditLogService->log(
                        action: 'permission-denied',
                        resourceType: get_class($controller),
                        resourceId: $methodName,
                        result: 'denied',
                        details: ['reason' => 'user-inactive'],
                    );
                    throw new PermissionGateException(message: 'Account inactive', statusCode: 403);
                }

                // Check @RequiredRole annotation on the controller method.
                $requiredRole = $this->getRequiredRole(controller: $controller, methodName: $methodName);
                if ($requiredRole !== null) {
                    $activeRoleIds = array_map(
                        static fn($r) => ($r['roleId'] ?? ''),
                        $objectService->findObjects(
                            Application::APP_ID,
                            'accessRight',
                            [
                                'userId'   => ($shillinqUser['id'] ?? $ncUserId),
                                'isActive' => true,
                            ],
                        )
                    );

                    $activeRoles = array_filter(
                            array_map(
                        function ($roleId) use ($objectService) {
                            try {
                                $role = $objectService->getObject(
                                    Application::APP_ID,
                                    'role',
                                    $roleId,
                                );
                                return ($role['name'] ?? null);
                            } catch (\Throwable $e) {
                                return null;
                            }
                        },
                            $activeRoleIds,
                    )
                            );

                    if (in_array($requiredRole, $activeRoles, true) === false) {
                        $this->auditLogService->log(
                            action: 'permission-denied',
                            resourceType: get_class($controller),
                            resourceId: $methodName,
                            result: 'denied',
                            details: ['reason' => 'insufficient-role', 'required' => $requiredRole],
                        );
                        throw new PermissionGateException(message: 'Insufficient role', statusCode: 403);
                    }
                }//end if
            }//end if

            // Log the access attempt as successful.
            $this->auditLogService->log(
                action: 'read',
                resourceType: get_class($controller),
                resourceId: $methodName,
                result: 'success',
            );
        } catch (PermissionGateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: permission gate unavailable, blocking request',
                ['exception' => $e->getMessage()]
            );
            throw new PermissionGateException(message: 'Service unavailable', statusCode: 503);
        }//end try
    }//end beforeController()

    /**
     * Extract the @RequiredRole annotation value from a controller method docblock.
     *
     * Returns null when no annotation is present (the method has no role requirement).
     *
     * @param object $controller The controller instance
     * @param string $methodName The method name
     *
     * @return string|null The required role name, or null if not annotated
     */
    private function getRequiredRole(object $controller, string $methodName): ?string
    {
        try {
            $reflection = new \ReflectionMethod($controller, $methodName);
            $rawComment = $reflection->getDocComment();
            $docComment = '';
            if ($rawComment !== false) {
                $docComment = $rawComment;
            }

            if (preg_match('/@RequiredRole\s+(\S+)/', $docComment, $matches) === 1) {
                return $matches[1];
            }
        } catch (\ReflectionException $e) {
            // If reflection fails, skip the role check.
        }

        return null;
    }//end getRequiredRole()

    /**
     * Called after the controller method executes.
     *
     * Applies field-level security filtering on JSON responses to strip fields
     * the authenticated user is not permitted to read.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     * @param Response                     $response   The controller response
     *
     * @return Response
     */
    public function afterController($controller, $methodName, Response $response): Response
    {
        if (str_contains(get_class($controller), 'OCA\\Shillinq\\Controller\\') === false) {
            return $response;
        }

        // Apply field-level security filtering on JSON responses.
        if ($response instanceof JSONResponse) {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                try {
                    $fieldSecurityService = $this->container->get(
                        'OCA\Shillinq\Service\FieldSecurityService'
                    );
                    $data = $response->getData();
                    if (is_array($data) === true) {
                        $data = $fieldSecurityService->filterResponse($data, '', $user->getUID());
                        $response->setData($data);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Shillinq: field security filter failed',
                        ['exception' => $e->getMessage()]
                    );
                }
            }
        }

        return $response;
    }//end afterController()

    /**
     * Called after an exception is thrown by the controller.
     *
     * Converts PermissionGateException (thrown by beforeController) into a JSON error
     * response so the client receives a well-formed 403/503 rather than a raw
     * exception page.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     * @param \Exception                   $exception  The thrown exception
     *
     * @return JSONResponse
     *
     * @throws \Exception Re-throws if not a SecurityException
     */
    public function afterException($controller, $methodName, \Exception $exception)
    {
        if ($exception instanceof PermissionGateException) {
            $statusCode = 403;
            if ($exception->getCode() !== 0) {
                $statusCode = $exception->getCode();
            }

            return new JSONResponse(
                data: ['error' => $exception->getMessage()],
                statusCode: $statusCode,
            );
        }

        if (str_contains(get_class($controller), 'OCA\\Shillinq\\Controller\\') === false) {
            throw $exception;
        }

        throw $exception;
    }//end afterException()
}//end class
