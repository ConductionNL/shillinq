<?php

/**
 * Controller tests for WbsoTransactionApiController.
 *
 * Covers REQ-WBSO-002/005/008 — list / create / post / reverse with role
 * enforcement (REQ-WBSO-005).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-35
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WbsoTransactionApiController;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-35
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoTransactionApiControllerTest extends TestCase
{

    /**
     * Request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Service.
     *
     * @var WbsoTransactionService&MockObject
     */
    private WbsoTransactionService&MockObject $transactions;

    /**
     * Rbac.
     *
     * @var WbsoRbacResolver&MockObject
     */
    private WbsoRbacResolver&MockObject $rbac;

    /**
     * Session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $session;

    /**
     * Logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Controller.
     *
     * @var WbsoTransactionApiController
     */
    private WbsoTransactionApiController $controller;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request      = $this->createMock(IRequest::class);
        $this->transactions = $this->createMock(WbsoTransactionService::class);
        $this->rbac         = $this->createMock(WbsoRbacResolver::class);
        $this->session      = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->session->method('getUser')->willReturn($user);

        $this->controller = new WbsoTransactionApiController(
            request: $this->request,
            transactions: $this->transactions,
            rbac: $this->rbac,
            userSession: $this->session,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Index returns 200 with rows.
     *
     * @return void
     */
    public function testIndexReturnsRows(): void
    {
        $this->request->method('getParam')->willReturn('adm-1');
        $this->rbac->method('hasAny')->willReturn(true);
        $this->transactions->method('listTransactions')->willReturn([
            ['id' => 'tx-1', 'transactionNumber' => 'INV-1', 'amount' => 100.0, 'status' => 'draft'],
        ]);

        $response = $this->controller->index();
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertCount(1, $data['transactions']);
        self::assertTrue($data['canCreate']);

    }//end testIndexReturnsRows()

    /**
     * Reverse requires administrator role.
     *
     * @return void
     */
    public function testReverseRequiresAdmin(): void
    {
        $this->request->method('getParam')->willReturn('adm-1');
        $this->rbac->method('hasAny')->willReturnMap([
            [['administrator'], false],
        ]);

        $response = $this->controller->reverse(id: 'tx-1');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testReverseRequiresAdmin()

    /**
     * Reversing a non-posted transaction returns 409.
     *
     * @return void
     */
    public function testReverseConflictReturns409(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null): mixed {
                return match ($key) {
                    'administration_id' => 'adm-1',
                    'reason'            => 'oops',
                    default             => $default,
                };
            }
        );
        $this->rbac->method('hasAny')->willReturn(true);
        $this->transactions->method('reverseTransaction')->willThrowException(new RuntimeException('Only posted transactions can be reversed'));

        $response = $this->controller->reverse(id: 'tx-1');
        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testReverseConflictReturns409()

    /**
     * Post happy path returns 200.
     *
     * @return void
     */
    public function testPostHappyPath(): void
    {
        $this->request->method('getParam')->willReturn('adm-1');
        $this->rbac->method('hasAny')->willReturn(true);
        $this->transactions->method('postTransaction')->willReturn([
            'id'     => 'tx-1',
            'status' => 'posted',
        ]);

        $response = $this->controller->post(id: 'tx-1');
        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testPostHappyPath()

    /**
     * Create returns 201 on success.
     *
     * @return void
     */
    public function testCreateReturns201(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null): mixed {
                return match ($key) {
                    'administration_id'   => 'adm-1',
                    'transactionNumber'   => 'INV-9',
                    'transactionType'     => 'invoice',
                    'transactionDate'     => '2026-01-15',
                    'amount'              => 100.0,
                    'description'         => 'Test',
                    default               => $default,
                };
            }
        );
        $this->rbac->method('hasAny')->willReturn(true);
        $this->transactions->method('createTransaction')->willReturn([
            'id'                => 'tx-9',
            'transactionNumber' => 'INV-9',
            'status'            => 'draft',
        ]);

        $response = $this->controller->create();
        self::assertSame(Http::STATUS_CREATED, $response->getStatus());

    }//end testCreateReturns201()
}//end class
