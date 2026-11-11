<?php

/**
 * Golden tests for InventoryScanController: refusals, the membership check, role checks, calls and answers.
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
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryScanController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryScanService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the three actions, allowed and refused, and every collaborator call.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryScanControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), member (bool), admin (bool), groups, item, fail (per transaction id).
	 *
	 * @return InventoryScanController
	 */
	private function controller(array $setup=[]): InventoryScanController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('u1');
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $userId) use ($setup): bool {
				$this->calls[] = ['isAdmin', $userId];
				return ($setup['admin'] ?? false);
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $userId, string $group) use ($setup): bool {
				$this->calls[] = ['isInGroup', $userId, $group];
				return in_array($group, ($setup['groups'] ?? []), true);
			}
		);
		$service = $this->createMock(InventoryScanService::class);
		$service->method('resolveBarcode')->willReturnCallback(
			function (string $barcode, string $administrationId) use ($setup): ?array {
				$this->calls[] = ['resolveBarcode', $barcode, $administrationId];
				return ($setup['item'] ?? null);
			}
		);
		$service->method('getStockDelta')->willReturnCallback(
			function (?string $since, string $administrationId): array {
				$this->calls[] = ['getStockDelta', $since, $administrationId];
				return [['sku' => 'S']];
			}
		);
		$service->method('applyOperation')->willReturnCallback(
			function (array $operation, string $userId, string $administrationId) use ($setup): array {
				$this->calls[] = ['applyOperation', $operation, $userId, $administrationId];
				if (isset($setup['fail'][$operation['transactionId']]) === true) {
					throw $setup['fail'][$operation['transactionId']];
				}

				return ['transactionId' => $operation['transactionId'], 'status' => 'accepted'];
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return new InventoryScanController($this->createMock(IRequest::class), $session, $groups, $service, $context, $logger);
	}//end controller()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Resolve: 401, the barcode 400 before the administration checks, then 400 / masked 404, then the lookup.
	 *
	 * @return void
	 */
	public function testResolve(): void {
		$actual = self::answer($this->controller(setup: ['user' => false])->resolve('b', 'a'));
		self::assertSame(expected: [401, ['message' => 'Not logged in']], actual: $actual);
		$actual = self::answer($this->controller(setup: ['member' => false])->resolve('  ', ''));
		self::assertSame(expected: [400, ['message' => 'A barcode or SKU is required.']], actual: $actual);
		$actual = self::answer($this->controller()->resolve('b', '  '));
		self::assertSame(expected: [400, ['message' => 'An administrationId is required.']], actual: $actual);
		self::assertSame(expected: [], actual: $this->calls);
		$actual = self::answer($this->controller(setup: ['member' => false])->resolve('b', ' a1 '));
		self::assertSame(expected: [404, ['message' => 'Administration not found']], actual: $actual);
		self::assertSame(expected: [['canAccess', 'a1']], actual: $this->calls);
		self::assertSame(expected: [200, ['found' => false]], actual: self::answer($this->controller()->resolve(' b1 ', 'a1')));
		self::assertSame(expected: ['resolveBarcode', 'b1', 'a1'], actual: end($this->calls));
		$actual = self::answer($this->controller(setup: ['item' => ['sku' => 'X']])->resolve('b1', 'a1'));
		self::assertSame(expected: [200, ['found' => true, 'item' => ['sku' => 'X']]], actual: $actual);
	}//end testResolve()

	/**
	 * Sync: a blank `since` is a full sync; the answer carries the server time.
	 *
	 * @return void
	 */
	public function testSync(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => false])->sync('', 'a1')->getStatus());
		self::assertSame(expected: 404, actual: $this->controller(setup: ['member' => false])->sync('', 'a1')->getStatus());
		$data = $this->controller()->sync('  ', ' a1 ')->getData();
		self::assertSame(expected: ['serverTime', 'stock'], actual: array_keys($data));
		self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', string: $data['serverTime']);
		self::assertSame(expected: ['getStockDelta', null, 'a1'], actual: end($this->calls));
		$this->controller()->sync(' 2026-01-01 ', 'a1');
		self::assertSame(expected: ['getStockDelta', '2026-01-01', 'a1'], actual: end($this->calls));
	}//end testSync()

	/**
	 * Scan: batch refusals before the administration checks.
	 *
	 * @return void
	 */
	public function testScanBatchRefusals(): void {
		self::assertSame(expected: 401, actual: $this->controller(setup: ['user' => false])->scan([['x']], 'a1')->getStatus());
		self::assertSame(expected: [400, ['message' => 'No operations supplied.']], actual: self::answer($this->controller()->scan([], 'a1')));
		$tooMany = [400, ['message' => 'Batch too large; maximum 200 operations per request.']];
		self::assertSame(expected: $tooMany, actual: self::answer($this->controller(setup: ['member' => false])->scan(array_fill(0, 201, []), 'a1')));
		self::assertSame(expected: [], actual: $this->calls);
		self::assertSame(expected: 404, actual: $this->controller(setup: ['member' => false])->scan(array_fill(0, 200, []), 'a1')->getStatus());
	}//end testScanBatchRefusals()

	/**
	 * Scan: every operation gets its own ACK, rejected or applied, in order; roles are re-checked server-side.
	 *
	 * @return void
	 */
	public function testScanOperations(): void {
		$operations = [
			'junk',
			['transactionId' => '  ', 'type' => 'pick'],
			['transactionId' => 't3', 'type' => 'fly'],
			['transactionId' => 't4', 'type' => 'receive'],
			['transactionId' => 't5', 'type' => 'pick'],
			['transactionId' => 't6', 'type' => 'count'],
		];
		$setup = ['groups' => ['shillinq-inventory-operator', 'shillinq-counter'], 'fail' => ['t6' => new \RuntimeException('stock')]];
		$results = $this->controller(setup: $setup)->scan($operations, ' a1 ')->getData()['results'];
		self::assertSame(
			expected: [
				['transactionId' => null, 'status' => 'rejected', 'message' => 'Malformed operation.'],
				['transactionId' => null, 'status' => 'rejected', 'message' => 'Missing transactionId.'],
				['transactionId' => 't3', 'status' => 'rejected', 'message' => 'Unknown operation type.'],
				['transactionId' => 't4', 'status' => 'rejected', 'message' => 'You do not have permission to perform a receive operation.'],
				['transactionId' => 't5', 'status' => 'accepted'],
				['transactionId' => 't6', 'status' => 'rejected', 'message' => 'The operation could not be applied. Please retry.'],
			],
			actual: $results
		);
		self::assertSame(
			expected: [
				['canAccess', 'a1'],
				['isAdmin', 'u1'],
				['isInGroup', 'u1', 'shillinq-warehouse-manager'],
				['info', 'InventoryScanController: permission denied for operation', ['user' => 'u1', 'type' => 'receive', 'transactionId' => 't4']],
				['isAdmin', 'u1'],
				['isInGroup', 'u1', 'shillinq-inventory-operator'],
				['applyOperation', ['transactionId' => 't5', 'type' => 'pick'], 'u1', 'a1'],
				['isAdmin', 'u1'],
				['isInGroup', 'u1', 'shillinq-counter'],
				['applyOperation', ['transactionId' => 't6', 'type' => 'count'], 'u1', 'a1'],
				['error', 'InventoryScanController: operation failed', ['transactionId' => 't6', 'exception' => 'stock']],
			],
			actual: $this->calls
		);
		$this->calls = [];
		$this->controller(setup: ['admin' => true])->scan([['transactionId' => ' t7 ', 'type' => 'receive']], 'a1');
		$actual = $this->calls;
		$expected = [['canAccess', 'a1'], ['isAdmin', 'u1'], ['applyOperation', ['transactionId' => ' t7 ', 'type' => 'receive'], 'u1', 'a1']];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testScanOperations()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
