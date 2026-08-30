<?php

/**
 * Unit tests for ApprovalActivityEmitter.
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
 * @spec openspec/changes/bookkeeping-rekenkamer-audit-pack/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pin the ApprovalActivityEmitter contract — REQ-RAP-006 requires the user-
 * facing IActivityManager publish surface to mirror the OR audit-trail's
 * tamper-proof channel, with one Activity event per approval / signing /
 * decision transition. Failure is best-effort (logged, not raised).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ApprovalActivityEmitterTest extends TestCase {

	/**
	 * Mock Activity manager.
	 *
	 * @var IActivityManager&MockObject
	 */
	private IActivityManager&MockObject $activityManager;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Captured Activity event.
	 *
	 * @var IEvent&MockObject
	 */
	private IEvent&MockObject $event;

	/**
	 * System under test.
	 *
	 * @var ApprovalActivityEmitter
	 */
	private ApprovalActivityEmitter $emitter;

	/**
	 * Stand up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Build a fluent Event mock — every setter returns $this so the
		// builder chain keeps composing.
		$this->event = $this->createMock(IEvent::class);
		$this->event->method('setApp')->willReturnSelf();
		$this->event->method('setType')->willReturnSelf();
		$this->event->method('setAuthor')->willReturnSelf();
		$this->event->method('setAffectedUser')->willReturnSelf();
		$this->event->method('setTimestamp')->willReturnSelf();
		$this->event->method('setSubject')->willReturnSelf();
		$this->event->method('setObject')->willReturnSelf();
		$this->event->method('setMessage')->willReturnSelf();

		$this->emitter = new ApprovalActivityEmitter(
			$this->activityManager,
			$this->userSession,
			$this->logger
		);

	}//end setUp()

	/**
	 * The five event-type subject ids match the REQ-RAP-006 table exactly.
	 *
	 * @return void
	 */
	public function testEventSubjectConstantsMatchSpec(): void {
		$this->assertSame('approval_requested', ApprovalActivityEmitter::EVENT_APPROVAL_REQUESTED);
		$this->assertSame('approval_approved', ApprovalActivityEmitter::EVENT_APPROVAL_APPROVED);
		$this->assertSame('approval_rejected', ApprovalActivityEmitter::EVENT_APPROVAL_REJECTED);
		$this->assertSame('document_signed', ApprovalActivityEmitter::EVENT_DOCUMENT_SIGNED);
		$this->assertSame('decision_made', ApprovalActivityEmitter::EVENT_DECISION_MADE);
		$this->assertSame('shillinq_decision_lifecycle', ApprovalActivityEmitter::ACTIVITY_TYPE);

	}//end testEventSubjectConstantsMatchSpec()

	/**
	 * emitApprovalRequested publishes one Activity event with the canonical
	 * subject id, the requested actor, the object UUID, and the spec
	 * activity-type bucket.
	 *
	 * @return void
	 */
	public function testEmitApprovalRequestedPublishesOneEvent(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')
			->willReturn($this->event);
		$this->event->expects($this->once())->method('setApp')->with(Application::APP_ID)->willReturnSelf();
		$this->event->expects($this->once())->method('setType')->with('shillinq_decision_lifecycle')->willReturnSelf();
		$this->event->expects($this->once())->method('setSubject')->with(
			'approval_requested',
			['object' => 'AP Invoice 12345']
		)->willReturnSelf();
		$this->event->expects($this->once())->method('setObject')->with(
			'ApprovalRequest',
			0,
			'uuid-1'
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish')->with($this->event);

		$this->emitter->emitApprovalRequested(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-1',
			actorUid: 'bookkeeper1',
			summaryHint: 'AP Invoice 12345'
		);

	}//end testEmitApprovalRequestedPublishesOneEvent()

	/**
	 * When the caller passes actorUid='' the emitter falls back to the
	 * current session user.
	 *
	 * @return void
	 */
	public function testEmitsWithSessionActorWhenActorOmitted(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('session-user');
		$this->userSession->method('getUser')->willReturn($user);

		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setAuthor')->with('session-user')->willReturnSelf();
		$this->event->expects($this->once())->method('setAffectedUser')->with('session-user')->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitApprovalRequested(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-2'
		);

	}//end testEmitsWithSessionActorWhenActorOmitted()

	/**
	 * When the actor is omitted AND no session user is present, the
	 * emitter falls back to 'system' so the Activity event still publishes.
	 *
	 * @return void
	 */
	public function testEmitsWithSystemActorWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setAuthor')->with('system')->willReturnSelf();
		$this->event->expects($this->once())->method('setAffectedUser')->with('system')->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitApprovalRequested(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-3'
		);

	}//end testEmitsWithSystemActorWhenAnonymous()

	/**
	 * emitApprovalApproved carries the actor, the comment, and the
	 * canonical subject id 'approval_approved'.
	 *
	 * @return void
	 */
	public function testEmitApprovalApprovedCarriesActorAndComment(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setSubject')->with(
			'approval_approved',
			['actor' => 'finance-lead', 'object' => 'AP Invoice 12345', 'comment' => 'OK to pay']
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitApprovalApproved(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-1',
			actorUid: 'finance-lead',
			summaryHint: 'AP Invoice 12345',
			comment: 'OK to pay'
		);

	}//end testEmitApprovalApprovedCarriesActorAndComment()

	/**
	 * emitApprovalRejected carries the rejection reason.
	 *
	 * @return void
	 */
	public function testEmitApprovalRejectedCarriesReason(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setSubject')->with(
			'approval_rejected',
			['actor' => 'reviewer', 'object' => 'PO 99', 'reason' => 'budget overrun']
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitApprovalRejected(
			objectType: 'PurchaseOrder',
			objectId: 'uuid-po-99',
			actorUid: 'reviewer',
			summaryHint: 'PO 99',
			reason: 'budget overrun'
		);

	}//end testEmitApprovalRejectedCarriesReason()

	/**
	 * emitDocumentSigned publishes 'document_signed' with the signer actor.
	 *
	 * @return void
	 */
	public function testEmitDocumentSignedPublishesSignerEvent(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setSubject')->with(
			'document_signed',
			['actor' => 'cfo', 'object' => 'Q1 jaarrekening']
		)->willReturnSelf();
		$this->event->expects($this->once())->method('setObject')->with(
			'SignedDocument',
			0,
			'uuid-doc-7'
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitDocumentSigned(
			objectType: 'SignedDocument',
			objectId: 'uuid-doc-7',
			actorUid: 'cfo',
			summaryHint: 'Q1 jaarrekening'
		);

	}//end testEmitDocumentSignedPublishesSignerEvent()

	/**
	 * emitDecisionMade carries the new status as a subject parameter.
	 *
	 * @return void
	 */
	public function testEmitDecisionMadeCarriesNewStatus(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setSubject')->with(
			'decision_made',
			['actor' => 'board', 'object' => 'Budget Q3', 'status' => 'approved']
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitDecisionMade(
			objectType: 'Decision',
			objectId: 'uuid-dec-1',
			actorUid: 'board',
			newStatus: 'approved',
			summaryHint: 'Budget Q3'
		);

	}//end testEmitDecisionMadeCarriesNewStatus()

	/**
	 * Publish failure is logged at INFO level and NOT raised — the OR
	 * audit-trail still captures the lifecycle transition, so Activity
	 * is best-effort.
	 *
	 * @return void
	 */
	public function testPublishFailureIsLoggedNotRaised(): void {
		$this->activityManager->method('generateEvent')->willReturn($this->event);
		$this->activityManager->method('publish')->willThrowException(new \RuntimeException('Activity offline'));

		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('failed to publish Activity event'),
			$this->callback(function (array $ctx): bool {
				return ($ctx['event'] === 'approval_requested')
					&& ($ctx['exception'] === 'Activity offline');
			})
		);

		// No throw — best-effort surface.
		$this->emitter->emitApprovalRequested(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-fail',
			actorUid: 'u'
		);

	}//end testPublishFailureIsLoggedNotRaised()

	/**
	 * Summary falls back to the objectId when summaryHint is empty.
	 *
	 * @return void
	 */
	public function testSummaryFallsBackToObjectIdWhenHintEmpty(): void {
		$this->activityManager->expects($this->once())->method('generateEvent')->willReturn($this->event);
		$this->event->expects($this->once())->method('setSubject')->with(
			'approval_requested',
			['object' => 'uuid-bare']
		)->willReturnSelf();
		$this->activityManager->expects($this->once())->method('publish');

		$this->emitter->emitApprovalRequested(
			objectType: 'ApprovalRequest',
			objectId: 'uuid-bare',
			actorUid: 'u'
		);

	}//end testSummaryFallsBackToObjectIdWhenHintEmpty()
}//end class
