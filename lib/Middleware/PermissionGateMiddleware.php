<?php

/**
 * Shillinq Permission Gate Middleware
 *
 * Intercepts all Shillinq OCS requests to enforce role-based access control.
 *
 * @category  Middleware
 * @package   OCA\Shillinq\Middleware
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

namespace OCA\Shillinq\Middleware;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AuditLogService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware that enforces role-based permission checks on all Shillinq OCS requests.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class PermissionGateMiddleware extends Middleware
{

    /**
     * Cached user object for the current request.
     *
     * @var array|null
     */
    private ?array $currentUser = null;


    /**
     * Constructor for PermissionGateMiddleware.
     *
     * @param IUserSession       $userSession     The user session
     * @param IRequest           $request         The current request
     * @param ContainerInterface $container       The DI container
     * @param AuditLogService    $auditLogService The audit log service
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private IUserSession $userSession,
        private IRequest $request,
        private ContainerInterface $container,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Execute before the controller method. Checks user status and role level.
     *
     * @param \OCP\AppFramework\Controller $controller  The controller
     * @param string                       $methodName  The method name being called
     *
     * @return void
     *
     * @throws \OCA\Shillinq\Middleware\PermissionDeniedException If access is denied
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function beforeController($controller, $methodName): void
    {
        // Only gate controllers in the Shillinq namespace (skip Dashboard, Settings).
        $controllerClass = get_class($controller);
        $gatedControllers = [
            'OCA\\Shillinq\\Controller\\RoleController',
            'OCA\\Shillinq\\Controller\\TeamController',
            'OCA\\Shillinq\\Controller\\UserController',
            'OCA\\Shillinq\\Controller\\AccessControlController',
            'OCA\\Shillinq\\Controller\\DelegationController',
            'OCA\\Shillinq\\Controller\\RecertificationController',
            'OCA\\Shillinq\\Controller\\ReportController',
        ];

        if (in_array($controllerClass, $gatedControllers, true) === false) {
            return;
        }

        $ncUser = $this->userSession->getUser();
        if ($ncUser === null) {
            $this->auditLogService->log('permission-denied', 'api', null, 'denied', ['reason' => 'no session']);
            throw new PermissionDeniedException('Authentication required');
        }

        // Resolve Shillinq user object.
        $shillinqUser = $this->resolveUser($ncUser->getUID());
        if ($shillinqUser === null) {
            // No Shillinq user profile yet — allow through for basic endpoints.
            $this->auditLogService->log('read', 'api', null, 'success');
            return;
        }

        // Check isActive.
        if (isset($shillinqUser['isActive']) === true && $shillinqUser['isActive'] === false) {
            $this->auditLogService->log(
                'permission-denied',
                'api',
                null,
                'denied',
                ['reason' => 'user inactive', 'username' => $ncUser->getUID()]
            );
            throw new PermissionDeniedException('Your account has been deactivated');
        }

        // Check role level via reflection on the controller method annotations.
        $requiredLevel = $this->getRequiredRoleLevel($controller, $methodName);
        if ($requiredLevel > 0) {
            $userLevel = $this->getUserRoleLevel($shillinqUser);
            if ($userLevel < $requiredLevel) {
                $this->auditLogService->log(
                    'permission-denied',
                    'api',
                    null,
                    'denied',
                    [
                        'reason'        => 'insufficient role level',
                        'required'      => $requiredLevel,
                        'actual'        => $userLevel,
                        'username'      => $ncUser->getUID(),
                    ]
                );
                throw new PermissionDeniedException(
                    'Insufficient permissions: requires role level '.$requiredLevel
                );
            }
        }

        // Log successful access.
        $this->auditLogService->log('read', 'api', null, 'success');

    }//end beforeController()


    /**
     * Handle exceptions thrown by the controller or middleware.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     * @param \Exception                   $exception  The exception
     *
     * @return JSONResponse|void
     *
     * @throws \Exception Re-throws non-permission exceptions
     */
    public function afterException($controller, $methodName, \Exception $exception)
    {
        if ($exception instanceof PermissionDeniedException) {
            return new JSONResponse(
                ['error' => $exception->getMessage()],
                403
            );
        }

        throw $exception;

    }//end afterException()


    /**
     * Resolve a Nextcloud user to a Shillinq User object.
     *
     * @param string $username The Nextcloud username
     *
     * @return array|null The user object or null
     */
    private function resolveUser(string $username): ?array
    {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $users = $objectService->findObjects(
                filters: ['username' => $username],
                register: Application::APP_ID,
                schema: 'user',
            );

            if (empty($users) === false) {
                $this->currentUser = $users[0];
                return $this->currentUser;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: could not resolve user', ['exception' => $e->getMessage()]);
        }

        return null;

    }//end resolveUser()


    /**
     * Extract the required role level from method annotations.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     *
     * @return int The required role level (0 if not specified)
     */
    private function getRequiredRoleLevel($controller, string $methodName): int
    {
        try {
            $reflection = new \ReflectionMethod($controller, $methodName);
            $docComment = $reflection->getDocComment();

            if ($docComment !== false && preg_match('/@RequiresRoleLevel\((\d+)\)/', $docComment, $matches) === 1) {
                return (int) $matches[1];
            }
        } catch (\ReflectionException $e) {
            $this->logger->warning('Shillinq: reflection failed for role level check', ['exception' => $e->getMessage()]);
        }

        return 0;

    }//end getRequiredRoleLevel()


    /**
     * Get the highest role level for a user across base and delegated roles.
     *
     * @param array $user The Shillinq user object
     *
     * @return int The highest role level
     */
    private function getUserRoleLevel(array $user): int
    {
        $highestLevel = 0;

        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            $userId = ($user['id'] ?? '');

            // Get all active access rights for this user.
            $accessRights = $objectService->findObjects(
                filters: [
                    'userId'   => $userId,
                    'isActive' => true,
                ],
                register: Application::APP_ID,
                schema: 'accessRight',
            );

            foreach ($accessRights as $right) {
                $roles = $objectService->findObjects(
                    filters: ['id' => $right['roleId']],
                    register: Application::APP_ID,
                    schema: 'role',
                );

                if (empty($roles) === false) {
                    $role = $roles[0];
                    $level = (int) ($role['level'] ?? 0);
                    if ($level > $highestLevel) {
                        $highestLevel = $level;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: failed to resolve user role level', ['exception' => $e->getMessage()]);
        }//end try

        return $highestLevel;

    }//end getUserRoleLevel()


}//end class
