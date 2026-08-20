<?php

/**
 * Innovatiebox Audit Trail Listener
 *
 * Listens to OpenRegister `ObjectCreatedEvent` + `ObjectUpdatedEvent` on the
 * three immutable / sensitive innovatiebox schemas — `NexusCalculation`,
 * `IBProfitAttribution`, and `CarryForwardLoss` — and appends one immutable
 * `InnovatieboxAuditEvent` per business-relevant lifecycle transition
 * (REQ-IBA-008 + REQ-IBA-009).
 *
 * Mapping (subject schema -> audit event):
 *
 *  - NexusCalculation create  -> NexusCalculation.calculated
 *  - IBProfitAttribution create  -> IBProfitAttribution.created
 *  - IBProfitAttribution update where the next-state has vso_locked: true
 *    AND the prior state has vso_locked: false  -> IBProfitAttribution.finalized
 *  - IBProfitAttribution update where the prior state has vso_locked: true
 *    AND VsoLockingValidator confirms the year is locked
 *    -> IBProfitAttribution.amendment_attempt_blocked (reason: 'vso_locked')
 *  - CarryForwardLoss create  -> CarryForwardLoss.created
 *  - CarryForwardLoss update where verrekend_boekjaar grew  ->
 *    CarryForwardLoss.offset_applied (with the new offset entry in details)
 *
 * The listener never blocks the OR write path. Sensitive listener routines
 * (audit event appends, VSO checks) are wrapped fail-soft and downgrade to
 * Psr warnings — the source of legal truth is the schema record itself, the
 * audit event is the defence. Schema slugs are checked case-insensitively
 * and accept `register/schema` paths exactly like the existing
 * GLTransactionComplianceCacheListener.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\VsoLockingValidator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Append an InnovatieboxAuditEvent for every relevant innovatiebox lifecycle
 * transition (REQ-IBA-008 + REQ-IBA-009).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 */
final class InnovatieboxAuditTrailListener implements IEventListener {
	/**
	 * Schema slug of the OR NexusCalculation record (case-insensitive).
	 *
	 * @var string
	 */
	private const SCHEMA_NEXUS = 'nexuscalculation';

	/**
	 * Schema slug of the OR IBProfitAttribution record (case-insensitive).
	 *
	 * @var string
	 */
	private const SCHEMA_PROFIT = 'ibprofitattribution';

	/**
	 * Schema slug of the OR CarryForwardLoss record (case-insensitive).
	 *
	 * @var string
	 */
	private const SCHEMA_LOSS = 'carryforwardloss';

	/**
	 * Construct the listener.
	 *
	 * @param InnovatieboxAuditEventLogger $logger Append-only audit event writer.
	 * @param VsoLockingValidator $vsoValidator VSO year-lock checker (task 4.3).
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $psrLogger Psr logger for fail-soft.
	 */
	public function __construct(
		private readonly InnovatieboxAuditEventLogger $logger,
		private readonly VsoLockingValidator $vsoValidator,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $psrLogger,
	) {
	}//end __construct()

	/**
	 * Handle the OR object lifecycle event.
	 *
	 * @param Event $event OR ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof ObjectCreatedEvent) {
				$this->handleCreated(event: $event);
				return;
			}

			if ($event instanceof ObjectUpdatedEvent) {
				$this->handleUpdated(event: $event);
				return;
			}
		} catch (Throwable $e) {
			// Never bubble a listener error into OR's write path.
			$this->psrLogger->warning(
				'InnovatieboxAuditTrailListener: handler raised — swallowed to protect write path',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Handle a create event (records *.created + NexusCalculation.calculated).
	 *
	 * @param ObjectCreatedEvent $event The OR create event.
	 *
	 * @return void
	 */
	private function handleCreated(ObjectCreatedEvent $event): void {
		$entity = $event->getObject();
		if ($entity === null) {
			return;
		}

		$schema = $this->normaliseSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity));
		$data = $this->extractObjectArray(entity: $entity);

		if ($schema === self::SCHEMA_NEXUS) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_NEXUS_CALCULATED,
					'administrationId' => (string)($data['administrationId'] ?? ''),
					'financialYear' => $this->intOrNull(value: $data['financialYear'] ?? null),
					'qualifying_asset_id' => $this->stringOrNull(value: $data['qualifying_asset_id'] ?? null),
					'subject_schema' => 'NexusCalculation',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'details' => $this->slice(
						data: $data,
						keys: [
							'own_rd_cost',
							'rd_cost_outsourced_third_parties',
							'rd_cost_outsourced_affiliated',
							'uplift_factor',
							'nexusbreuk_ongecapt',
							'nexus_fraction_applied',
						]
					),
				]
			);
			return;
		}//end if

		if ($schema === self::SCHEMA_PROFIT) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_CREATED,
					'administrationId' => (string)($data['administrationId'] ?? ''),
					'financialYear' => $this->intOrNull(value: $data['financialYear'] ?? null),
					'qualifying_asset_id' => $this->stringOrNull(value: $data['qualifying_asset_id'] ?? null),
					'subject_schema' => 'IBProfitAttribution',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'details' => $this->slice(
						data: $data,
						keys: [
							'method',
							'gross_revenue_asset',
							'qualifying_profit_for_nexus',
							'qualifying_profit_after_nexus',
							'effective_rate',
							'vpb_on_innovation_share',
							'benefit_innovation_box',
							'flat_rate_cap_applied',
						]
					),
				]
			);

			// Twin event: a forfaitair election that hit the EUR 25k cap
			// emits ForfaitairCap.applied so the Belastingdienst defence can
			// see the binding cap and the resulting benefit reduction (task
			// 5.4 + REQ-IBA-003).
			if ($this->isForfaitairCapHit(data: $data) === true) {
				$kwalifFor = (float)($data['qualifying_profit_for_nexus'] ?? 0);
				$kwalifAfter = (float)($data['qualifying_profit_after_nexus'] ?? 0);
				$this->logger->record(
					options: [
						'event_type' => InnovatieboxAuditEventLogger::EVENT_FORFAITAIR_CAP_APPLIED,
						'administrationId' => (string)($data['administrationId'] ?? ''),
						'financialYear' => $this->intOrNull(value: $data['financialYear'] ?? null),
						'qualifying_asset_id' => $this->stringOrNull(value: $data['qualifying_asset_id'] ?? null),
						'subject_schema' => 'IBProfitAttribution',
						'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
						'reason' => 'cap_hit',
						'details' => [
							'voor_cap' => $kwalifFor,
							'na_cap' => $kwalifAfter,
							'benefit_reduction' => max(0.0, ($kwalifFor - $kwalifAfter)),
							'forfaitair_cap_eur' => 25000,
						],
					]
				);
			}//end if

			return;
		}//end if

		if ($schema === self::SCHEMA_LOSS) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_LOSS_CREATED,
					'administrationId' => (string)($data['administrationId'] ?? ''),
					'financialYear' => $this->intOrNull(value: $data['origin_boekjaar'] ?? ($data['financialYear'] ?? null)),
					'qualifying_asset_id' => $this->stringOrNull(value: $data['qualifying_asset_id'] ?? null),
					'subject_schema' => 'CarryForwardLoss',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'details' => $this->slice(
						data: $data,
						keys: ['origin_boekjaar', 'oorspronkelijk_bedrag', 'balance_after', 'status']
					),
				]
			);
			return;
		}

	}//end handleCreated()

	/**
	 * Handle an update event — finalisation, blocked amendment, or loss offset.
	 *
	 * @param ObjectUpdatedEvent $event The OR update event.
	 *
	 * @return void
	 */
	private function handleUpdated(ObjectUpdatedEvent $event): void {
		$entity = $event->getObject();
		if ($entity === null) {
			return;
		}

		$schema = $this->normaliseSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity));
		$next = $this->extractObjectArray(entity: $entity);
		$prior = $this->extractPriorState(event: $event);

		if ($schema === self::SCHEMA_PROFIT) {
			$this->handleProfitUpdated(entity: $entity, next: $next, prior: $prior);
			return;
		}

		if ($schema === self::SCHEMA_LOSS) {
			$this->handleLossUpdated(entity: $entity, next: $next, prior: $prior);
			return;
		}

		// NexusCalculation is x-openregister.immutable, so an update event
		// here means OR allowed an amendment that should not have happened —
		// record it as a blocked-attempt-style audit event for the defence.
		if ($schema === self::SCHEMA_NEXUS) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_AMENDMENT_BLOCKED,
					'administrationId' => (string)($next['administrationId'] ?? ''),
					'financialYear' => $this->intOrNull(value: $next['financialYear'] ?? null),
					'qualifying_asset_id' => $this->stringOrNull(value: $next['qualifying_asset_id'] ?? null),
					'subject_schema' => 'NexusCalculation',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'reason' => 'immutable_schema_violation',
					'details' => ['changed_keys' => $this->changedKeys(prior: $prior, next: $next)],
				]
			);
		}

	}//end handleUpdated()

	/**
	 * Branch profit-attribution update events into finalised / blocked.
	 *
	 * @param object $entity Entity wrapper (for uuid).
	 * @param array<string,mixed> $next Next-state payload.
	 * @param array<string,mixed> $prior Prior-state payload (best-effort).
	 *
	 * @return void
	 */
	private function handleProfitUpdated(object $entity, array $next, array $prior): void {
		$priorLocked = (bool)($prior['vso_locked'] ?? false);
		$nextLocked = (bool)($next['vso_locked'] ?? false);

		if ($priorLocked === false && $nextLocked === true) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_FINALIZED,
					'administrationId' => (string)($next['administrationId'] ?? ''),
					'financialYear' => $this->intOrNull(value: $next['financialYear'] ?? null),
					'qualifying_asset_id' => $this->stringOrNull(value: $next['qualifying_asset_id'] ?? null),
					'subject_schema' => 'IBProfitAttribution',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'reason' => 'vso_signed',
				]
			);
			return;
		}

		$administrationId = (string)($next['administrationId'] ?? '');
		$financialYear = $this->intOrNull(value: $next['financialYear'] ?? null);
		$alreadyLocked = ($priorLocked === true);
		if ($alreadyLocked === false && $financialYear !== null && $administrationId !== '') {
			// Cross-check: even if THIS record is not locked, the year may be
			// locked by another row in the same administration + boekjaar.
			$alreadyLocked = $this->vsoValidator->isYearLocked(
				administrationId: $administrationId,
				financialYear: $financialYear
			);
		}

		if ($alreadyLocked === true) {
			$this->logger->record(
				options: [
					'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_AMENDMENT_BLOCKED,
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'qualifying_asset_id' => $this->stringOrNull(value: $next['qualifying_asset_id'] ?? null),
					'subject_schema' => 'IBProfitAttribution',
					'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
					'reason' => 'vso_locked',
					'details' => ['changed_keys' => $this->changedKeys(prior: $prior, next: $next)],
				]
			);
		}

	}//end handleProfitUpdated()

	/**
	 * Handle a CarryForwardLoss update — emit an offset-applied event when
	 * the `verrekend_boekjaar` array grew.
	 *
	 * @param object $entity Entity wrapper (for uuid).
	 * @param array<string,mixed> $next Next-state payload.
	 * @param array<string,mixed> $prior Prior-state payload (best-effort).
	 *
	 * @return void
	 */
	private function handleLossUpdated(object $entity, array $next, array $prior): void {
		$priorEntries = (array)($prior['settled_financial_year'] ?? []);
		$nextEntries = (array)($next['settled_financial_year'] ?? []);
		if (count($nextEntries) <= count($priorEntries)) {
			return;
		}

		$newEntries = array_slice($nextEntries, count($priorEntries));

		$this->logger->record(
			options: [
				'event_type' => InnovatieboxAuditEventLogger::EVENT_LOSS_OFFSET_APPLIED,
				'administrationId' => (string)($next['administrationId'] ?? ''),
				'financialYear' => $this->intOrNull(value: $next['origin_boekjaar'] ?? ($next['financialYear'] ?? null)),
				'qualifying_asset_id' => $this->stringOrNull(value: $next['qualifying_asset_id'] ?? null),
				'subject_schema' => 'CarryForwardLoss',
				'subject_id' => $this->stringOrNull(value: $entity->getUuid() ?? null),
				'details' => [
					'new_entries' => $newEntries,
					'balance_after' => $next['balance_after'] ?? null,
					'status' => $next['status'] ?? null,
				],
			]
		);

	}//end handleLossUpdated()

	/**
	 * Normalise an OR schema identifier to a lowercase slug (also strips a
	 * `register/` prefix for parity with the existing GL listener).
	 *
	 * @param string $schema Raw schema identifier.
	 *
	 * @return string Lowercased slug.
	 */
	private function normaliseSchema(string $schema): string {
		$normalised = strtolower(trim($schema));
		$slashAt = strrpos($normalised, '/');
		if ($slashAt !== false) {
			$normalised = substr($normalised, ($slashAt + 1));
		}

		return $normalised;
	}//end normaliseSchema()

	/**
	 * Extract the entity's stored object array (best-effort across OR types).
	 *
	 * @param object $entity OR object entity.
	 *
	 * @return array<string,mixed>
	 */
	private function extractObjectArray(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			$obj = $entity->getObject();
			if (is_array($obj) === true) {
				return $obj;
			}
		}

		if (method_exists($entity, 'jsonSerialize') === true) {
			$serialised = $entity->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end extractObjectArray()

	/**
	 * Extract the prior-state object array from an ObjectUpdatedEvent — best
	 * effort across OR releases. Returns an empty array if not available.
	 *
	 * @param ObjectUpdatedEvent $event The update event.
	 *
	 * @return array<string,mixed>
	 */
	private function extractPriorState(ObjectUpdatedEvent $event): array {
		foreach (['getOldObject', 'getPreviousObject', 'getPrevious', 'getOld'] as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$prior = $event->{$method}();
			if ($prior === null) {
				continue;
			}

			if (is_object($prior) === true) {
				return $this->extractObjectArray(entity: $prior);
			}

			if (is_array($prior) === true) {
				return $prior;
			}
		}

		return [];
	}//end extractPriorState()

	/**
	 * Slice a data array to a fixed key whitelist (for the audit details blob).
	 *
	 * @param array<string,mixed> $data Source.
	 * @param array<int,string> $keys Whitelisted keys.
	 *
	 * @return array<string,mixed>
	 */
	private function slice(array $data, array $keys): array {
		$out = [];
		foreach ($keys as $key) {
			if (array_key_exists($key, $data) === true) {
				$out[$key] = $data[$key];
			}
		}

		return $out;
	}//end slice()

	/**
	 * Compute the symmetric set of changed top-level keys between two states.
	 *
	 * @param array<string,mixed> $prior Prior state.
	 * @param array<string,mixed> $next Next state.
	 *
	 * @return array<int,string>
	 */
	private function changedKeys(array $prior, array $next): array {
		$changed = [];
		$keys = array_unique(array_merge(array_keys($prior), array_keys($next)));
		foreach ($keys as $key) {
			$a = $prior[$key] ?? null;
			$b = $next[$key] ?? null;
			if ($a !== $b) {
				$changed[] = (string)$key;
			}
		}

		return $changed;
	}//end changedKeys()

	/**
	 * Whether the supplied IBProfitAttribution payload represents a
	 * forfaitair election that hit the EUR 25k cap. Two trip-wires:
	 * either the explicit `forfaitair_cap_applied` flag, or the
	 * `methode` is forfaitair_25pct AND the pre-cap qualifying profit
	 * exceeded the cap.
	 *
	 * @param array<string,mixed> $data Payload.
	 *
	 * @return bool
	 */
	private function isForfaitairCapHit(array $data): bool {
		if (($data['flat_rate_cap_applied'] ?? false) === true) {
			return true;
		}

		$method = (string)($data['method'] ?? '');
		if ($method !== 'flat_rate_25pct') {
			return false;
		}

		$forCap = (float)($data['qualifying_profit_for_nexus'] ?? 0);
		return ($forCap > 25000.0);
	}//end isForfaitairCapHit()

	/**
	 * Coerce a value to int, or null when not parseable.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * Coerce a value to non-empty string, or null.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = (string)$value;
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()
}//end class
