<?php

/**
 * Unit tests for PermissionGateMiddleware.
 *
 * @category  Test
 * @package   OCA\Shillinq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Middleware;

use OCA\Shillinq\Controller\RoleController;
use OCA\Shillinq\Middleware\PermissionDeniedException;
use OCA\Shillinq\Middleware\PermissionGateMiddleware;
use OCA\Shillinq\Service\AuditLogService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PermissionGateMiddleware.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
 */
class PermissionGateMiddlewareTest extends TestCase
{

    /**
     * The middleware under test.
     *
     * @var PermissionGateMiddleware
     */
    private PermissionGateMiddleware $middleware;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock AuditLogService.
     *
     * @var AuditLogService&MockObject
     */
    private AuditLogService&MockObject $auditLogService;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->userSession     = $this->createMock(IUserSession::class);
        $this->request         = $this->createMock(IRequest::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->middleware = new PermissionGateMiddleware(
            userSession: $this->userSession,
            request: $this->request,
            container: $this->container,
            auditLogService: $this->auditLogService,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Test that an inactive user receives a 403 denial regardless of role.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testInactiveUserGetsDenied(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObjects')->willReturnCallback(
            function (array $filters) {
                if (isset($filters['username']) === true) {
                    return [['id' => 'user-1', 'username' => 'testuser', 'isActive' => false]];
                }

                return [];
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $controller = $this->createMock(RoleController::class);

        $this->expectException(PermissionDeniedException::class);
        $this->middleware->beforeController($controller, 'index');

    }//end testInactiveUserGetsDenied()


    /**
     * Test that afterException returns a 403 JSONResponse for PermissionDeniedException.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testAfterExceptionReturns403ForPermissionDenied(): void
    {
        $controller = $this->createMock(RoleController::class);
        $exception  = new PermissionDeniedException('Access denied');

        $result = $this->middleware->afterException($controller, 'index', $exception);

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(403, $result->getStatus());
        self::assertSame('Access denied', $result->getData()['error']);

    }//end testAfterExceptionReturns403ForPermissionDenied()


    /**
     * Test that a non-gated controller passes through without checks.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testNonGatedControllerPassesThrough(): void
    {
        $controller = $this->createMock(\OCA\Shillinq\Controller\DashboardController::class);

        // Should not throw — DashboardController is not gated.
        $this->middleware->beforeController($controller, 'page');

        // If we reach this point, the test passes.
        self::assertTrue(true);

    }//end testNonGatedControllerPassesThrough()


}//end class
