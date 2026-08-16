<?php

/**
 * Exception Resolution Service.
 *
 * Slice 08 of the bookkeeping-purchase-order-3way chain — owns the
 * resolution side of out-of-tolerance 3-way matches (REQ-PO3W-005).
 * When a ThreeWayMatch carries an `exception_*` status (price, quantity,
 * missing-grn, missing-po, fraud_alert) the crediteuren-administrateur
 * picks one of three dispositions and this service:
 *
 *  1. acceptWithMotivation()   — records resolution_action=accepted +
 *                                resolution_notes; the upstream match is
 *                                treated as approved, the linked invoice
 *                                advances to `approved`.
 *  2. fileDispute()            — composes a UBL CreditNote dispute envelope,
 *                                hands it to openconnector via
 *                                {@see CreditNoteRequestAdapterInterface},
 *                                records resolution_action=credit_note_requested
 *                                and notifies the Inkoper queue. Payment
 *                                stays blocked until the supplier settles
 *                                the dispute.
 *  3. rejectAndBlockPayment()  — records resolution_action=rejected, marks
 *                                the linked invoice rejected and reverses
 *                                any partial GR/IR posting (placeholder
 *                                hook delegated to slice 09's GL settlement).
 *
 * Every disposition stamps `resolvedBy` from the authenticated user session
 * (NEVER from the request body, ADR-005) and a deterministic `resolvedAt`
 * ISO timestamp on the canonical ThreeWayMatch record. Payment is blocked
 * until the match leaves the exception state — the linked invoice stays
 * `exception` for dispute, moves to `approved` for accept-with-motivation
 * and to `rejected` for reject-and-block. The state-machine transitions
 * are written via the real OR ObjectService API (find/findAll/saveObject);
 * no inventive method names ([[or-objectservice-api]]).
 *
 * Administration scope is validated server-side by
 * {@see AdministrationContextService::canAccess()} — cross-tenant calls
 * are masked as RuntimeException(`not found`) and surface as 404 in the
 * controller (ADR-005 IDOR-safe). The matchId is also re-loaded
 * server-side before any resolution write so a forged POST cannot pivot a
 * resolution into another tenant's match record.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\PurchaseOrder\CreditNoteRequestAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\LogCreditNoteRequestAdapter;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Slice 08 — resolution-side workflow for out-of-tolerance ThreeWayMatch
 * records.
 *
 * Public methods:
 *  - acceptWithMotivation(): record resolution_action=accepted + notes.
 *  - fileDispute():          compose + dispatch UBL CreditNote request,
 *                            escalate to Inkoper queue, record
 *                            resolution_action=credit_note_requested.
 *  - rejectAndBlockPayment(): mark invoice rejected, reverse GR/IR (stub),
 *                            record resolution_action=rejected.
 *  - notifyOnException():    dispatch the exception alert when a match
 *                            transitions into an `exception_*` status —
 *                            wired from the matching engine in slice 06
 *                            (kept here so the notification gates live in
 *                            one place and slice 08 owns the notification
 *                            spec ADDED Requirement).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 */
class ExceptionResolutionService {

	/**
	 * Resolution action recorded for accept-with-motivation. The enum value
	 * mirrors the slice-01 ThreeWayMatch.resolutionAction enum.
	 *
	 * @var string
	 */
	public const ACTION_ACCEPTED = 'accepted';

	/**
	 * Resolution action recorded for file-dispute (UBL CreditNote).
	 *
	 * @var string
	 */
	public const ACTION_CREDIT_NOTE_REQUESTED = 'credit_note_requested';

	/**
	 * Resolution action recorded for reject-and-block-payment.
	 *
	 * @var string
	 */
	public const ACTION_REJECTED = 'rejected';

	/**
	 * NC notification object type for exception alerts.
	 *
	 * @var string
	 */
	public const NOTIFICATION_OBJECT_TYPE = 'three_way_match_exception';

	/**
	 * NC notification subject for new exception alerts.
	 *
	 * @var string
	 */
	public const NOTIFICATION_SUBJECT_EXCEPTION = 'three_way_match_exception_raised';

	/**
	 * NC notification subject for dispute-filed escalations.
	 *
	 * @var string
	 */
	public const NOTIFICATION_SUBJECT_DISPUTE = 'three_way_match_dispute_filed';

	/**
	 * Default role looked up for the crediteuren-administrateur notification
	 * queue. Membership rows with this role on the administration receive
	 * the exception alert. Mirrors the PurchaseOrderService approver-lookup
	 * pattern (AdministrationMembership.role).
	 *
	 * @var string
	 */
	public const ROLE_CREDITEUREN_ADMIN = 'crediteuren_administrateur';

	/**
	 * Default role looked up for the Inkoper escalation queue when a
	 * dispute is filed.
	 *
	 * @var string
	 */
	public const ROLE_INKOPER = 'inkoper';

	/**
	 * Match status set is the subset of REQ-MATCH-002 that routes into the
	 * resolution workflow.
	 *
	 * @var array<int,string>
	 */
	public const EXCEPTION_STATUSES = [
		'exception_price',
		'exception_quantity',
		'exception_missing_grn',
		'exception_missing_po',
		'fraud_alert',
	];

	/**
	 * Schema slug for ThreeWayMatch records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * Schema slug for SupplierInvoice records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for ToleranceProfile records (slice 01) — consulted for
	 * the exception_routing field (which queue the alert escalates to).
	 *
	 * @var string
	 */
	private const SCHEMA_TOLERANCE_PROFILE = 'ToleranceProfile';

	/**
	 * Schema slug for administration membership rows (used by both the
	 * accept-with-motivation and file-dispute notifications).
	 *
	 * @var string
	 */
	private const SCHEMA_ADMINISTRATION_MEMBERSHIP = 'AdministrationMembership';

	/**
	 * The adapter port — slice 08 binds {@see LogCreditNoteRequestAdapter}
	 * by default; production swaps in an HTTP-backed openconnector
	 * adapter without touching the orchestration code (mirrors the
	 * PeppolTransmissionAdapterInterface pattern from slice 03).
	 *
	 * @var CreditNoteRequestAdapterInterface
	 */
	private readonly CreditNoteRequestAdapterInterface $creditNoteAdapter;

	/**
	 * Constructor.
	 *
	 *                                      ObjectService is fetched
	 *                                      lazily so unit tests
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR
	 *                              register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession Session for resolvedBy
	 *                                  attribution (server-
	 *                                  authoritative, not body).
	 * @param INotificationManager $notificationManager NC notification dispatcher.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param CreditNoteRequestAdapterInterface|null $creditNoteAdapter Optional UBL CreditNote
	 *                                                                  dispatch port — defaults to
	 *                                                                  {@see LogCreditNoteRequestAdapter}
	 *                                                                  so the orchestration code
	 *                                                                  is testable without
	 *                                                                  openconnector wired.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		?CreditNoteRequestAdapterInterface $creditNoteAdapter = null,
	) {
		$this->creditNoteAdapter = ($creditNoteAdapter ?? new LogCreditNoteRequestAdapter(logger: $logger));

	}//end __construct()

	/**
	 * Record the operator's accept-with-motivation disposition on a
	 * ThreeWayMatch and advance the linked invoice to `approved`.
	 *
	 * The method is server-authoritative:
	 *  - administration scope is validated;
	 *  - the matchId is re-loaded from OR so a forged POST cannot pivot
	 *    to a cross-tenant match record;
	 *  - the match must currently be in one of the
	 *    {@see EXCEPTION_STATUSES} — calling on an already-resolved
	 *    match throws a RuntimeException (the controller maps to 400);
	 *  - resolvedBy is taken from the authenticated user session, never
	 *    from the request body;
	 *  - the resolution stamps resolvedAt deterministically (date('c')).
	 *
	 * Payment is unblocked as a consequence of the linked invoice moving
	 * out of `exception` into `approved`.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $resolutionNotes Operator-supplied motivation
	 *                                (mandatory — empty notes are
	 *                                rejected with a RuntimeException).
	 *
	 * @return array<string,mixed> The updated ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           non-exception match status, or blank
	 *                           motivation.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function acceptWithMotivation(
		string $administrationId,
		string $matchId,
		string $resolutionNotes,
	): array {
		$this->assertAccess(administrationId: $administrationId);
		if ($matchId === '') {
			throw new RuntimeException('ThreeWayMatch not found');
		}

		$notes = trim($resolutionNotes);
		if ($notes === '') {
			throw new RuntimeException('resolutionNotes is required');
		}

		$match = $this->loadMatch(administrationId: $administrationId, matchId: $matchId);
		$this->assertExceptionStatus(match: $match);

		$resolvedBy = $this->currentUserId();
		$resolvedAt = $this->nowIso();

		$match['resolutionAction'] = self::ACTION_ACCEPTED;
		$match['resolutionNotes'] = $notes;
		$match['resolvedBy'] = $resolvedBy;
		$match['resolvedAt'] = $resolvedAt;

		$persisted = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $match);

		// Move the linked invoice out of `exception` into `approved` so
		// payment is unblocked.
		$this->advanceInvoiceStatus(
			administrationId: $administrationId,
			invoiceId: (string)($match['invoiceId'] ?? ''),
			nextStatus: 'approved'
		);

		return $persisted;
	}//end acceptWithMotivation()

	/**
	 * File a dispute on an out-of-tolerance ThreeWayMatch — auto-generate
	 * a UBL CreditNote request via openconnector and escalate to the
	 * Inkoper notification queue. Payment stays blocked until the
	 * supplier settles the dispute (the linked invoice stays in
	 * `exception`).
	 *
	 * The dispute payload is composed server-side from the match + the
	 * linked invoice header. The dispatch is best-effort — a failure of
	 * the openconnector adapter does NOT roll back the ThreeWayMatch
	 * record (the canonical surface for the audit trail) but is logged
	 * and surfaced through the returned envelope so the controller can
	 * report the partial success to the operator.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $disputeReason Human-readable dispute reason
	 *                              (mandatory — empty reason is
	 *                              rejected with a RuntimeException).
	 *
	 * @return array{match:array<string,mixed>,dispatch:array{accepted:bool,dispatchId:?string,error:?string}}
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           missing invoice, non-exception match
	 *                           status, or blank reason.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function fileDispute(
		string $administrationId,
		string $matchId,
		string $disputeReason,
	): array {
		$this->assertAccess(administrationId: $administrationId);
		if ($matchId === '') {
			throw new RuntimeException('ThreeWayMatch not found');
		}

		$reason = trim($disputeReason);
		if ($reason === '') {
			throw new RuntimeException('disputeReason is required');
		}

		$match = $this->loadMatch(administrationId: $administrationId, matchId: $matchId);
		$this->assertExceptionStatus(match: $match);

		$invoiceId = (string)($match['invoiceId'] ?? '');
		if ($invoiceId === '') {
			throw new RuntimeException('Match has no linked invoice');
		}

		$invoice = $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$resolvedBy = $this->currentUserId();
		$resolvedAt = $this->nowIso();

		$payload = $this->buildDisputePayload(
			administrationId: $administrationId,
			match: $match,
			invoice: $invoice,
			invoiceId: $invoiceId,
			reason: $reason,
			requestedAt: $resolvedAt
		);
		$dispatch = $this->dispatchDispute(payload: $payload, match: $match, invoiceId: $invoiceId);

		$resolutionNotes = $reason;
		$dispatchId = ($dispatch['dispatchId'] ?? null);
		if (is_string($dispatchId) === true && $dispatchId !== '') {
			$resolutionNotes .= ' [creditNoteDispatchId=' . $dispatchId . ']';
		}

		$match['resolutionAction'] = self::ACTION_CREDIT_NOTE_REQUESTED;
		$match['resolutionNotes'] = $resolutionNotes;
		$match['resolvedBy'] = $resolvedBy;
		$match['resolvedAt'] = $resolvedAt;

		$persisted = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $match);

		// Payment stays blocked — the invoice remains in `exception`.
		// Escalate to the Inkoper queue.
		$this->notifyRole(
			administrationId: $administrationId,
			role: self::ROLE_INKOPER,
			subject: self::NOTIFICATION_SUBJECT_DISPUTE,
			matchId: $this->idOf(record: $match),
			invoice: $invoice
		);

		return [
			'match' => $persisted,
			'dispatch' => $dispatch,
		];

	}//end fileDispute()

	/**
	 * Reject an out-of-tolerance ThreeWayMatch and block the linked
	 * invoice from payment. Reverses any partial GR/IR posting via the
	 * slice-09 settlement hook (placeholder — slice 09 owns the actual
	 * GL transaction reversal; this method records the resolution and
	 * advances the invoice to `rejected` so the payment block is
	 * effective immediately).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $rejectionReason Operator-supplied rejection reason
	 *                                (mandatory).
	 *
	 * @return array<string,mixed> The updated ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           non-exception match status, or blank
	 *                           reason.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function rejectAndBlockPayment(
		string $administrationId,
		string $matchId,
		string $rejectionReason,
	): array {
		$this->assertAccess(administrationId: $administrationId);
		if ($matchId === '') {
			throw new RuntimeException('ThreeWayMatch not found');
		}

		$reason = trim($rejectionReason);
		if ($reason === '') {
			throw new RuntimeException('rejectionReason is required');
		}

		$match = $this->loadMatch(administrationId: $administrationId, matchId: $matchId);
		$this->assertExceptionStatus(match: $match);

		$resolvedBy = $this->currentUserId();
		$resolvedAt = $this->nowIso();

		$match['resolutionAction'] = self::ACTION_REJECTED;
		$match['resolutionNotes'] = $reason;
		$match['resolvedBy'] = $resolvedBy;
		$match['resolvedAt'] = $resolvedAt;

		$persisted = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $match);

		// Mark the linked invoice rejected so the payment block is
		// effective; slice 09 owns the GR/IR reversal posting + stock
		// restoration. We log a structured marker so the slice-09 engine
		// can pick the reversal up; no GL movement is made here.
		$this->advanceInvoiceStatus(
			administrationId: $administrationId,
			invoiceId: (string)($match['invoiceId'] ?? ''),
			nextStatus: 'rejected'
		);
		$this->logger->info(
			'ExceptionResolutionService: invoice rejected — slice-09 GR/IR reversal pending',
			[
				'matchId' => $this->idOf(record: $match),
				'invoiceId' => (string)($match['invoiceId'] ?? ''),
			]
		);

		return $persisted;
	}//end rejectAndBlockPayment()

	/**
	 * Send the exception alert to the crediteuren-administrateur queue
	 * when a ThreeWayMatch transitions into an `exception_*` status.
	 *
	 * Wired from the matching engine in slice 06 once it ships — slice 08
	 * owns the notification orchestration so the spec stays in one place.
	 * The method is idempotent at the notification layer (NC dedupes on
	 * (app, user, object) but the caller is expected to fire-and-forget).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On cross-tenant access.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function notifyOnException(string $administrationId, string $matchId): void {
		$this->assertAccess(administrationId: $administrationId);

		$match = $this->findOne(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'id' => $matchId,
				'administrationId' => $administrationId,
			]
		);
		if ($match === null) {
			return;
		}

		if (in_array((string)($match['matchStatus'] ?? ''), self::EXCEPTION_STATUSES, true) === false) {
			return;
		}

		$invoiceId = (string)($match['invoiceId'] ?? '');
		$invoice = null;
		if ($invoiceId !== '') {
			$invoice = $this->findOne(
				schema: self::SCHEMA_SUPPLIER_INVOICE,
				filters: [
					'id' => $invoiceId,
					'administrationId' => $administrationId,
				]
			);
		}

		$role = $this->resolveAlertRole(administrationId: $administrationId, match: $match);

		$this->notifyRole(
			administrationId: $administrationId,
			role: $role,
			subject: self::NOTIFICATION_SUBJECT_EXCEPTION,
			matchId: $matchId,
			invoice: $invoice
		);

	}//end notifyOnException()

	/**
	 * Compose the UBL CreditNote dispute envelope from the match + the
	 * linked invoice header. The adapter owns the wire serialisation;
	 * this helper only ships the structural payload needed for the
	 * dispute request so fileDispute() stays under the PHPMD method-length
	 * threshold.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $match ThreeWayMatch record.
	 * @param array<string,mixed> $invoice SupplierInvoice header.
	 * @param string $invoiceId Invoice id.
	 * @param string $reason Dispute reason.
	 * @param string $requestedAt ISO timestamp.
	 *
	 * @return array<string,mixed> The dispute payload envelope.
	 */
	private function buildDisputePayload(
		string $administrationId,
		array $match,
		array $invoice,
		string $invoiceId,
		string $reason,
		string $requestedAt,
	): array {
		return [
			'matchId' => $this->idOf(record: $match),
			'invoiceId' => $invoiceId,
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'supplierId' => (string)($invoice['supplierId'] ?? ''),
			'administrationId' => $administrationId,
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'totalExclVat' => (int)($invoice['totalExclVat'] ?? 0),
			'totalVat' => (int)($invoice['totalVat'] ?? 0),
			'totalInclVat' => (int)($invoice['totalInclVat'] ?? 0),
			'reason' => $reason,
			'divergenceDetails' => ($match['divergenceDetails'] ?? []),
			'matchedPoIds' => ($match['matchedPoIds'] ?? []),
			'requestedAt' => $requestedAt,
		];

	}//end buildDisputePayload()

	/**
	 * Hand the dispute envelope to the credit-note adapter and translate
	 * a thrown exception into the same envelope shape so the caller never
	 * has to unwrap. Best-effort — the canonical record is the
	 * ThreeWayMatch the caller persists immediately after.
	 *
	 * @param array<string,mixed> $payload Dispute envelope.
	 * @param array<string,mixed> $match ThreeWayMatch record (for log context).
	 * @param string $invoiceId Invoice id (for log context).
	 *
	 * @return array{accepted:bool,dispatchId:?string,error:?string}
	 */
	private function dispatchDispute(array $payload, array $match, string $invoiceId): array {
		try {
			return $this->creditNoteAdapter->submitDisputeCreditNote(payload: $payload);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'ExceptionResolutionService: dispute UBL CreditNote dispatch failed',
				[
					'matchId' => $this->idOf(record: $match),
					'invoiceId' => $invoiceId,
					'exception' => $exception->getMessage(),
				]
			);

			return [
				'accepted' => false,
				'dispatchId' => null,
				'error' => $exception->getMessage(),
			];
		}

	}//end dispatchDispute()

	/**
	 * Resolve the membership role that should receive the exception
	 * alert. Defaults to {@see ROLE_CREDITEUREN_ADMIN}; when the match's
	 * divergenceDetails name a ToleranceProfile that defines an
	 * exceptionRouting tag, the routing tag is used verbatim so an
	 * organisation can pivot specific exception classes to a controller
	 * approval queue without changing this code.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $match ThreeWayMatch record.
	 *
	 * @return string The role to notify.
	 */
	private function resolveAlertRole(string $administrationId, array $match): string {
		$divergence = ($match['divergenceDetails'] ?? []);
		if (is_array($divergence) === false) {
			return self::ROLE_CREDITEUREN_ADMIN;
		}

		foreach ($divergence as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$profileId = trim((string)($entry['toleranceProfileId'] ?? ''));
			if ($profileId === '') {
				continue;
			}

			$profile = $this->findOne(
				schema: self::SCHEMA_TOLERANCE_PROFILE,
				filters: [
					'profileId' => $profileId,
					'administrationId' => $administrationId,
				]
			);
			if ($profile === null) {
				continue;
			}

			$routing = trim((string)($profile['exceptionRouting'] ?? ''));
			if ($routing !== '') {
				return $routing;
			}
		}//end foreach

		return self::ROLE_CREDITEUREN_ADMIN;
	}//end resolveAlertRole()

	/**
	 * Notify every member of the named role on the administration with
	 * the given subject. Mirrors the PurchaseOrderService approver-notify
	 * pattern (loop AdministrationMembership rows on (administrationId,
	 * role), createNotification per user, log + continue on failure).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $role Membership role.
	 * @param string $subject NC notification subject.
	 * @param string $matchId ThreeWayMatch id.
	 * @param array<string,mixed>|null $invoice Optional invoice header
	 *                                          for the parameter bag.
	 *
	 * @return void
	 */
	private function notifyRole(
		string $administrationId,
		string $role,
		string $subject,
		string $matchId,
		?array $invoice,
	): void {
		$userIds = $this->findRoleMembers(administrationId: $administrationId, role: $role);
		if ($userIds === []) {
			$this->logger->info(
				'ExceptionResolutionService: no recipients for exception alert',
				[
					'administrationId' => $administrationId,
					'role' => $role,
					'matchId' => $matchId,
				]
			);
			return;
		}

		$parameters = [
			'matchId' => $matchId,
			'invoiceId' => (string)($invoice['id'] ?? ''),
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'supplierId' => (string)($invoice['supplierId'] ?? ''),
			'totalInclVat' => (int)($invoice['totalInclVat'] ?? 0),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
		];

		foreach ($userIds as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification
					->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new DateTime())
					->setObject(self::NOTIFICATION_OBJECT_TYPE, $matchId)
					->setSubject($subject, $parameters);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $exception) {
				$this->logger->warning(
					'ExceptionResolutionService: failed to dispatch exception notification',
					[
						'administrationId' => $administrationId,
						'userId' => $userId,
						'role' => $role,
						'subject' => $subject,
						'matchId' => $matchId,
						'exception' => $exception->getMessage(),
					]
				);
			}//end try
		}//end foreach

	}//end notifyRole()

	/**
	 * Look up AdministrationMembership rows for the (administrationId,
	 * role) pair and return the unique user-id list.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $role Role tag.
	 *
	 * @return array<int,string>
	 */
	private function findRoleMembers(string $administrationId, string $role): array {
		$rows = $this->findAll(
			schema: self::SCHEMA_ADMINISTRATION_MEMBERSHIP,
			filters: [
				'administrationId' => $administrationId,
				'role' => $role,
			]
		);

		$userIds = [];
		foreach ($rows as $row) {
			$userId = trim((string)($row['userId'] ?? ''));
			if ($userId !== '' && in_array($userId, $userIds, true) === false) {
				$userIds[] = $userId;
			}
		}

		return $userIds;
	}//end findRoleMembers()

	/**
	 * Load a ThreeWayMatch by id, scoped to the administration so a
	 * forged id from another tenant masks as "not found" (ADR-005).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the match is not found in the tenant.
	 */
	private function loadMatch(string $administrationId, string $matchId): array {
		$match = $this->findOne(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: [
				'id' => $matchId,
				'administrationId' => $administrationId,
			]
		);
		if ($match === null) {
			throw new RuntimeException('ThreeWayMatch not found');
		}

		return $match;
	}//end loadMatch()

	/**
	 * Assert a match is currently in one of the exception statuses —
	 * calling a resolution on an already-resolved or auto-approved match
	 * is rejected so the audit trail stays clean.
	 *
	 * @param array<string,mixed> $match ThreeWayMatch record.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the match is not in an exception state.
	 */
	private function assertExceptionStatus(array $match): void {
		$status = (string)($match['matchStatus'] ?? '');
		if (in_array($status, self::EXCEPTION_STATUSES, true) === false) {
			throw new RuntimeException('Match is not in an exception state');
		}

	}//end assertExceptionStatus()

	/**
	 * Advance the linked SupplierInvoice to the next status (approved /
	 * rejected) so the payment-block side effect is visible without
	 * waiting for a downstream listener.
	 *
	 * Best-effort — a failure to update the invoice is logged but does
	 * NOT roll back the ThreeWayMatch resolution (the canonical record).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 * @param string $nextStatus Target statusCode (`approved` or
	 *                           `rejected`).
	 *
	 * @return void
	 */
	private function advanceInvoiceStatus(string $administrationId, string $invoiceId, string $nextStatus): void {
		if ($invoiceId === '') {
			return;
		}

		try {
			$invoice = $this->findOne(
				schema: self::SCHEMA_SUPPLIER_INVOICE,
				filters: [
					'id' => $invoiceId,
					'administrationId' => $administrationId,
				]
			);
			if ($invoice === null) {
				return;
			}

			$invoice['statusCode'] = $nextStatus;
			$this->saveObject(schema: self::SCHEMA_SUPPLIER_INVOICE, object: $invoice);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'ExceptionResolutionService: failed to advance invoice status',
				[
					'administrationId' => $administrationId,
					'invoiceId' => $invoiceId,
					'nextStatus' => $nextStatus,
					'exception' => $exception->getMessage(),
				]
			);
		}//end try

	}//end advanceInvoiceStatus()

	/**
	 * Extract a record id, handling both top-level `id` and OR's
	 * `@self.id` envelope.
	 *
	 * @param array<string,mixed> $record OR record.
	 *
	 * @return string
	 */
	private function idOf(array $record): string {
		$id = ($record['id'] ?? ($record['@self']['id'] ?? ''));
		return trim((string)$id);
	}//end idOf()

	/**
	 * Validate the administration scope (ADR-005 IDOR-safe).
	 *
	 * @param string $administrationId Tenant id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()

	/**
	 * Resolve the current user id, falling back to `system` when no
	 * session is bound (e.g. inside a background job firing notifyOnException).
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Persist via the real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object to persist.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ExceptionResolutionService: failed to persist object',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'ExceptionResolutionService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Current timestamp in ISO-8601 (Y-m-d\TH:i:sP) — server-authoritative.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
