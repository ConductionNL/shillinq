<?php

/**
 * Unit tests for InventoryScanController.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T6.6
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryScanController;
use OCA\Shillinq\Service\InventoryScanService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InventoryScanController authorization + sync behaviour.
 *
 * Covers:
 * - Unauthenticated requests are rejected (401).
 * - Role gate: a non-member is rejected per operation type (REQ-PERM-001).
 * - Role gate: an admin may perform any operation (admin override).
 * - Role gate: a member of the right group is permitted.
 * - IDOR: the acting user id comes from the session, never the body.
 * - resolve returns found/not-found.
 * - scan rejects an empty batch and an oversized batch.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryScanControllerTest extends TestCase
{

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
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock InventoryScanService.
     *
     * @var InventoryScanService&MockObject
     */
    private InventoryScanService&MockObject $scanService;

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

        $this->request      = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->scanService  = $this->createMock(InventoryScanService::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return InventoryScanController
     */
    private function controller(): InventoryScanController
    {
        return new InventoryScanController(
            request: $this->request,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            scanService: $this->scanService,
            logger: $this->logger,
        );

    }//end controller()

    /**
     * Make the session return a user with the given uid.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function loginAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end loginAs()

    /**
     * Unauthenticated scan is rejected with 401.
     *
     * @return void
     */
    public function testScanRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller()->scan([['type' => 'receive', 'transactionId' => 't1']]);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testScanRejectsAnonymous()

    /**
     * A non-member is rejected for the operation (REQ-PERM-001).
     *
     * @return void
     */
    public function testScanRejectsUserWithoutRole(): void
    {
        $this->loginAs('bob');
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        // The service must never be reached when permission is denied.
        $this->scanService->expects($this->never())->method('applyOperation');

        $response = $this->controller()->scan(
            [['type' => 'receive', 'transactionId' => 't1', 'sku' => 'X', 'location' => 'L']]
        );

        $data = $response->getData();
        $this->assertSame('rejected', $data['results'][0]['status']);

    }//end testScanRejectsUserWithoutRole()

    /**
     * An admin may perform any operation (admin override).
     *
     * @return void
     */
    public function testScanAllowsAdmin(): void
    {
        $this->loginAs('root');
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->scanService->expects($this->once())
            ->method('applyOperation')
            ->willReturn(['status' => 'applied', 'transactionId' => 't1', 'syncedAt' => 'now', 'resultingQuantity' => 95.0]);

        $response = $this->controller()->scan(
            [['type' => 'receive', 'transactionId' => 't1', 'sku' => 'X', 'location' => 'L', 'quantity' => 50]]
        );

        $data = $response->getData();
        $this->assertSame('applied', $data['results'][0]['status']);

    }//end testScanAllowsAdmin()

    /**
     * A member of the right group is permitted, and the acting user id is taken
     * from the session — never from the body (IDOR-safe).
     *
     * @return void
     */
    public function testScanUsesSessionUserNotBody(): void
    {
        $this->loginAs('alice');
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(true);

        $this->scanService->expects($this->once())
            ->method('applyOperation')
            ->with(
                $this->anything(),
                $this->equalTo('alice'),
                $this->anything()
            )
            ->willReturn(['status' => 'applied', 'transactionId' => 't1', 'syncedAt' => 'now']);

        // The body tries to impersonate 'mallory'; it must be ignored.
        $op       = [
            'type'          => 'transfer',
            'transactionId' => 't1',
            'sku'           => 'X',
            'location'      => 'L1',
            'toLocation'    => 'L2',
            'quantity'      => 5,
            'performedBy'   => 'mallory',
        ];
        $response = $this->controller()->scan([$op]);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testScanUsesSessionUserNotBody()

    /**
     * An operation with no transactionId is rejected before the service is called.
     *
     * @return void
     */
    public function testScanRejectsMissingTransactionId(): void
    {
        $this->loginAs('alice');
        $this->scanService->expects($this->never())->method('applyOperation');

        $response = $this->controller()->scan([['type' => 'receive', 'sku' => 'X', 'location' => 'L']]);
        $data     = $response->getData();
        $this->assertSame('rejected', $data['results'][0]['status']);

    }//end testScanRejectsMissingTransactionId()

    /**
     * An empty batch is a 400.
     *
     * @return void
     */
    public function testScanRejectsEmptyBatch(): void
    {
        $this->loginAs('alice');
        $response = $this->controller()->scan([]);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testScanRejectsEmptyBatch()

    /**
     * An oversized batch is a 400.
     *
     * @return void
     */
    public function testScanRejectsOversizedBatch(): void
    {
        $this->loginAs('alice');
        $batch    = array_fill(0, 201, ['type' => 'count', 'transactionId' => 't', 'sku' => 'X', 'location' => 'L']);
        $response = $this->controller()->scan($batch);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testScanRejectsOversizedBatch()

    /**
     * Resolve returns found / not-found.
     *
     * @return void
     */
    public function testResolveReturnsItemOrNotFound(): void
    {
        $this->loginAs('alice');
        $this->scanService->method('resolveBarcode')
            ->willReturnOnConsecutiveCalls(['sku' => 'WIDGET-001', 'name' => 'Blue Widget'], null);

        $found = $this->controller()->resolve('5901234123457');
        $this->assertTrue($found->getData()['found']);
        $this->assertSame('WIDGET-001', $found->getData()['item']['sku']);

        $missing = $this->controller()->resolve('NOPE');
        $this->assertFalse($missing->getData()['found']);

    }//end testResolveReturnsItemOrNotFound()

    /**
     * Resolve rejects an empty barcode (400).
     *
     * @return void
     */
    public function testResolveRejectsEmptyBarcode(): void
    {
        $this->loginAs('alice');
        $response = $this->controller()->resolve('');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testResolveRejectsEmptyBarcode()

    /**
     * Sync returns the stock delta with a server timestamp.
     *
     * @return void
     */
    public function testSyncReturnsStockDelta(): void
    {
        $this->loginAs('alice');
        $this->scanService->method('getStockDelta')
            ->willReturn([['sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 45]]);

        $response = $this->controller()->sync('2026-05-15T00:00:00Z');
        $data     = $response->getData();
        $this->assertArrayHasKey('serverTime', $data);
        $this->assertCount(1, $data['stock']);

    }//end testSyncReturnsStockDelta()
}//end class
