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
use OCA\Shillinq\Service\AdministrationContextService;
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
class InventoryScanControllerTest extends TestCase {

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
	 * Mock AdministrationContextService — the ADR-005 membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * What canAccess() answers. Flipped by the ADR-005 refusal tests.
	 *
	 * Read through a callback rather than re-stubbed per test: a second
	 * `->method('canAccess')` APPENDS a matcher instead of replacing the first.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->scanService = $this->createMock(InventoryScanService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return InventoryScanController
	 */
	private function controller(): InventoryScanController {
		return new InventoryScanController(
			request: $this->request,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			scanService: $this->scanService,
			context: $this->context,
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
	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end loginAs()

	/**
	 * Unauthenticated scan is rejected with 401.
	 *
	 * @return void
	 */
	public function testScanRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller()->scan([['type' => 'receive', 'transactionId' => 't1']], 'adm-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testScanRejectsAnonymous()

	/**
	 * A non-member is rejected for the operation (REQ-PERM-001).
	 *
	 * @return void
	 */
	public function testScanRejectsUserWithoutRole(): void {
		$this->loginAs('bob');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		// The service must never be reached when permission is denied.
		$this->scanService->expects($this->never())->method('applyOperation');

		$response = $this->controller()->scan(
			[['type' => 'receive', 'transactionId' => 't1', 'sku' => 'X', 'location' => 'L']],
			'adm-1'
		);

		$data = $response->getData();
		$this->assertSame('rejected', $data['results'][0]['status']);

	}//end testScanRejectsUserWithoutRole()

	/**
	 * An admin may perform any operation (admin override).
	 *
	 * @return void
	 */
	public function testScanAllowsAdmin(): void {
		$this->loginAs('root');
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->scanService->expects($this->once())
			->method('applyOperation')
			->willReturn(['status' => 'applied', 'transactionId' => 't1', 'syncedAt' => 'now', 'resultingQuantity' => 95.0]);

		$response = $this->controller()->scan(
			[['type' => 'receive', 'transactionId' => 't1', 'sku' => 'X', 'location' => 'L', 'quantity' => 50]],
			'adm-1'
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
	public function testScanUsesSessionUserNotBody(): void {
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
		$op = [
			'type' => 'transfer',
			'transactionId' => 't1',
			'sku' => 'X',
			'location' => 'L1',
			'toLocation' => 'L2',
			'quantity' => 5,
			'performedBy' => 'mallory',
		];
		$response = $this->controller()->scan([$op], 'adm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testScanUsesSessionUserNotBody()

	/**
	 * An operation with no transactionId is rejected before the service is called.
	 *
	 * @return void
	 */
	public function testScanRejectsMissingTransactionId(): void {
		$this->loginAs('alice');
		$this->scanService->expects($this->never())->method('applyOperation');

		$response = $this->controller()->scan([['type' => 'receive', 'sku' => 'X', 'location' => 'L']], 'adm-1');
		$data = $response->getData();
		$this->assertSame('rejected', $data['results'][0]['status']);

	}//end testScanRejectsMissingTransactionId()

	/**
	 * An empty batch is a 400.
	 *
	 * @return void
	 */
	public function testScanRejectsEmptyBatch(): void {
		$this->loginAs('alice');
		$response = $this->controller()->scan([], 'adm-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testScanRejectsEmptyBatch()

	/**
	 * An oversized batch is a 400.
	 *
	 * @return void
	 */
	public function testScanRejectsOversizedBatch(): void {
		$this->loginAs('alice');
		$batch = array_fill(0, 201, ['type' => 'count', 'transactionId' => 't', 'sku' => 'X', 'location' => 'L']);
		$response = $this->controller()->scan($batch, 'adm-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testScanRejectsOversizedBatch()

	/**
	 * Resolve returns found / not-found.
	 *
	 * @return void
	 */
	public function testResolveReturnsItemOrNotFound(): void {
		$this->loginAs('alice');
		$this->scanService->method('resolveBarcode')
			->willReturnOnConsecutiveCalls(['sku' => 'WIDGET-001', 'name' => 'Blue Widget'], null);

		$found = $this->controller()->resolve('5901234123457', 'adm-1');
		$this->assertTrue($found->getData()['found']);
		$this->assertSame('WIDGET-001', $found->getData()['item']['sku']);

		$missing = $this->controller()->resolve('NOPE', 'adm-1');
		$this->assertFalse($missing->getData()['found']);

	}//end testResolveReturnsItemOrNotFound()

	/**
	 * Resolve rejects an empty barcode (400).
	 *
	 * @return void
	 */
	public function testResolveRejectsEmptyBarcode(): void {
		$this->loginAs('alice');
		$response = $this->controller()->resolve('', 'adm-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testResolveRejectsEmptyBarcode()

	/**
	 * Sync returns the stock delta with a server timestamp.
	 *
	 * @return void
	 */
	public function testSyncReturnsStockDelta(): void {
		$this->loginAs('alice');
		$this->scanService->method('getStockDelta')
			->willReturn([['sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 45]]);

		$response = $this->controller()->sync('2026-05-15T00:00:00Z', 'adm-1');
		$data = $response->getData();
		$this->assertArrayHasKey('serverTime', $data);
		$this->assertCount(1, $data['stock']);

	}//end testSyncReturnsStockDelta()

	/**
	 * An OMITTED administrationId is a 400, not a silent fallback to 'adm-1' (#518).
	 *
	 * The parameter used to default to the literal `'adm-1'` on resolve(),
	 * sync() and scan(), so a request that named no tenant read and wrote a real
	 * one. A tenant is never a default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.3
	 */
	public function testOmittedAdministrationNoLongerDefaultsToAdm1(): void {
		$this->loginAs('alice');
		$this->scanService->expects($this->never())->method('getStockDelta');
		$this->scanService->expects($this->never())->method('resolveBarcode');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->sync('2026-05-15T00:00:00Z')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->resolve('5901234123457')->getStatus());

	}//end testOmittedAdministrationNoLongerDefaultsToAdm1()

	/**
	 * A foreign administrationId yields 404 on every endpoint (ADR-005 / #518).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.3
	 */
	public function testForeignAdministrationReturns404OnEveryEndpoint(): void {
		$this->loginAs('alice');
		$this->canAccess = false;
		$this->scanService->expects($this->never())->method('getStockDelta');
		$this->scanService->expects($this->never())->method('resolveBarcode');
		$this->scanService->expects($this->never())->method('applyOperation');

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->sync('2026-05-15T00:00:00Z', 'adm-not-mine')->getStatus()
		);
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->resolve('5901234123457', 'adm-not-mine')->getStatus()
		);
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->scan(
				[['type' => 'receive', 'transactionId' => 't1', 'sku' => 'X', 'location' => 'L']],
				'adm-not-mine'
			)->getStatus()
		);

	}//end testForeignAdministrationReturns404OnEveryEndpoint()
}//end class
