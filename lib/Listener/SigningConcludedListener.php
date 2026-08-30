<?php

/**
 * Document SigningConcludedEvent Listener.
 *
 * Change shillinq-signing-via-events (REQ-SIGN-001/006) — consumes the terminal
 * document-signing outcome docudesk publishes via
 * {@see \OCA\DocuDesk\Event\SigningConcludedEvent}. Filters to
 * `getSourceApp() === 'shillinq'`, resolves the originating finance object
 * (ACMReport / AnnualReport / ManagementLetter) by the externalReference /
 * subjectId we sent on the matching DocumentSigningRequestedEvent, and projects
 * the `signed` / `declined` / `expired` / `cancelled` status onto it via
 * {@see \OCA\Shillinq\Service\Signing\SigningDelegationService::onSigningCallback}.
 *
 * The accounting CONSEQUENCE stays in shillinq (REQ-SIGN-006): on `signed` the
 * consequence callback opens the finance submission gate (the report becomes
 * submittable) through the existing OR write path. shillinq owns no signing
 * engine — it only records the docudesk outcome and fires its own GL/submission
 * consequence exactly once (idempotent).
 *
 * Fail-soft: a lookup/projection error logs but is never rethrown into
 * docudesk's synchronous dispatch — recording an already-completed signature
 * must not crash docudesk. (This is distinct from the fail-CLOSED request path
 * in SigningDelegationService::requestSignature, where a missing docudesk MUST
 * block.)
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
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project a concluded docudesk signing request onto the originating shillinq
 * finance object and fire the local GL/submission consequence on `signed`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md (REQ-SIGN-001/006)
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class SigningConcludedListener implements IEventListener {

	/**
	 * Finance schemas that carry the document-signing consumer field set.
	 * Matched against the concluded event's subjectSchema, with a fallback
	 * scan when the schema is absent.
	 *
	 * @var array<string>
	 */
	private const SUBJECT_SCHEMAS = ['ACMReport', 'AnnualReport', 'ManagementLetter'];

	/**
	 * Docudesk statuses that map to a terminal shillinq signingStatus. A
	 * `cancelled` request is, like an `expired` one, a non-completing terminal
	 * outcome that must not open the submission gate, so it maps to `expired`.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_MAP = [
		'signed' => 'signed',
		'declined' => 'declined',
		'expired' => 'expired',
		'cancelled' => 'expired',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SigningDelegationService $signingService The document-signing consumer service.
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
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle a docudesk SigningConcludedEvent.
	 *
	 * Fail-soft: any error logs and returns; never bubbles back into docudesk's
	 * dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		// Only react to the docudesk SigningConcludedEvent. Guarded by
		// class_exists so the listener is inert when docudesk is absent.
		if (class_exists(\OCA\DocuDesk\Event\SigningConcludedEvent::class) === false) {
			return;
		}

		if (($event instanceof \OCA\DocuDesk\Event\SigningConcludedEvent) === false) {
			return;
		}

		try {
			// Filter to shillinq-originated signing requests only.
			if ($event->getSourceApp() !== 'shillinq') {
				return;
			}

			$outcome = self::STATUS_MAP[$event->getStatus()] ?? null;
			if ($outcome === null) {
				// Unknown / non-terminal status — no projection.
				return;
			}

			$signingRequestRef = (string)$event->getSigningRequestId();
			$subjectId = $this->resolveSubjectId(event: $event);
			if ($subjectId === '') {
				$this->logger->info(
					'SigningConcludedListener: no subject id on concluded signing request (skipping)',
					['signingRequestRef' => $signingRequestRef]
				);
				return;
			}

			$resolved = $this->resolveFinanceObject(event: $event, subjectId: $subjectId);
			if ($resolved === null) {
				$this->logger->info(
					'SigningConcludedListener: no matching finance object (skipping)',
					['signingRequestRef' => $signingRequestRef, 'subjectId' => $subjectId]
				);
				return;
			}

			[$schema, $financeObject] = $resolved;

			$signedDocumentRef = (string)$event->getSignedDocumentRef();

			// Capture the accounting-consequence mutation so it can be persisted
			// alongside the mirror. onSigningCallback owns the idempotency guard
			// and fires the consequence exactly once on 'signed'; a repeated
			// conclusion is a no-op and $consequence stays empty.
			$consequence = [];
			if ($signedDocumentRef !== '') {
				$signedDocumentValue = $signedDocumentRef;
			} else {
				$signedDocumentValue = null;
			}

			$updated = $this->signingService->onSigningCallback(
				$financeObject,
				$outcome,
				$signingRequestRef,
				null,
				null,
				$signedDocumentValue,
				function (array $object) use ($schema, $outcome, &$consequence): array {
					$object = $this->applyAccountingConsequence(schema: $schema, object: $object, outcome: $outcome);
					$consequence = $this->consequenceDelta(object: $object, outcome: $outcome);
					return $object;
				},
				// Activity object type for the REQ-RAP-006 `document_signed`
				// event raised inside onSigningCallback(). This listener is the
				// sole production caller, so omitting it here would leave that
				// event permanently unemitted.
				$schema,
			);

			// Persist the mirror (signingRequestRef + signingStatus +
			// signedDocumentRef) plus any local accounting-consequence delta
			// (REQ-SIGN-006) through OR.
			$updates = [
				'signingRequestRef' => $updated['signingRequestRef'] ?? $signingRequestRef,
				'signingStatus' => $updated['signingStatus'] ?? $outcome,
			];
			if (array_key_exists('signedDocumentRef', $updated) === true) {
				$updates['signedDocumentRef'] = $updated['signedDocumentRef'];
			}

			$this->persist(schema: $schema, id: $subjectId, updates: ($updates + $consequence));

			$this->logger->info(
				'SigningConcludedListener: outcome consumed',
				[
					'schema' => $schema,
					'subjectId' => $subjectId,
					'outcome' => $outcome,
					'signingRequestRef' => $signingRequestRef,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SigningConcludedListener: projection failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve the subject id from the concluded event — prefer the
	 * externalReference we sent on the request, fall back to subjectId.
	 *
	 * @param \OCA\DocuDesk\Event\SigningConcludedEvent $event The concluded event.
	 *
	 * @return string The subject id, or '' when none.
	 */
	private function resolveSubjectId(\OCA\DocuDesk\Event\SigningConcludedEvent $event): string {
		$external = (string)$event->getExternalReference();
		if ($external !== '') {
			return $external;
		}

		return (string)($event->getSubjectId() ?? '');
	}//end resolveSubjectId()

	/**
	 * Resolve the finance object the concluded signing request belongs to.
	 *
	 * Uses the event's subjectSchema when present; otherwise scans the known
	 * document-signing subject schemas for the id.
	 *
	 * @param \OCA\DocuDesk\Event\SigningConcludedEvent $event The concluded event.
	 * @param string $subjectId The finance object id.
	 *
	 * @return array{0:string,1:array<string,mixed>}|null [schema, object] or null.
	 */
	private function resolveFinanceObject(\OCA\DocuDesk\Event\SigningConcludedEvent $event, string $subjectId): ?array {
		$hintedSchema = (string)($event->getSubjectSchema() ?? '');

		$schemas = self::SUBJECT_SCHEMAS;
		if ($hintedSchema !== '') {
			// Try the hinted schema first.
			array_unshift($schemas, $hintedSchema);
			$schemas = array_values(array_unique($schemas));
		}

		foreach ($schemas as $schema) {
			$object = $this->findObject(schema: $schema, id: $subjectId);
			if ($object !== null) {
				return [$schema, $object];
			}
		}

		return null;
	}//end resolveFinanceObject()

	/**
	 * Apply the local accounting consequence on a completed signature
	 * (REQ-SIGN-006). The consequence stays in shillinq: on `signed` the finance
	 * submission gate opens through the existing OR write path so the report
	 * becomes submittable. On any non-signed outcome no gate opens.
	 *
	 * @param string $schema The finance schema.
	 * @param array<string,mixed> $object The finance object (already carrying the mirror).
	 * @param string $outcome 'signed' | 'declined' | 'expired'.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	private function applyAccountingConsequence(string $schema, array $object, string $outcome): array {
		if ($outcome !== 'signed') {
			return $object;
		}

		// The accounting consequence opens the finance submission gate through
		// the existing OR write path. shillinq owns no signing engine — it
		// records the docudesk outcome and opens its own downstream gate
		// (submission) exactly once.
		$object['signedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$object['submissionGateOpen'] = true;

		$this->logger->info(
			'SigningConcludedListener: accounting consequence applied',
			[
				'schema' => $schema,
				'outcome' => $outcome,
			]
		);

		return $object;
	}//end applyAccountingConsequence()

	/**
	 * Extract the accounting-consequence fields written by
	 * {@see self::applyAccountingConsequence} so they can be persisted with the
	 * mirror in a single OR write.
	 *
	 * @param array<string,mixed> $object The finance object after the consequence.
	 * @param string $outcome 'signed' | 'declined' | 'expired'.
	 *
	 * @return array<string,mixed> The consequence delta (empty on non-signed).
	 */
	private function consequenceDelta(array $object, string $outcome): array {
		if ($outcome !== 'signed') {
			return [];
		}

		$delta = [];
		foreach (['signedAt', 'submissionGateOpen'] as $key) {
			if (array_key_exists($key, $object) === true) {
				$delta[$key] = $object[$key];
			}
		}

		return $delta;
	}//end consequenceDelta()

	/**
	 * Find a finance object by id within a schema, returning a plain array.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findObject(string $schema, string $id): ?array {
		try {
			$result = $this->objectService
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->find($id);

			return $this->toArray(result: $result);
		} catch (Throwable $e) {
			return null;
		}

	}//end findObject()

	/**
	 * Persist the mirror updates onto the finance object via OR.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param array<string,mixed> $updates The fields to write.
	 *
	 * @return void
	 */
	private function persist(string $schema, string $id, array $updates): void {
		$this->objectService
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema($schema)
			->updateObject($id, $updates);

	}//end persist()

	/**
	 * Normalise an OR find result to a plain array.
	 *
	 * @param mixed $result OR return value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function toArray(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		return null;
	}//end toArray()
}//end class
