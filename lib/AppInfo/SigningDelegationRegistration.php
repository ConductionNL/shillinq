<?php

/**
 * Shillinq SigningDelegationRegistration
 *
 * Registers the four cross-app signing / governance-delegation listeners
 * (REQ-SIGN-001, REQ-SIGN-005, REQ-SIGN-006) that wire shillinq's finance
 * objects onto decidesk's DECISION path and docudesk's DOCUMENT e-signature
 * path.
 *
 * Extracted verbatim from Application::register() — the registrations are
 * unchanged, only their home is. Application had reached phpmd's
 * ExcessiveClassLength threshold, and this block is the most cohesive unit in
 * it: one change (shillinq-signing-via-events /
 * shillinq-delegation-via-events), one pair of collaborating apps, request and
 * outcome halves that must stay registered together. Splitting them apart
 * would be the failure mode this class prevents — registering a REQUEST leg
 * without its OUTCOME leg leaves a signature that is asked for and never
 * projected back.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\ACMReportSignTransitionListener;
use OCA\Shillinq\Listener\AnnualReportSignoffRequestListener;
use OCA\Shillinq\Listener\SigningConcludedListener;
use OCA\Shillinq\Listener\SignoffDecisionConcludedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the signing / delegation request and outcome listeners.
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 */
final class SigningDelegationRegistration {
	/**
	 * Register all four listeners on the app registration context.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Change shillinq-delegation-via-events (REQ-SIGN-005) — the REAL
		// production trigger for SignoffDecisionService::requestSignoff().
		// AnnualReportSignoffRequestListener raises the decidesk adoption
		// Decision the moment an AnnualReport transitions to `opgemaakt`
		// (bestuur-signed) — the single state shared by both `vaststellen`
		// (post-review) and `vaststellenZonderReview` (klein/micro) before
		// the algemene vergadering votes. Idempotent (one request per
		// adoption cycle) and fail-soft at the listener boundary; the
		// fail-CLOSED guarantee (never auto-approve) lives in
		// requestSignoff() itself.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: AnnualReportSignoffRequestListener::class
		);

		// Change shillinq-delegation-via-events (REQ-SIGN-005/006) — consume
		// the terminal governance-decision outcome decidesk publishes via
		// OCA\Decidesk\Event\DecisionConcludedEvent. The sign-off DECISION
		// request is dispatched synchronously from SignoffDecisionService
		// (DecisionRequestedEvent via IEventDispatcher, fail-closed when
		// decidesk is absent); this listener projects the approved/rejected
		// outcome back onto the originating finance object (ACMReport /
		// ActuarialValuation / AnnualReport) and fires the LOCAL GL /
		// lifecycle consequence (the accounting consequence stays in
		// shillinq). The listener filters to getSourceApp()==='shillinq' and
		// is inert when decidesk is not installed (the event never fires).
		// Registering by the decidesk event FQCN is safe even when the class
		// is not autoloadable — NC only needs the string key.
		$context->registerEventListener(
			event: \OCA\Decidesk\Event\DecisionConcludedEvent::class,
			listener: SignoffDecisionConcludedListener::class
		);

		// Change shillinq-signing-via-events (REQ-SIGN-001) — wire the
		// declarative `ACMReport.sign` lifecycle transition (`draft` ->
		// `ready-for-submission`, register.d/bookkeeping-market-government-
		// separation.json) onto the docudesk document e-signature REQUEST
		// path. The transition itself carries no handler; OpenRegister
		// fires ObjectTransitionedEvent once it has committed, and this
		// listener is the sole production caller of
		// SigningDelegationService::requestSignature() for ACMReport.
		// Without this registration the request side never fires (the
		// orphaned-capability defect this change closes).
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: ACMReportSignTransitionListener::class
		);

		// Change shillinq-signing-via-events (REQ-SIGN-001/006) — consume the
		// terminal DOCUMENT e-signature outcome docudesk publishes via
		// OCA\DocuDesk\Event\SigningConcludedEvent. The document signing REQUEST
		// is dispatched synchronously from SigningDelegationService
		// (DocumentSigningRequestedEvent via IEventDispatcher, fail-closed when
		// docudesk is absent — shillinq NEVER signs on local authority); this
		// listener projects the signed/declined/expired/cancelled outcome back
		// onto the originating finance object (ACMReport / AnnualReport /
		// ManagementLetter) and fires the LOCAL submission/GL consequence (the
		// accounting consequence stays in shillinq) exactly once on `signed`.
		// The listener filters to getSourceApp()==='shillinq' and is inert when
		// docudesk is not installed (the event never fires). Registering by the
		// docudesk event FQCN is safe even when the class is not autoloadable —
		// NC only needs the string key.
		$context->registerEventListener(
			event: \OCA\DocuDesk\Event\SigningConcludedEvent::class,
			listener: SigningConcludedListener::class
		);

	}//end register()
}//end class
