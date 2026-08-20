<?php

/**
 * Sign-off DecisionConcludedEvent Listener.
 *
 * Change shillinq-delegation-via-events (REQ-SIGN-005/006) — consumes the
 * terminal governance-decision outcome decidesk publishes via
 * {@see \OCA\Decidesk\Event\DecisionConcludedEvent}. Filters to
 * `getSourceApp() === 'shillinq'`, resolves the originating finance object
 * (ACMReport / ActuarialValuation / AnnualReport) by the externalReference /
 * subjectId we sent on the matching DecisionRequestedEvent, and projects the
 * `approved` / `rejected` outcome onto it via
 * {@see \OCA\Shillinq\Service\Signing\SignoffDecisionService::onDecisionCallback}.
 *
 * The accounting CONSEQUENCE stays in shillinq (REQ-SIGN-006): on `approved`
 * the consequence callback flips the finance lifecycle (the report becomes
 * submittable / the year-end posting runs) through the existing OR write path.
 * shillinq owns no approval state machine — it only records the decidesk
 * outcome and fires its own GL consequence exactly once (idempotent).
 *
 * Fail-soft: a lookup/projection error logs but is never rethrown into
 * decidesk's synchronous dispatch — recording an already-made decision must
 * not crash decidesk. (This is distinct from the fail-CLOSED request path in
 * SignoffDecisionService::requestSignoff, where a missing decidesk MUST block.)
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
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project a concluded decidesk Decision onto the originating shillinq finance
 * object and fire the local GL/lifecycle consequence.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md (REQ-SIGN-005/006)
 */
final class SignoffDecisionConcludedListener implements IEventListener {

	/**
	 * Finance schemas that carry the governance-decision consumer field set.
	 * Matched against the concluded event's subjectSchema, with a fallback
	 * scan when the schema is absent.
	 *
	 * @var array<string>
	 */
	private const SUBJECT_SCHEMAS = ['ACMReport', 'ActuarialValuation', 'AnnualReport'];

	/**
	 * Decidesk statuses that map to a terminal shillinq outcome.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_MAP = [
		'approved' => 'approved',
		'rejected' => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SignoffDecisionService $signoffService The sign-off consumer service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SignoffDecisionService $signoffService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle a decidesk DecisionConcludedEvent.
	 *
	 * Fail-soft: any error logs and returns; never bubbles back into decidesk's
	 * dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		// Only react to the decidesk DecisionConcludedEvent. Guarded by
		// class_exists so the listener is inert when decidesk is absent.
		if (class_exists(\OCA\Decidesk\Event\DecisionConcludedEvent::class) === false) {
			return;
		}

		if (($event instanceof \OCA\Decidesk\Event\DecisionConcludedEvent) === false) {
			return;
		}

		try {
			// Filter to shillinq-originated decisions only.
			if ($event->getSourceApp() !== 'shillinq') {
				return;
			}

			$outcome = self::STATUS_MAP[$event->getStatus()] ?? null;
			if ($outcome === null) {
				// Withdrawn / pending / unknown — no terminal projection.
				return;
			}

			$decisionRef = (string)$event->getDecisionId();
			$subjectId = $this->resolveSubjectId(event: $event);
			if ($subjectId === '') {
				$this->logger->info(
					'SignoffDecisionConcludedListener: no subject id on concluded decision (skipping)',
					['decisionRef' => $decisionRef]
				);
				return;
			}

			$resolved = $this->resolveFinanceObject(event: $event, subjectId: $subjectId);
			if ($resolved === null) {
				$this->logger->info(
					'SignoffDecisionConcludedListener: no matching finance object (skipping)',
					['decisionRef' => $decisionRef, 'subjectId' => $subjectId]
				);
				return;
			}

			[$schema, $financeObject] = $resolved;

			// Capture the accounting-consequence mutation so it can be
			// persisted alongside the mirror. onDecisionCallback owns the
			// idempotency guard and fires the consequence exactly once on
			// 'approved'; a repeated conclusion is a no-op and $consequence
			// stays null (nothing extra to persist).
			$consequence = [];
			$updated = $this->signoffService->onDecisionCallback(
				$financeObject,
				$outcome,
				$decisionRef,
				function (array $object) use ($schema, $outcome, &$consequence): array {
					$object = $this->applyAccountingConsequence(schema: $schema, object: $object, outcome: $outcome);
					$consequence = $this->consequenceDelta(object: $object, outcome: $outcome);
					return $object;
				},
				// Activity object type for the REQ-RAP-006 `decision_made`
				// event raised inside onDecisionCallback(). This listener is the
				// sole production caller, so omitting it here would leave that
				// event permanently unemitted.
				$schema,
			);

			// Persist the mirror (decisionRef + decisionOutcome) plus any local
			// accounting-consequence delta (REQ-SIGN-006) through OR.
			$updates = [
				'decisionRef' => $updated['decisionRef'] ?? $decisionRef,
				'decisionOutcome' => $updated['decisionOutcome'] ?? $outcome,
			];
			$this->persist(schema: $schema, id: $subjectId, updates: ($updates + $consequence));

			$this->logger->info(
				'SignoffDecisionConcludedListener: outcome consumed',
				[
					'schema' => $schema,
					'subjectId' => $subjectId,
					'outcome' => $outcome,
					'decisionRef' => $decisionRef,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SignoffDecisionConcludedListener: projection failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve the subject id from the concluded event — prefer the
	 * externalReference we sent on the request, fall back to subjectId.
	 *
	 * @param \OCA\Decidesk\Event\DecisionConcludedEvent $event The concluded event.
	 *
	 * @return string The subject id, or '' when none.
	 */
	private function resolveSubjectId(\OCA\Decidesk\Event\DecisionConcludedEvent $event): string {
		$external = (string)$event->getExternalReference();
		if ($external !== '') {
			return $external;
		}

		return (string)($event->getSubjectId() ?? '');
	}//end resolveSubjectId()

	/**
	 * Resolve the finance object the concluded decision belongs to.
	 *
	 * Uses the event's subjectSchema when present; otherwise scans the three
	 * known governance-decision subject schemas for the id.
	 *
	 * @param \OCA\Decidesk\Event\DecisionConcludedEvent $event The concluded event.
	 * @param string $subjectId The finance object id.
	 *
	 * @return array{0:string,1:array<string,mixed>}|null [schema, object] or null.
	 */
	private function resolveFinanceObject(\OCA\Decidesk\Event\DecisionConcludedEvent $event, string $subjectId): ?array {
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
	 * Apply the local accounting consequence on an approved sign-off
	 * (REQ-SIGN-006). The consequence stays in shillinq: on `approved` the
	 * finance lifecycle is flipped through the existing OR write path so the
	 * report becomes submittable / the adoption posting can run. On `rejected`
	 * no lifecycle advance happens.
	 *
	 * @param string $schema The finance schema.
	 * @param array<string,mixed> $object The finance object (already carrying the mirror).
	 * @param string $outcome 'approved' | 'rejected'.
	 *
	 * @return array<string,mixed> The (possibly mutated) finance object.
	 */
	private function applyAccountingConsequence(string $schema, array $object, string $outcome): array {
		if ($outcome !== 'approved') {
			return $object;
		}

		// The accounting consequence flips the finance lifecycle to the
		// post-sign-off state through the existing OR write path. shillinq owns
		// no approval logic — it records the decidesk outcome and opens its own
		// downstream gate (submission / adoption posting) exactly once.
		$object['signoffApprovedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$object['signoffGateOpen'] = true;

		$this->logger->info(
			'SignoffDecisionConcludedListener: accounting consequence applied',
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
	 * @param string $outcome 'approved' | 'rejected'.
	 *
	 * @return array<string,mixed> The consequence delta (empty on non-approved).
	 */
	private function consequenceDelta(array $object, string $outcome): array {
		if ($outcome !== 'approved') {
			return [];
		}

		$delta = [];
		foreach (['signoffApprovedAt', 'signoffGateOpen'] as $key) {
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
