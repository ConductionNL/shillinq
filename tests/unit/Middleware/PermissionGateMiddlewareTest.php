<?php

/**
 * Unit tests for PermissionGateMiddleware.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Middleware;

use OCA\Shillinq\Middleware\PermissionGateMiddleware;
use OCA\Shillinq\Service\AuditLogService;
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.1
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
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

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

        $this->request         = $this->createMock(IRequest::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->middleware = new PermissionGateMiddleware(
            request: $this->request,
            userSession: $this->userSession,
            container: $this->container,
            auditLogService: $this->auditLogService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that inactive users are denied access and an audit event is written.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.1
     */
    public function testInactiveUserIsDenied(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('inactive-user');
        $this->userSession->method('getUser')->willReturn($user);

        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObjects')
            ->willReturn([
                [
                    'username' => 'inactive-user',
                    'isActive' => false,
                ],
            ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->auditLogService->expects($this->once())
            ->method('log')
            ->with(
                action: 'permission-denied',
                resourceType: $this->anything(),
                resourceId: $this->anything(),
                result: 'denied',
                details: $this->anything(),
            );

        $controller = new class extends \OCA\Shillinq\Controller\RoleController {

            /**
             * Stub constructor.
             *
             * @return void
             */
            public function __construct()
            {
            }//end __construct()
        };

        $this->middleware->beforeController($controller, 'index');

    }//end testInactiveUserIsDenied()

    /**
     * Test that a valid user request logs a success event.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.1
     */
    public function testActiveUserIsAllowed(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('active-user');
        $this->userSession->method('getUser')->willReturn($user);

        $objectService = $this->createMock(\stdClass::class);
        $objectService->method('findObjects')
            ->willReturn([
                [
                    'username' => 'active-user',
                    'isActive' => true,
                ],
            ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $this->auditLogService->expects($this->once())
            ->method('log')
            ->with(
                action: 'read',
                resourceType: $this->anything(),
                resourceId: $this->anything(),
                result: 'success',
            );

        $controller = new class extends \OCA\Shillinq\Controller\RoleController {

            /**
             * Stub constructor.
             *
             * @return void
             */
            public function __construct()
            {
            }//end __construct()
        };

        $this->middleware->beforeController($controller, 'index');

    }//end testActiveUserIsAllowed()

    /**
     * Test that non-Shillinq controllers are skipped.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.1
     */
    public function testNonShillinqControllerIsSkipped(): void
    {
        $this->auditLogService->expects($this->never())->method('log');

        $controller = $this->createMock(\OCP\AppFramework\Controller::class);
        $this->middleware->beforeController($controller, 'index');

    }//end testNonShillinqControllerIsSkipped()
}//end class
