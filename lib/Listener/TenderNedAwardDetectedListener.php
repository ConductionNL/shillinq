<?php

/**
 * TenderNed Award Detected Listener.
 *
 * REQ-002 — auto-promote an awarded TenderNed dossier into an active
 * Commitment when the winning KvK matches the tenant organisation.
 *
 * The listener is the in-app side of the `tenderned.award.detected`
 * CloudEvent contract documented in design.md D4. Openconnector polls
 * the TenderNed source on its own schedule (live-instance concern, see
 * Task 0.2) and writes / updates the corresponding
 * `TenderNedProcurement` OR record. OR fires
 * `ObjectCreatedEvent` / `ObjectTransitionedEvent` on every write, and
 * THIS listener turns "status == gegund + matching tenant KvK" into an
 * idempotent Commitment promotion + audit-trail entry.
 *
 * Two surfaces:
 *
 *  - `ObjectCreatedEvent` — openconnector publishes a fresh dossier
 *    already in `gegund` (bulk back-fill case).
 *  - `ObjectTransitionedEvent` — an existing dossier moves
 *    `open -> gegund` (live polling case).
 *
 * Both paths converge in `promoteIfEligible()`, which checks:
 *
 *  1. The dossier is on the `TenderNedProcurement` schema.
 *  2. The new status is `gegund` with `contractWaarde >= 1`.
 *  3. The `awardedSupplier` KvK prefix matches the configured tenant
 *     KvK (`shillinq` app config key `tenant_kvk`).
 *  4. No Commitment with the same `bronReferentie` exists yet
 *     (idempotency contract from the spec scenario).
 *
 * On match the listener creates a new Commitment with `bron=tenderned`,
 * `status=active`, and a milestone plan generated via
 * `MilestoneTemplateService` (REQ-003), then sets the aanbesteding to
 * `in-uitvoering`. The cross-app budget-impact CloudEvent (REQ-007) is
 * emitted by `BudgetImpactEmitter` from the same handler.
 *
 * Fail-soft: any unexpected exception is logged but never bubbles up to
 * the OR write path. The TenderNedProcurementGuard still defends the
 * declarative `gunnen` transition; this listener is the cross-schema
 * materialisation step layered on top.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\MilestoneTemplateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REQ-002 cross-schema promotion listener for the
 * `tenderned.award.detected` event-bus contract.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 */
class TenderNedAwardDetectedListener implements IEventListener {
	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param MilestoneTemplateService $templates Pure-logic milestone planner (REQ-003).
	 * @param BudgetImpactEmitter $budget Emits the launchpad budget-impact CloudEvent (REQ-007).
	 * @param ContainerInterface $container DI container for lazy OR ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug + tenant KvK.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly MilestoneTemplateService $templates,
		private readonly BudgetImpactEmitter $budget,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an OR ObjectCreatedEvent or ObjectTransitionedEvent.
	 *
	 * Both event shapes carry an OR Entity with `getObject()` returning the
	 * dossier payload. The handler is intentionally tolerant of either
	 * arriving — openconnector decides whether to bulk-insert or polling-
	 * transition based on the live TenderNed source state.
	 *
	 * @param Event $event OR object lifecycle event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
	 *
	 * KNOWN ADR-078 VIOLATION — gate-61, tracked in issue #1198. This handler
	 * calls saveObject() inside another object's write. That is pre-existing:
	 * the change that touched this file renamed identifiers only, and gate-61
	 * is diff-scoped, so the rename is what pulled a long-standing registration
	 * into scope rather than anything new.
	 *
	 * Deliberately NOT annotated with `@listener-placement inline`. That escape
	 * hatch takes one of four closed ADR-078 categories (realtime, sapi-memory,
	 * cheap-bounded, correctness) and none of them is true here — claiming one
	 * would buy a green gate with a false declaration, which is worse than the
	 * red. The fix is real deferral through OpenRegister's
	 * ListenerDeferralService; see #1198.
	 */
	public function handle(Event $event): void {
		try {
			$payload = $this->extractPayload(event: $event);
			if ($payload === null) {
				return;
			}

			if ($this->isAwardEligible(payload: $payload) === false) {
				return;
			}

			$this->promote(payload: $payload);
		} catch (Throwable $e) {
			// Fail-soft: never block the OR write path. The dossier itself
			// is persisted; the promotion can be retried on the next event.
			$this->logger->warning(
				'TenderNedAwardDetectedListener: promotion failed — fail-soft',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Pull the `TenderNedProcurement` payload from the OR event.
	 *
	 * Returns null when the event refers to a different schema or carries
	 * no usable object array (defensive).
	 *
	 * @param Event $event OR event.
	 *
	 * @return array<string, mixed>|null Aanbesteding payload, or null when not applicable.
	 */
	private function extractPayload(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent === false
			&& $event instanceof ObjectTransitionedEvent === false
		) {
			return null;
		}

		// ObjectTransitionedEvent additionally filters on the target state.
		if ($event instanceof ObjectTransitionedEvent === true
			&& $event->getTo() !== 'gegund'
		) {
			return null;
		}

		$entity = $event->getObject();
		if ($entity === null) {
			return null;
		}

		$schema = $this->schemaResolver->schemaSlug(entity: $entity);
		if ($this->isTenderSchema(schema: $schema) === false) {
			return null;
		}

		$payload = $entity->getObject();
		if (is_array($payload) === false) {
			return null;
		}

		return $payload;
	}//end extractPayload()

	/**
	 * Decide whether the dossier is the tenant's awarded tender.
	 *
	 * Matches `status: gegund` + `contractWaarde >= 1` + tenant KvK on the
	 * leading KvK prefix of `awardedSupplier` (the schema stores
	 * "KvK Naam" — design D6).
	 *
	 * @param array<string, mixed> $payload Aanbesteding payload.
	 *
	 * @return bool True when promotion is warranted.
	 */
	private function isAwardEligible(array $payload): bool {
		if ((string)($payload['status'] ?? '') !== 'gegund') {
			return false;
		}

		if ((float)($payload['contractValue'] ?? 0) < 1.0) {
			return false;
		}

		$tenantKvk = trim(
			$this->appConfig->getValueString(Application::APP_ID, 'tenant_kvk', '')
		);
		if ($tenantKvk === '') {
			// No tenant KvK configured — promotion would be ambiguous; skip.
			return false;
		}

		$supplier = trim((string)($payload['awardedSupplier'] ?? ''));
		if ($supplier === '') {
			return false;
		}

		return str_starts_with(haystack: $supplier, needle: $tenantKvk);
	}//end isAwardEligible()

	/**
	 * Promote the aanbesteding to a Commitment (REQ-002 + REQ-003 + REQ-007).
	 *
	 * @param array<string, mixed> $payload Aanbesteding payload.
	 *
	 * @return void
	 */
	private function promote(array $payload): void {
		$tenderId = (string)($payload['tenderId'] ?? '');
		if ($tenderId === '') {
			return;
		}

		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			$this->logger->info(
				'TenderNedAwardDetectedListener: OR ObjectService unavailable — skipping promotion',
				['tenderId' => $tenderId]
			);
			return;
		}

		$existing = $this->findExistingBySourceReference(
			objectService: $objectService,
			sourceReference: $tenderId
		);

		if ($existing !== null) {
			// REQ-002 idempotency: an obligation already exists for this
			// aanbestedingId. Nothing to do beyond re-emitting the budget
			// impact CloudEvent (in case launchpad missed the original).
			$this->budget->emitActivated(commitment: $existing, source: $payload);
			return;
		}

		$plan = $this->safeGeneratePlan(
			assignmentType: (string)($payload['assignmentType'] ?? 'other'),
			termStart: (string)($payload['termStart'] ?? ''),
			termEnd: (string)($payload['termEnd'] ?? '')
		);

		$commitment = [
			'commitmentNumber' => 'TN-' . $tenderId,
			'description' => (string)($payload['title'] ?? $tenderId),
			'source' => 'tenderned',
			'sourceReference' => $tenderId,
			'amount' => (float)($payload['contractValue'] ?? 0),
			'termStart' => (string)($payload['termStart'] ?? ''),
			'termEnd' => (string)($payload['termEnd'] ?? ''),
			'milestones' => $plan,
			'status' => 'active',
			'administrationId' => (string)($payload['administrationId'] ?? ''),
		];

		try {
			$objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Commitment')
				->saveObject(object: $commitment);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TenderNedAwardDetectedListener: saveObject failed — fail-soft',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
			return;
		}

		// Move the aanbesteding to in-uitvoering so it surfaces in the
		// execution view. Fail-soft if the update fails — the obligation
		// has been created and the next polling tick will re-attempt.
		try {
			$payload['status'] = 'in-uitvoering';
			$payload['commitmentId'] = 'TN-' . $tenderId;
			$objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'TenderNedProcurement')
				->saveObject(object: $payload);
		} catch (Throwable $e) {
			$this->logger->info(
				'TenderNedAwardDetectedListener: failed to bump aanbesteding to in-uitvoering',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
		}//end try

		$this->budget->emitActivated(commitment: $commitment, source: $payload);

	}//end promote()

	/**
	 * Generate the milestone plan, catching invalid-date exceptions so a
	 * missing looptijd does not block the promotion (the operator can
	 * enrich the term in the Commitment detail view later).
	 *
	 * @param string $assignmentType Contract type.
	 * @param string $termStart Term start.
	 * @param string $termEnd Term end.
	 *
	 * @return array<int, array<string, mixed>> Milestone plan, possibly empty.
	 */
	private function safeGeneratePlan(string $assignmentType, string $termStart, string $termEnd): array {
		if ($termStart === '' || $termEnd === '') {
			return [];
		}

		try {
			return $this->templates->generatePlan(
				assignmentType: $assignmentType,
				termStart: $termStart,
				termEnd: $termEnd
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'TenderNedAwardDetectedListener: milestone plan generation failed — using empty plan',
				['exception' => $e->getMessage()]
			);
			return [];
		}

	}//end safeGeneratePlan()

	/**
	 * Look up an existing Commitment by bronReferentie.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $sourceReference TenderNed aanbestedingId.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findExistingBySourceReference(object $objectService, string $sourceReference): ?array {
		try {
			$rows = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Commitment')
				->findAll(
					[
						'filters' => [
							'source' => 'tenderned',
							'sourceReference' => $sourceReference,
						],
						// This is an existence check: the loop below returns the
						// first row and discards the rest, so listing more than
						// one was only ever cost. OpenRegister control params
						// take an underscore prefix — a bare `limit` is read as
						// a FILTER on a property named "limit", which matches
						// nothing and silently returns the unbounded list.
						'_limit' => 1,
					]
				);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findExistingByBronReferentie()

	/**
	 * Resolve the OR ObjectService from the container (lazy).
	 *
	 * @return object|null ObjectService when OR is installed, else null.
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveObjectService()

	/**
	 * Return the configured register slug, falling back to `shillinq`.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Check whether the schema slug is the TenderNedProcurement schema.
	 *
	 * @param string $schema Schema slug from the event.
	 *
	 * @return bool
	 */
	private function isTenderSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));

		// The legacy Dutch slug is still matched on purpose. The schema was
		// renamed TenderNedAanbesteding -> TenderNedProcurement, but objects
		// already stored carry the old slug until the repair step has run on
		// that instance. Matching only the new slug would make this listener
		// silently do nothing for every pre-existing tender -- the exact
		// no-op failure mode the guards in this app have hit before, which
		// raises no error and writes no log line.
		return (str_ends_with(haystack: $normalised, needle: 'tendernedprocurement')
			|| str_ends_with(haystack: $normalised, needle: 'tendernedaanbesteding'));

	}//end isTenderSchema()
}//end class
