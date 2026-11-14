<?php

/**
 * Characterisation tests for PurchaseOrderApprovalService::recordApprovalDecision.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the refusal order and messages, the signed entry, the next state and
 * the activity event of one approval decision.
 */
final class PurchaseOrderDecisionStepsTest extends TestCase {

	/**
	 * Activity calls the emitter double received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * Every refusal, in the order the checks run, with its exact message;
	 * none of them saves.
	 *
	 * @return void
	 */
	public function testRefusalsRunInOrderWithTheirMessages(): void {
		$rows = [
			$this->po(id: 'po-ok', chain: [['decision' => 'pending']]),
			$this->po(id: 'po-approved', chain: [['decision' => 'pending']], status: 'approved'),
			$this->po(id: 'po-empty', chain: []),
			$this->po(id: 'po-signed', chain: ['junk', ['decision' => 'approved'], ['status' => 'approved']]),
		];
		$cases = [
			['', 'po-ok', 'approved', 'alice'],
			['admin-other', '', 'maybe', 'alice'],
			['admin-1', '', 'maybe', 'alice'],
			['admin-1', 'po-ok', 'maybe', 'alice'],
			['admin-1', 'po-missing', 'approved', 'alice'],
			['admin-1', 'po-approved', 'approved', null],
			['admin-1', 'po-empty', 'approved', null],
			['admin-1', 'po-signed', 'approved', null],
			['admin-1', 'po-signed', 'approved', 'alice'],
		];

		$messages = [];
		$saved    = [];
		foreach ($cases as [$admin, $poId, $decision, $user]) {
			$service = $this->service(rows: $rows, user: $user, saved: $saved);
			try {
				$service->recordApprovalDecision($admin, $poId, $decision);
				$messages[] = 'accepted';
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'administrationId is required',
				'Administration not found',
				'Purchase order not found',
				'Invalid approval decision',
				'Purchase order not found',
				'Purchase order is not pending approval',
				'Approval chain is empty',
				'Approval decision requires an authenticated user',
				'Approval chain is fully signed',
			],
			$messages
		);
		self::assertSame([], $saved);
		self::assertSame([], $this->events);

	}//end testRefusalsRunInOrderWithTheirMessages()

	/**
	 * An approval signs only the first pending entry, moves a legacy entry
	 * onto the declared shape, keeps a trimmed comment and stays draft while
	 * another entry waits; the approved event carries the PO number.
	 *
	 * @return void
	 */
	public function testApprovalSignsTheFirstPendingEntry(): void {
		$chain = [
			'junk',
			['decision' => 'approved', 'userId' => 'bob'],
			['status' => 'pending', 'signedAt' => 'x', 'signedBy' => 'y', 'role' => 'cfo'],
			['decision' => 'pending'],
		];
		$saved   = [];
		$service = $this->service(rows: [$this->po(id: 'po-1', chain: $chain, number: 'PO-7')], user: 'alice', saved: $saved);

		$result = $service->recordApprovalDecision('admin-1', 'po-1', 'approved', '  fine  ');

		self::assertSame('draft', $result['statusCode']);
		self::assertSame('junk', $result['approvalChain'][0]);
		self::assertSame(['decision' => 'approved', 'userId' => 'bob'], $result['approvalChain'][1]);
		$signed = $result['approvalChain'][2];
		self::assertSame(['role', 'userId', 'decision', 'decidedAt', 'comment'], array_keys($signed));
		self::assertSame('cfo', $signed['role']);
		self::assertSame('alice', $signed['userId']);
		self::assertSame('approved', $signed['decision']);
		self::assertSame('fine', $signed['comment']);
		self::assertSame(['decision' => 'pending'], $result['approvalChain'][3]);
		self::assertCount(1, $saved);
		self::assertSame(
			[['approved', 'PurchaseOrder', 'po-1', 'alice', 'Purchase order PO-7', '  fine  ']],
			$this->events
		);

	}//end testApprovalSignsTheFirstPendingEntry()

	/**
	 * The last approval moves the PO to approved, skipping non-array entries
	 * in the completeness check; an empty comment is not written.
	 *
	 * @return void
	 */
	public function testLastApprovalApproves(): void {
		$saved   = [];
		$service = $this->service(rows: [$this->po(id: 'po-2', chain: ['junk', ['decision' => 'approved'], ['decision' => 'pending']])], user: 'alice', saved: $saved);

		$result = $service->recordApprovalDecision('admin-1', 'po-2', 'approved', '   ');

		self::assertSame('approved', $result['statusCode']);
		self::assertSame(['decision', 'userId', 'decidedAt'], array_keys($result['approvalChain'][2]));
		self::assertSame([['approved', 'PurchaseOrder', 'po-2', 'alice', 'Purchase order po-2', '   ']], $this->events);

	}//end testLastApprovalApproves()

	/**
	 * A rejection cancels and emits the rejected event with the comment as
	 * reason; a delegation stays draft and emits nothing.
	 *
	 * @return void
	 */
	public function testRejectionCancelsAndDelegationWaits(): void {
		$saved   = [];
		$service = $this->service(rows: [$this->po(id: 'po-3', chain: [['decision' => 'pending'], ['decision' => 'pending']])], user: 'carol', saved: $saved);

		$rejected = $service->recordApprovalDecision('admin-1', 'po-3', 'rejected');
		self::assertSame('cancelled', $rejected['statusCode']);
		self::assertSame([['rejected', 'PurchaseOrder', 'po-3', 'carol', 'Purchase order po-3', '']], $this->events);

		$this->events = [];
		$service      = $this->service(rows: [$this->po(id: 'po-4', chain: [['decision' => 'pending']])], user: 'carol', saved: $saved);
		$delegated    = $service->recordApprovalDecision('admin-1', 'po-4', 'delegated', 'to dave');
		self::assertSame('draft', $delegated['statusCode']);
		self::assertSame('delegated', $delegated['approvalChain'][0]['decision']);
		self::assertSame('to dave', $delegated['approvalChain'][0]['comment']);
		self::assertSame([], $this->events);
		self::assertCount(2, $saved);

	}//end testRejectionCancelsAndDelegationWaits()

	/**
	 * Without an emitter the decision still saves.
	 *
	 * @return void
	 */
	public function testNoEmitterStillSaves(): void {
		$saved   = [];
		$service = $this->service(rows: [$this->po(id: 'po-5', chain: [['decision' => 'pending']])], user: 'alice', saved: $saved, withEmitter: false);

		self::assertSame('approved', $service->recordApprovalDecision('admin-1', 'po-5', 'approved')['statusCode']);
		self::assertCount(1, $saved);

	}//end testNoEmitterStillSaves()

	/**
	 * One purchase order row in admin-1.
	 *
	 * @param string            $id     The PO id.
	 * @param array<int, mixed> $chain  The approval chain.
	 * @param string            $status The statusCode.
	 * @param string|null       $number The poNumber, when set.
	 *
	 * @return array<string, mixed>
	 */
	private function po(string $id, array $chain, string $status = 'draft', ?string $number = null): array {
		$row = ['id' => $id, 'administrationId' => 'admin-1', 'statusCode' => $status, 'approvalChain' => $chain];
		if ($number !== null) {
			$row['poNumber'] = $number;
		}

		return $row;

	}//end po()

	/**
	 * The service over in-memory rows; only admin-1 is accessible.
	 *
	 * @param array<int, array<string, mixed>> $rows        PurchaseOrder rows.
	 * @param string|null                      $user        The session user, or null.
	 * @param array<int, mixed>                $saved       Save sink.
	 * @param bool                             $withEmitter Whether an emitter is wired.
	 *
	 * @return PurchaseOrderApprovalService
	 */
	private function service(array $rows, ?string $user, array &$saved, bool $withEmitter = true): PurchaseOrderApprovalService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$session = $this->createMock(IUserSession::class);
		$iUser   = null;
		if ($user !== null) {
			$iUser = $this->createMock(IUser::class);
			$iUser->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($iUser);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'admin-1');

		$emitter = null;
		if ($withEmitter === true) {
			$emitter = $this->createMock(ApprovalActivityEmitter::class);
			$emitter->method('emitApprovalApproved')->willReturnCallback(
				function (string $type, string $id, string $actor, string $summary = '', string $comment = ''): void {
					$this->events[] = ['approved', $type, $id, $actor, $summary, $comment];
				}
			);
			$emitter->method('emitApprovalRejected')->willReturnCallback(
				function (string $type, string $id, string $actor, string $summary = '', string $reason = ''): void {
					$this->events[] = ['rejected', $type, $id, $actor, $summary, $reason];
				}
			);
		}

		return new PurchaseOrderApprovalService(
			appConfig: $appConfig,
			administrationContext: $context,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new InMemoryObjectServiceStub(data: ['PurchaseOrder' => $rows], saveSink: $saved),
			activityEmitter: $emitter,
		);

	}//end service()
}//end class
