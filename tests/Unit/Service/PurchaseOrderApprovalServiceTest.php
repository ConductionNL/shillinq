<?php

/**
 * Unit tests for PurchaseOrderApprovalService (slice 11 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-010 (audit-trail capture — approver identity +
 * decidedAt):
 *  - recordApprovalDecision() stamps the authenticated user as the
 *    chain entry's userId + decision + decidedAt;
 *  - the lifecycleState advances to `approved` only when every chain
 *    entry is approved; a single rejection sends it to `rejected`;
 *  - cross-tenant calls mask as RuntimeException(`not found`);
 *  - blank/invalid decision is rejected as RuntimeException;
 *  - calling on a PO that is not pending_approval is rejected;
 *  - calling on a fully-signed chain is rejected;
 *  - an unauthenticated session is rejected;
 *  - the comment is trimmed + only kept when non-empty.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OpenRegister-backed approval-decision service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderApprovalServiceTest extends TestCase {
	/**
	 * The single approver path with the authenticated user stamps a
	 * non-empty userId + decidedAt and advances the PO to approved.
	 *
	 * @return void
	 */
	public function testRecordApprovalAdvancesLifecycleWhenChainFullySigned(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						['userId' => '', 'decision' => 'pending'],
					],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'alice'
		);

		$result = $service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-1',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED,
			comment: '  ack — within budget  '
		);

		self::assertSame('approved', $result['lifecycleState']);
		self::assertCount(1, $result['approvalChain']);
		$entry = $result['approvalChain'][0];
		self::assertSame('alice', $entry['userId']);
		self::assertSame('approved', $entry['decision']);
		self::assertNotEmpty($entry['decidedAt']);
		self::assertSame('ack — within budget', $entry['comment']);
		self::assertCount(1, $saved);

	}//end testRecordApprovalAdvancesLifecycleWhenChainFullySigned()

	/**
	 * A chain with two pending entries only advances after BOTH are
	 * approved. The first approval stays in pending_approval.
	 *
	 * @return void
	 */
	public function testRecordApprovalKeepsPendingWhenChainHasMoreEntries(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-2',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						['userId' => '', 'decision' => 'pending'],
						['userId' => '', 'decision' => 'pending'],
					],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'bob'
		);

		$result = $service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-2',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED
		);

		self::assertSame('pending_approval', $result['lifecycleState']);
		self::assertSame('bob', $result['approvalChain'][0]['userId']);
		self::assertSame('approved', $result['approvalChain'][0]['decision']);
		self::assertSame('pending', $result['approvalChain'][1]['decision']);

	}//end testRecordApprovalKeepsPendingWhenChainHasMoreEntries()

	/**
	 * A rejection terminates the lifecycle even with more pending
	 * entries downstream.
	 *
	 * @return void
	 */
	public function testRecordApprovalRejectionTerminatesLifecycle(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-3',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						['userId' => '', 'decision' => 'pending'],
						['userId' => '', 'decision' => 'pending'],
					],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'carol'
		);

		$result = $service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-3',
			decision: PurchaseOrderApprovalService::DECISION_REJECTED,
			comment: 'budget exceeded'
		);

		self::assertSame('rejected', $result['lifecycleState']);
		self::assertSame('carol', $result['approvalChain'][0]['userId']);
		self::assertSame('rejected', $result['approvalChain'][0]['decision']);
		self::assertSame('budget exceeded', $result['approvalChain'][0]['comment']);

	}//end testRecordApprovalRejectionTerminatesLifecycle()

	/**
	 * Cross-tenant access masks as a not-found RuntimeException so the
	 * controller surfaces 404 (ADR-005 IDOR-safe).
	 *
	 * @return void
	 */
	public function testCrossTenantAccessIsMaskedAsNotFound(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-x',
					'administrationId' => 'admin-other',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [['userId' => '', 'decision' => 'pending']],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'mallory'
		);

		$this->expectException(RuntimeException::class);
		$service->recordApprovalDecision(
			administrationId: 'admin-other',
			purchaseOrderId: 'po-x',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED
		);

	}//end testCrossTenantAccessIsMaskedAsNotFound()

	/**
	 * An invalid decision is rejected before any save.
	 *
	 * @return void
	 */
	public function testInvalidDecisionIsRejected(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-4',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [['userId' => '', 'decision' => 'pending']],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'alice'
		);

		$this->expectException(RuntimeException::class);
		$service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-4',
			decision: 'maybe',
		);

	}//end testInvalidDecisionIsRejected()

	/**
	 * Calling on a PO that is not pending_approval is rejected.
	 *
	 * @return void
	 */
	public function testNonPendingPoIsRejected(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-5',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'approved',
					'approvalChain' => [['userId' => 'alice', 'decision' => 'approved']],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'bob'
		);

		$this->expectException(RuntimeException::class);
		$service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-5',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED
		);

	}//end testNonPendingPoIsRejected()

	/**
	 * Calling on a fully-signed chain (no pending entries) is rejected.
	 *
	 * @return void
	 */
	public function testFullySignedChainIsRejected(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-6',
					'administrationId' => 'admin-1',
					// Anomaly: lifecycleState is still pending_approval but
					// every entry is already approved — defensive guard
					// surfaces the bug rather than silently no-op'ing.
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						['userId' => 'alice', 'decision' => 'approved'],
						['userId' => 'bob', 'decision' => 'approved'],
					],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: 'carol'
		);

		$this->expectException(RuntimeException::class);
		$service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-6',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED
		);

	}//end testFullySignedChainIsRejected()

	/**
	 * An unauthenticated session is rejected (defensive — the
	 * controller already blocks 401 before this is reached).
	 *
	 * @return void
	 */
	public function testAnonymousSessionIsRejected(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-7',
					'administrationId' => 'admin-1',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [['userId' => '', 'decision' => 'pending']],
				],
			],
		];

		$saved = [];
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['admin-1'],
			userId: '__ANON__'
		);

		$this->expectException(RuntimeException::class);
		$service->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-7',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED
		);

	}//end testAnonymousSessionIsRejected()

	/**
	 * Build the service over an in-memory OR ObjectService stub seeded
	 * with the supplied schema=>rows map. The `__ANON__` userId sentinel
	 * signals the session mock should return null.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string $userId UID returned by the session (or `__ANON__`).
	 *
	 * @return PurchaseOrderApprovalService
	 */
	private function buildService(
		array $data,
		array &$saved,
		array $accessibleAdministrations,
		string $userId = 'alice',
	): PurchaseOrderApprovalService {
		$stub = $this->objectServiceStub(data: $data, saved: $saved);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$userSession = $this->createMock(IUserSession::class);
		if ($userId === '__ANON__') {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		}

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new PurchaseOrderApprovalService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build an in-memory OpenRegister ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 * @param array<int,array<string,mixed>> $saved Capture ref.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Capture ref.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Apply equality filters to the active schema rows.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Persist an object — capture the save + update the in-memory
			 * store so subsequent finds see the new state.
			 *
			 * @param array<string,mixed> $object Object to persist.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = $object;

				$rows = ($this->data[$this->schema] ?? []);
				$id = (string)($object['id'] ?? '');
				if ($id !== '') {
					foreach ($rows as $index => $row) {
						if ((string)($row['id'] ?? '') === $id) {
							$rows[$index] = $object;
							$this->data[$this->schema] = $rows;
							return $object;
						}
					}
				}

				$rows[] = $object;
				$this->data[$this->schema] = $rows;
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()
}//end class
