<?php

/**
 * Approval Activity Emitter Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-RAP-006 — emit Nextcloud Activity events on approval / signing /
 * decision lifecycle transitions so the `BookkeepingActivityFeed`
 * manifest entry (and the Nextcloud dashboard / email-notification /
 * mobile-push pipelines that consume IActivityManager) surface the
 * lifecycle reliably.
 *
 * The service is deliberately a thin wrapper over `IActivityManager`
 * — every approval / signing transition publishes one Activity event
 * with the canonical message-id set, the actor UID, the object UUID,
 * and a one-line summary. The Activity app handles permission scoping
 * (callers see only events on objects they have read access to per
 * REQ-RAP-006 scenario 2).
 *
 * No app-local activity table is introduced (ADR-022): every event
 * flows through IActivityManager which itself persists into
 * `oc_activity` (Nextcloud core). The OR audit-trail-immutable channel
 * is separate and continues to capture the same lifecycle transition
 * as a hash-chained audit event — Activity is the "user-facing
 * timeline" surface while OR's audit-trail is the "tamper-proof
 * evidence chain". REQ-RAP-006 needs BOTH and they MUST agree.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\Activity\IManager as IActivityManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Emit Nextcloud Activity events for approval / signing / decision
 * lifecycle transitions (REQ-RAP-006).
 *
 * Each `emit*()` method maps to one row of the REQ-RAP-006 event-types
 * table. Callers are services that effect the lifecycle transition —
 * e.g. `PurchaseOrderApprovalService::recordDecision()` calls
 * `emitApprovalApproved()` or `emitApprovalRejected()`.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 */
class ApprovalActivityEmitter {

	/**
	 * Activity event subject id for "approval_requested".
	 *
	 * @var string
	 */
	public const EVENT_APPROVAL_REQUESTED = 'approval_requested';

	/**
	 * Activity event subject id for "approval_approved".
	 *
	 * @var string
	 */
	public const EVENT_APPROVAL_APPROVED = 'approval_approved';

	/**
	 * Activity event subject id for "approval_rejected".
	 *
	 * @var string
	 */
	public const EVENT_APPROVAL_REJECTED = 'approval_rejected';

	/**
	 * Activity event subject id for "document_signed".
	 *
	 * @var string
	 */
	public const EVENT_DOCUMENT_SIGNED = 'document_signed';

	/**
	 * Activity event subject id for "decision_made".
	 *
	 * @var string
	 */
	public const EVENT_DECISION_MADE = 'decision_made';

	/**
	 * Activity type bucket for the bookkeeping decision lifecycle.
	 *
	 * @var string
	 */
	public const ACTIVITY_TYPE = 'shillinq_decision_lifecycle';

	/**
	 * Constructor.
	 *
	 * @param IActivityManager $activityManager Nextcloud Activity app handle.
	 * @param IUserSession $userSession Session for actor fallback.
	 * @param LoggerInterface $logger Logger (Activity publish
	 *                                failure is non-fatal — the
	 *                                OR audit-trail still records
	 *                                the lifecycle event).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IActivityManager $activityManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Emit "approval_requested" — an ApprovalRequest object was created.
	 *
	 * @param string $objectType OR schema slug (e.g. "ApprovalRequest").
	 * @param string $objectId UUID of the object.
	 * @param string $actorUid UID of the creator (defaults to current session).
	 * @param string $summaryHint Free-form 1-line summary (e.g. "AP Invoice #12345").
	 *
	 * @return void
	 */
	public function emitApprovalRequested(string $objectType, string $objectId, string $actorUid = '', string $summaryHint = ''): void {
		$label = $this->labelFor(summaryHint: $summaryHint, objectId: $objectId);

		$this->publish(
			activity: new ApprovalActivity(
				event:       self::EVENT_APPROVAL_REQUESTED,
				objectType:  $objectType,
				objectId:    $objectId,
				actorUid:    $actorUid,
				summary:     sprintf('Approval requested for %s', $label),
				parameters:  ['object' => $label]
			)
		);

	}//end emitApprovalRequested()

	/**
	 * Emit "approval_approved" — an ApprovalRequest / ApprovalTask was approved.
	 *
	 * @param string $objectType OR schema slug.
	 * @param string $objectId UUID.
	 * @param string $actorUid UID of the approver.
	 * @param string $summaryHint Free-form 1-line summary.
	 * @param string $comment Approval comment (optional).
	 *
	 * @return void
	 */
	public function emitApprovalApproved(string $objectType, string $objectId, string $actorUid, string $summaryHint = '', string $comment = ''): void {
		$label = $this->labelFor(summaryHint: $summaryHint, objectId: $objectId);

		$this->publish(
			activity: new ApprovalActivity(
				event:       self::EVENT_APPROVAL_APPROVED,
				objectType:  $objectType,
				objectId:    $objectId,
				actorUid:    $actorUid,
				summary:     sprintf('%s approved %s', $actorUid, $label),
				parameters:  ['actor' => $actorUid, 'object' => $label, 'comment' => $comment]
			)
		);

	}//end emitApprovalApproved()

	/**
	 * Emit "approval_rejected" — an ApprovalRequest / ApprovalTask was rejected.
	 *
	 * @param string $objectType OR schema slug.
	 * @param string $objectId UUID.
	 * @param string $actorUid UID of the rejecter.
	 * @param string $summaryHint Free-form 1-line summary.
	 * @param string $reason Rejection reason.
	 *
	 * @return void
	 */
	public function emitApprovalRejected(string $objectType, string $objectId, string $actorUid, string $summaryHint = '', string $reason = ''): void {
		$label = $this->labelFor(summaryHint: $summaryHint, objectId: $objectId);

		$this->publish(
			activity: new ApprovalActivity(
				event:       self::EVENT_APPROVAL_REJECTED,
				objectType:  $objectType,
				objectId:    $objectId,
				actorUid:    $actorUid,
				summary:     sprintf('%s rejected %s: %s', $actorUid, $label, $reason),
				parameters:  ['actor' => $actorUid, 'object' => $label, 'reason' => $reason]
			)
		);

	}//end emitApprovalRejected()

	/**
	 * Emit "document_signed" — a SigningAuthority signature was recorded.
	 *
	 * @param string $objectType OR schema slug.
	 * @param string $objectId UUID of the signed document.
	 * @param string $actorUid UID of the signer.
	 * @param string $summaryHint Free-form 1-line summary.
	 *
	 * @return void
	 */
	public function emitDocumentSigned(string $objectType, string $objectId, string $actorUid, string $summaryHint = ''): void {
		$label = $this->labelFor(summaryHint: $summaryHint, objectId: $objectId);

		$this->publish(
			activity: new ApprovalActivity(
				event:       self::EVENT_DOCUMENT_SIGNED,
				objectType:  $objectType,
				objectId:    $objectId,
				actorUid:    $actorUid,
				summary:     sprintf('%s signed %s', $actorUid, $label),
				parameters:  ['actor' => $actorUid, 'object' => $label]
			)
		);

	}//end emitDocumentSigned()

	/**
	 * Emit "decision_made" — a Decision lifecycle state was changed.
	 *
	 * @param string $objectType OR schema slug.
	 * @param string $objectId UUID of the decision object.
	 * @param string $actorUid UID of the deciding party.
	 * @param string $newStatus New decision status.
	 * @param string $summaryHint Free-form 1-line summary.
	 *
	 * @return void
	 */
	public function emitDecisionMade(string $objectType, string $objectId, string $actorUid, string $newStatus, string $summaryHint = ''): void {
		$label = $this->labelFor(summaryHint: $summaryHint, objectId: $objectId);

		$this->publish(
			activity: new ApprovalActivity(
				event:       self::EVENT_DECISION_MADE,
				objectType:  $objectType,
				objectId:    $objectId,
				actorUid:    $actorUid,
				summary:     sprintf('%s made decision on %s: %s', $actorUid, $label, $newStatus),
				parameters:  ['actor' => $actorUid, 'object' => $label, 'status' => $newStatus]
			)
		);

	}//end emitDecisionMade()

	/**
	 * The label of an object: the summary hint, or the object id when empty.
	 *
	 * @param string $summaryHint Free-form 1-line summary.
	 * @param string $objectId    Object UUID.
	 *
	 * @return string The label.
	 */
	private function labelFor(string $summaryHint, string $objectId): string {
		if ($summaryHint !== '') {
			return $summaryHint;
		}

		return $objectId;

	}//end labelFor()

	/**
	 * Publish one Activity event via IActivityManager.
	 *
	 * Failure is logged but not raised — the OR audit-trail still captures
	 * the lifecycle transition, so the Activity surface is best-effort.
	 *
	 * @param ApprovalActivity $activity The event to publish.
	 *
	 * @return void
	 */
	private function publish(ApprovalActivity $activity): void {
		try {
			$effectiveActor = $this->effectiveActor(actorUid: $activity->actorUid);

			$event = $this->activityManager->generateEvent();
			$event
				->setApp(AppIdentity::APP_ID)
				->setType(self::ACTIVITY_TYPE)
				->setAuthor($effectiveActor)
				->setAffectedUser($effectiveActor)
				->setTimestamp(time())
				->setSubject($activity->event, $activity->parameters)
				->setObject($activity->objectType, 0, $activity->objectId);

			// `setMessage()` is declared on OCP\Activity\IEvent, so the
			// method_exists() probe this replaces could never be false.
			$event->setMessage('summary', ['summary' => $activity->summary]);

			$this->activityManager->publish($event);
		} catch (\Throwable $e) {
			$this->logPublishFailure(activity: $activity, exception: $e);
		}

	}//end publish()

	/**
	 * The actor to record: the given UID, else the session user, else 'system'.
	 *
	 * @param string $actorUid Actor UID ('' = current session).
	 *
	 * @return string The effective actor UID.
	 */
	private function effectiveActor(string $actorUid): string {
		if ($actorUid !== '') {
			return $actorUid;
		}

		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return 'system';

	}//end effectiveActor()

	/**
	 * Log a failed publish (best-effort: never raised).
	 *
	 * @param ApprovalActivity $activity  The event that failed.
	 * @param \Throwable       $exception The failure.
	 *
	 * @return void
	 */
	private function logPublishFailure(ApprovalActivity $activity, \Throwable $exception): void {
		$this->logger->info(
			'ApprovalActivityEmitter: failed to publish Activity event (OR audit-trail still records the transition)',
			[
				'event' => $activity->event,
				'objectType' => $activity->objectType,
				'objectId' => $activity->objectId,
				'exception' => $exception->getMessage(),
			]
		);

	}//end logPublishFailure()
}//end class
