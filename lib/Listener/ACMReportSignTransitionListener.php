<?php

/**
 * ACMReport Sign Transition Listener.
 *
 * Change shillinq-signing-via-events (REQ-SIGN-001) — wires the declarative
 * `ACMReport.sign` lifecycle transition
 * ({@see lib/Settings/register.d/bookkeeping-market-government-separation.json},
 * `draft` -> `ready-for-submission`) onto the docudesk document e-signature
 * REQUEST path. The transition itself carries no handler in the
 * `x-openregister-lifecycle` block (from/to/label only) — OpenRegister fires
 * {@see \OCA\OpenRegister\Event\ObjectTransitionedEvent} once the transition
 * has already committed, and this listener is the sole production caller of
 * {@see \OCA\Shillinq\Service\Signing\SigningDelegationService::requestSignature()}
 * for the ACMReport schema. Without this listener the request side of the
 * signing delegation never fires: the transport (event dispatch + terminal
 * outcome listener) was correct but orphaned — nothing invoked it.
 *
 * Mirrors the established `*TransitionListener` pattern used elsewhere in
 * shillinq ({@see CommitmentTransitionListener},
 * {@see OrderFulfilmentTransitionListener}): filter
 * `ObjectTransitionedEvent` by schema + transition action, react, fail-soft.
 * Persisting the request-side mirror (`signingRequestRef` / `signingStatus`)
 * follows the lazy-container-ObjectService pattern used by the sibling
 * conclusion listener {@see SigningConcludedListener}.
 *
 * Fail-soft: the `sign` transition has already committed by the time this
 * event fires (the OR lifecycle machinery, not this listener, gates the
 * transition itself); a docudesk outage must not corrupt the ACMReport
 * record. On failure the object is simply left without a
 * `signingRequestRef` — an operator or a retry can re-trigger delegation
 * later. This is distinct from
 * {@see SigningDelegationService::requestSignature()}'s own fail-CLOSED
 * behaviour (it never fabricates a signed status; it throws instead).
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises the docudesk document-signing request when an ACMReport
 * transitions through its `sign` action (REQ-SIGN-001).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
class ACMReportSignTransitionListener implements IEventListener {

	/**
	 * The finance schema this listener reacts to.
	 */
	private const SCHEMA = 'ACMReport';

	/**
	 * The `x-openregister-lifecycle` transition action id
	 * (`draft` -> `ready-for-submission`) that raises the request.
	 */
	private const ACTION = 'sign';

	/**
	 * The signingStatus values that must never trigger a duplicate outbound
	 * request (defensive — the `sign` transition is one-way from `draft`,
	 * so a legitimate re-fire should not occur, but a repeated
	 * ObjectTransitionedEvent delivery must still be a no-op).
	 *
	 * @var array<string>
	 */
	private const ALREADY_REQUESTED_STATUSES = ['requested', 'in-progress', 'signed'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SigningDelegationService $signingService The document-signing request service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SigningDelegationService $signingService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent on the ACMReport `sign` transition.
	 *
	 * @param Event $event OR transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			$this->requestOnSign(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ACMReportSignTransitionListener: signing request failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Raise the signing request for an ACMReport that has an id and has not
	 * been sent for signing yet, and mirror the outcome onto it.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return void
	 */
	private function requestOnSign(ObjectTransitionedEvent $event): void {
		$financeObject = $this->signedReport(event: $event);
		if ($financeObject === null) {
			return;
		}

		if ($financeObject['id'] === '') {
			$this->logger->info(
				'ACMReportSignTransitionListener: no subject id on sign transition (skipping)'
			);
			return;
		}

		if (in_array((string)($financeObject['signingStatus'] ?? ''), self::ALREADY_REQUESTED_STATUSES, true) === true) {
			return;
		}

		$this->raiseRequest(financeObject: $financeObject);

	}//end requestOnSign()

	/**
	 * Request the signature, mirror its status and reference onto the
	 * report, and log it.
	 *
	 * @param array<string,mixed> $financeObject The report, with its subject id.
	 *
	 * @return void
	 */
	private function raiseRequest(array $financeObject): void {
		$updated = $this->signingService->requestSignature(
			financeObject: $financeObject,
			subjectSchema: self::SCHEMA,
			documentClass: 'acm-report'
		);

		$updates = $this->signingUpdates(updated: $updated);
		$this->persist(id: $financeObject['id'], updates: $updates);

		$this->logger->info(
			'ACMReportSignTransitionListener: signing request raised on sign transition',
			[
				'subjectId' => $financeObject['id'],
				'signingRequestRef' => ($updates['signingRequestRef'] ?? null),
			]
		);

	}//end raiseRequest()

	/**
	 * The ACMReport a `sign` transition is about, with its subject id (the
	 * payload id, else the entity id, '' when neither) stamped as `id` so
	 * requestSignature() derives the same subjectId this listener persists
	 * the result back to; null for other actions, schemas or payloads.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return array<string,mixed>|null
	 */
	private function signedReport(ObjectTransitionedEvent $event): array|null {
		$entity = $event->getObject();
		if ($event->getAction() !== self::ACTION || $entity === null) {
			return null;
		}

		if ($this->isAcmReportSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
			return null;
		}

		$financeObject = $entity->getObject();
		if (is_array($financeObject) === false) {
			return null;
		}

		$financeObject['id'] = (string)($financeObject['id'] ?? $entity->getId() ?? '');
		return $financeObject;

	}//end signedReport()

	/**
	 * The signing status (default `requested`) and, when returned, the
	 * request reference to mirror onto the report.
	 *
	 * @param array<string,mixed> $updated The requestSignature() result.
	 *
	 * @return array<string,mixed>
	 */
	private function signingUpdates(array $updated): array {
		$updates = ['signingStatus' => (string)($updated['signingStatus'] ?? 'requested')];
		if (array_key_exists('signingRequestRef', $updated) === true) {
			$updates['signingRequestRef'] = $updated['signingRequestRef'];
		}

		return $updates;

	}//end signingUpdates()

	/**
	 * Check whether the schema slug is ACMReport.
	 *
	 * @param string $schema Schema slug from the event.
	 *
	 * @return bool
	 */
	private function isAcmReportSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'acmreport'
			|| str_ends_with(haystack: $normalised, needle: 'acmreport'));

	}//end isAcmReportSchema()

	/**
	 * Persist the signing-request mirror onto the finance object via OR.
	 *
	 * @param string $id The object id.
	 * @param array<string,mixed> $updates The fields to write.
	 *
	 * @return void
	 */
	private function persist(string $id, array $updates): void {
		$this->objectService
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema(self::SCHEMA)
			->updateObject($id, $updates);

	}//end persist()
}//end class
