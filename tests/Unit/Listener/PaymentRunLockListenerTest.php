<?php

/**
 * Tests the approved payment run lock.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\PaymentRunLockListener;
use OCA\Shillinq\PaymentRun\PaymentRunApprovalLock;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F2-S1: once a payment run is approved, nothing that decides what the bank
 * file pays may change, and approval stamps the digest the export checks.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 */
final class PaymentRunLockListenerTest extends TestCase {

	/**
	 * A draft run.
	 *
	 * @return array<string,mixed>
	 */
	private function draft(): array {
		return [
			'runNumber' => 'PR-2026-001',
			'administrationId' => 'adm-1',
			'executionDate' => '2026-07-01',
			'debtorAccountIban' => 'NL00BANK9999999999',
			'currency' => 'EUR',
			'totalAmount' => 1497.5,
			'lifecycleState' => 'draft',
			'status' => 'draft',
			'paymentLines' => [
				['payeeId' => 'p1', 'creditorIban' => 'NL00BANK0123456789', 'amount' => 892.5, 'apTransactionRef' => 'a'],
				['payeeId' => 'p2', 'creditorIban' => 'NL00TEST0222222222', 'amount' => 605, 'apTransactionRef' => 'b'],
			],
		];
	}//end draft()

	/**
	 * The same run after approval, as the listener stamps it.
	 *
	 * @return array<string,mixed>
	 */
	private function approved(): array {
		$run = $this->draft();
		$run['lifecycleState'] = 'approved';
		$run['status'] = 'approved';
		$run['approvedLinesDigest'] = (new PaymentRunApprovalLock())->digest(run: $run);
		$run['approvedBy'] = 'approver';
		$run['approvedAt'] = '2026-06-30T10:00:00Z';
		return $run;
	}//end approved()

	/**
	 * Approving a draft stamps the digest of its lines and the approver.
	 *
	 * @return void
	 */
	public function testApprovalStampsTheDigestAndTheApprover(): void {
		$new = $this->draft();
		$new['lifecycleState'] = 'approved';
		$event = $this->updating(old: $this->draft(), new: $new);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$stamp = $event->getModifiedData();
		self::assertSame((new PaymentRunApprovalLock())->digest(run: $new), $stamp['approvedLinesDigest'] ?? null);
		self::assertSame('approver', $stamp['approvedBy'] ?? null);
		self::assertNotEmpty($stamp['approvedAt'] ?? null);
	}//end testApprovalStampsTheDigestAndTheApprover()

	/**
	 * A draft can still be edited.
	 *
	 * @return void
	 */
	public function testADraftCanBeEdited(): void {
		$new = $this->draft();
		$new['paymentLines'][0]['amount'] = 900;
		$event = $this->updating(old: $this->draft(), new: $new);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testADraftCanBeEdited()

	/**
	 * Changing a creditor IBAN after approval is refused.
	 *
	 * @return void
	 */
	public function testAnIbanEditAfterApprovalIsRefused(): void {
		$new = $this->approved();
		$new['paymentLines'][1]['creditorIban'] = 'NL00EVIL0666666666';
		$event = $this->updating(old: $this->approved(), new: $new);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(PaymentRunLockListener::MESSAGE_LOCKED, $event->getErrors()['message'] ?? null);
		self::assertSame(['paymentLines'], $event->getErrors()['fields'] ?? null);
	}//end testAnIbanEditAfterApprovalIsRefused()

	/**
	 * Adding a line, changing the total or the approval stamp after approval is refused.
	 *
	 * @return void
	 */
	public function testOtherLockedFieldsAreRefusedAfterApproval(): void {
		$new = $this->approved();
		$new['paymentLines'][] = ['payeeId' => 'p3', 'creditorIban' => 'NL00X', 'amount' => 10, 'apTransactionRef' => 'c'];
		$new['totalAmount'] = 1507.5;
		$new['approvedLinesDigest'] = (new PaymentRunApprovalLock())->digest(run: $new);
		$event = $this->updating(old: $this->approved(), new: $new);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['totalAmount', 'paymentLines', 'approvedLinesDigest'], $event->getErrors()['fields'] ?? null);
	}//end testOtherLockedFieldsAreRefusedAfterApproval()

	/**
	 * The export and reconcile transitions leave the lines alone and pass.
	 *
	 * @return void
	 */
	public function testAStateChangeWithTheSameLinesPasses(): void {
		$new = $this->approved();
		$new['lifecycleState'] = 'exported';
		$new['status'] = 'exported';
		$new['exportedAt'] = '2026-07-01T08:00:00Z';
		$new['paymentLines'][1]['amount'] = 605.0;
		$event = $this->updating(old: $this->approved(), new: $new);

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAStateChangeWithTheSameLinesPasses()

	/**
	 * An approved run cannot be written back to draft to reopen its lines.
	 *
	 * @return void
	 */
	public function testAnApprovedRunCannotReturnToDraft(): void {
		$new = $this->approved();
		$new['lifecycleState'] = 'draft';
		$event = $this->updating(old: $this->approved(), new: $new);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(PaymentRunLockListener::MESSAGE_BACK_TO_DRAFT, $event->getErrors()['message'] ?? null);
	}//end testAnApprovedRunCannotReturnToDraft()

	/**
	 * A draft cannot jump past approval to exported.
	 *
	 * @return void
	 */
	public function testADraftCannotSkipApproval(): void {
		$new = $this->draft();
		$new['lifecycleState'] = 'exported';
		$event = $this->updating(old: $this->draft(), new: $new);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(PaymentRunLockListener::MESSAGE_SKIPS_APPROVAL, $event->getErrors()['message'] ?? null);
	}//end testADraftCannotSkipApproval()

	/**
	 * A run cannot be created already approved.
	 *
	 * @return void
	 */
	public function testARunCannotBeCreatedApproved(): void {
		$entity = $this->entity(data: $this->approved());
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(PaymentRunLockListener::MESSAGE_NOT_DRAFT_ON_CREATE, $event->getErrors()['message'] ?? null);
	}//end testARunCannotBeCreatedApproved()

	/**
	 * A run created as a draft passes.
	 *
	 * @return void
	 */
	public function testARunCreatedAsDraftPasses(): void {
		$event = new ObjectCreatingEvent($this->entity(data: $this->draft()));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testARunCreatedAsDraftPasses()

	/**
	 * Other schemas are untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$new = $this->approved();
		$new['paymentLines'] = [];
		$event = $this->updating(old: $this->approved(), new: $new);

		$this->listener(matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreUntouched()

	/**
	 * Build the listener.
	 *
	 * @param bool $matches Whether the written object is a PaymentRun.
	 *
	 * @return PaymentRunLockListener
	 */
	private function listener(bool $matches=true): PaymentRunLockListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn($matches);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('approver');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PaymentRunLockListener(
			lock: new PaymentRunApprovalLock(),
			schemaResolver: $resolver,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * Build an update event.
	 *
	 * @param array<string,mixed> $old The stored run.
	 * @param array<string,mixed> $new The run being written.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity(data: $new), $this->entity(data: $old));
	}//end updating()

	/**
	 * Build a PaymentRun entity.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('PaymentRun');
		$entity->setRegister('shillinq');
		return $entity;
	}//end entity()
}//end class
