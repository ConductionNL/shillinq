<?php

/**
 * BADO Controleprotocol Service
 *
 * Tier-3 BADO (Besluit Accountantscontrole Decentrale Overheden) audit protocol
 * computation + lifecycle guards (REQ-001, REQ-004, REQ-006, REQ-007, REQ-008).
 * Materialises the per-topic finding aggregation and the mechanically-derived
 * audit opinion for a Controleprotocol from existing ToleranceMatrix +
 * AuditSample + AuditFinding + Materialiteit data using the real OpenRegister
 * ObjectService API (findAll) — the aggregation rows and the opinion are not
 * authored by operators; they are computed on demand (design.md D6).
 *
 * This service is also the ADR-031 exception-path host for the lifecycle
 * preconditions referenced from the register.d fragment's
 * x-openregister-lifecycle transitions (canSubmitForReview, canAdopt,
 * hasControllerResponse, isFourEyeComplete, canSignVerklaring): each combines
 * cross-schema lookups / arithmetic that OpenRegister's declarative `requires:`
 * clause cannot yet express. The pure decision logic lives in
 * BadoControleprotocolCalculator; this service wires it to live OR data and
 * recomputes everything server-side, never trusting client-supplied objects.
 * When OR lands stable conditional cross-schema aggregation, the declarative
 * blocks become primary and this service is removed.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes BADO finding aggregation + opinion and guards the protocol/finding
 * lifecycle transitions for one Controleprotocol.
 *
 * Reads are scoped to a single protocol (REQ-001, REQ-006): callers pass the
 * protocol id resolved from the authenticated user's context, never a
 * client-supplied trust boundary, and reads are delegated to OpenRegister's
 * ObjectService which enforces multitenancy / RBAC so no cross-organisation
 * audit data leaks.
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * ADR-031 exception-path host bundling the 5 lifecycle preconditions + the
 * aggregation/opinion derivation for the 7 BADO schemas; each guard is small
 * and the cohesion is by design.
 */
class BadoControleprotocolService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param BadoControleprotocolCalculator $calculator Pure-logic BADO decision helper.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics (no stack traces to client).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly BadoControleprotocolCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Validate a ToleranceMatrix row against the BADO statutory maxima (REQ-002).
	 *
	 * Delegates to the calculator; returns the list of violated ceiling field
	 * names (empty when valid). Named in the register.d fragment as the ceiling
	 * validation entry point.
	 *
	 * @param array<string,mixed> $row A ToleranceMatrix row.
	 *
	 * @return array<int,string> Field names whose ceiling exceeds the statutory maximum.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-6
	 */
	public function validateCeilings(array $row): array {
		return $this->calculator->validateCeilings(row: $row);
	}//end validateCeilings()

	/**
	 * Classify a single finding's severity against the topic's ceilings (REQ-006).
	 *
	 * Delegates to the calculator. Named in the register.d fragment on the
	 * AuditFinding schema as the severity-classification entry point.
	 *
	 * @param array<string,mixed> $finding The AuditFinding.
	 * @param array<string,mixed> $toleranceRow The ToleranceMatrix row for the finding's topic.
	 * @param mixed $materialityAmount Frozen materialiteit amount in EUR.
	 *
	 * @return string One of: acceptabel, te-corrigeren, materieel.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-9
	 */
	public function classifySeverity(array $finding, array $toleranceRow, mixed $materialityAmount): string {
		return $this->calculator->classifySeverity(
			finding: $finding,
			toleranceRow: $toleranceRow,
			materialityAmount: $materialityAmount
		);

	}//end classifySeverity()

	/**
	 * Mechanically derive the BADO audit opinion from topic verdicts (REQ-007).
	 *
	 * Delegates to the calculator. Named in the register.d fragment on the
	 * VerklaringDraft schema as the opinion-derivation entry point.
	 *
	 * @param array<int,array<string,mixed>> $topicVerdicts aggregateFindings() output.
	 * @param bool $scopeLimitation True when the auditor could not test the whole population.
	 *
	 * @return string One of: goedkeurend, met-beperking, oordeelonthouding, afkeurend.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-13
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) scope-limitation is a first-class BADO decision-tree input, not a behaviour toggle.
	 */
	public function deriveOpinion(array $topicVerdicts, bool $scopeLimitation = false): string {
		return $this->calculator->deriveOpinion(topicVerdicts: $topicVerdicts, scopeLimitation: $scopeLimitation);
	}//end deriveOpinion()

	/**
	 * Compute the full per-topic aggregation + derived opinion for a protocol (REQ-006, REQ-007).
	 *
	 * Server-authoritative: loads the protocol's materialiteit, all
	 * AuditFindings (via their parent AuditSamples) and the ToleranceMatrix
	 * rows, reclassifies each agreed/resolved finding's severity against the
	 * frozen materialiteit ceilings, aggregates per topic and derives the
	 * proposed opinion. The breakdown rows are never trusted from the client.
	 *
	 * @param string $protocolId The Controleprotocol.id to aggregate.
	 *
	 * @return array{protocolId: string, materialityAmount: float, topics: array<int,array<string,mixed>>, proposedOpinion: string}
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function computeAggregation(string $protocolId): array {
		$materialityAmount = $this->materialityAmount(protocolId: $protocolId);
		$toleranceByTopic = $this->toleranceRowsByTopic(protocolId: $protocolId);
		$findings = $this->classifiedFindings(
			protocolId: $protocolId,
			toleranceByTopic: $toleranceByTopic,
			materialityAmount: $materialityAmount
		);

		$topics = $this->calculator->aggregateFindings(findings: $findings);
		$opinion = $this->calculator->deriveOpinion(topicVerdicts: $topics, scopeLimitation: false);

		return [
			'protocolId' => $protocolId,
			'materialityAmount' => $materialityAmount,
			'topics' => $topics,
			'proposedOpinion' => $opinion,
		];

	}//end computeAggregation()

	/**
	 * Lifecycle guard: may a Controleprotocol move draft -> in-review (REQ-004)?
	 *
	 * Permitted only when the required header fields are populated: version,
	 * auditYear, organisationId, organisationType, materialityBase and the
	 * effective date range. Fail-closed: any missing field, a missing object or
	 * an exception denies the transition.
	 *
	 * @param string $protocolId The Controleprotocol.id being transitioned.
	 * @param array<string,mixed>|null $object The Controleprotocol object being transitioned.
	 *
	 * @return bool True when the protocol may be submitted for review.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-14
	 */
	public function canSubmitForReview(string $protocolId, ?array $object = null): bool {
		try {
			$protocol = $this->resolveProtocol(protocolId: $protocolId, object: $object);
			if ($protocol === null) {
				return false;
			}

			foreach (['version', 'auditYear', 'organisationId', 'organisationType', 'materialityBase', 'effectiveFrom', 'effectiveTo'] as $field) {
				if (empty($protocol[$field]) === true) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolService: submit-for-review precondition failed — denying transition (fail-closed)',
				['protocolId' => $protocolId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canSubmitForReview()

	/**
	 * Lifecycle guard: may a Controleprotocol move in-review -> adopted (REQ-004)?
	 *
	 * Permitted only when a valid adoptionDecision reference is present — it must
	 * carry both a besluitnummer and a datum (the raadsbesluit / statenbesluit /
	 * AB-besluit that legally adopts the protocol). Fail-closed.
	 *
	 * @param string $protocolId The Controleprotocol.id being transitioned.
	 * @param array<string,mixed>|null $object The Controleprotocol object being transitioned.
	 *
	 * @return bool True when the protocol may be adopted.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-14
	 */
	public function canAdopt(string $protocolId, ?array $object = null): bool {
		try {
			$protocol = $this->resolveProtocol(protocolId: $protocolId, object: $object);
			if ($protocol === null) {
				return false;
			}

			$decision = $protocol['adoptionDecision'] ?? null;
			if (is_array($decision) === false) {
				return false;
			}

			$decisionNumber = trim((string)($decision['decisionNumber'] ?? ''));
			$date = trim((string)($decision['date'] ?? ''));

			return $decisionNumber !== '' && $date !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolService: adopt precondition failed — denying transition (fail-closed)',
				['protocolId' => $protocolId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canAdopt()

	/**
	 * Lifecycle guard: has an AuditFinding recorded a controller response (REQ-006)?
	 *
	 * Precondition for the open -> agreed transition: the controller must have
	 * provided a response before the finding can be agreed. Fail-closed.
	 *
	 * @param string $findingId The AuditFinding.id being transitioned.
	 * @param array<string,mixed>|null $object The AuditFinding object being transitioned.
	 *
	 * @return bool True when a non-empty controllerResponse is present.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-18
	 */
	public function hasControllerResponse(string $findingId, ?array $object = null): bool {
		try {
			$finding = $this->resolveFinding(findingId: $findingId, object: $object);
			if ($finding === null) {
				return false;
			}

			return trim((string)($finding['controllerResponse'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolService: controller-response precondition failed — denying transition (fail-closed)',
				['findingId' => $findingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end hasControllerResponse()

	/**
	 * Lifecycle guard: is an AuditFinding's four-eye workflow complete (REQ-006)?
	 *
	 * Precondition for the resolution transitions: both the controller response
	 * and the auditor conclusion must be recorded AND both classification axes
	 * must carry an outcome. Delegates the rule to the calculator. Fail-closed.
	 *
	 * @param string $findingId The AuditFinding.id being transitioned.
	 * @param array<string,mixed>|null $object The AuditFinding object being transitioned.
	 *
	 * @return bool True when the four-eye workflow is complete.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-18
	 */
	public function isFourEyeComplete(string $findingId, ?array $object = null): bool {
		try {
			$finding = $this->resolveFinding(findingId: $findingId, object: $object);
			if ($finding === null) {
				return false;
			}

			return $this->calculator->isFourEyeComplete(finding: $finding);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolService: four-eye precondition failed — denying transition (fail-closed)',
				['findingId' => $findingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isFourEyeComplete()

	/**
	 * Lifecycle guard: may a VerklaringDraft be signed (REQ-007, REQ-008)?
	 *
	 * Permitted only when every AuditFinding for the protocol is agreed or
	 * resolved (no open/disputed finding remains, validation rule 5) AND every
	 * SiSa-regeling in scope has at least one SiSaAssurance child (validation
	 * rule 6). Both checks are recomputed server-side from live OR data.
	 * Fail-closed: any open finding, any uncovered regeling, a missing object or
	 * an exception denies the signature.
	 *
	 * @param string $declarationId The VerklaringDraft.id being signed.
	 * @param array<string,mixed>|null $object The VerklaringDraft object being signed.
	 *
	 * @return bool True when the verklaring may be signed.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-17
	 */
	public function canSignVerklaring(string $declarationId, ?array $object = null): bool {
		try {
			$declaration = $this->resolveObject(schema: 'VerklaringDraft', id: $declarationId, object: $object);
			if ($declaration === null) {
				return false;
			}

			$protocolId = (string)($declaration['protocol'] ?? '');
			if ($protocolId === '') {
				return false;
			}

			if ($this->allFindingsSettled(protocolId: $protocolId) === false) {
				return false;
			}

			return $this->allSisaRegelingenCovered(protocolId: $protocolId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BadoControleprotocolService: sign-verklaring precondition failed — denying signature (fail-closed)',
				['verklaringId' => $declarationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canSignVerklaring()

	/**
	 * Fetch the frozen materialiteit amount for a protocol (REQ-003).
	 *
	 * Reads the overall Materialiteit row for the protocol and returns its
	 * calculatedAmount, falling back to the protocol's own materialityAmount
	 * when no Materialiteit child exists, or 0 when neither is available.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return float The materialiteit amount in EUR.
	 */
	private function materialityAmount(string $protocolId): float {
		$materialiteiten = $this->objects()
			->setRegister($this->register())
			->setSchema('Materialiteit')
			->findAll(['filters' => ['protocol' => $protocolId]]);

		foreach ($materialiteiten as $row) {
			if ((string)($row['scope'] ?? '') === 'overall' && isset($row['calculatedAmount']) === true) {
				return (float)$row['calculatedAmount'];
			}
		}

		foreach ($materialiteiten as $row) {
			if (isset($row['calculatedAmount']) === true) {
				return (float)$row['calculatedAmount'];
			}
		}

		$protocol = $this->resolveProtocol(protocolId: $protocolId, object: null);
		return (float)($protocol['materialityAmount'] ?? 0);
	}//end materialityAmount()

	/**
	 * Load the ToleranceMatrix rows for a protocol, keyed by topic (REQ-006).
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return array<string,array<string,mixed>> topic => ToleranceMatrix row.
	 */
	private function toleranceRowsByTopic(string $protocolId): array {
		$rows = $this->objects()
			->setRegister($this->register())
			->setSchema('ToleranceMatrix')
			->findAll(['filters' => ['protocol' => $protocolId]]);

		$byTopic = [];
		foreach ($rows as $row) {
			$topic = (string)($row['topic'] ?? '');
			if ($topic !== '') {
				$byTopic[$topic] = $row;
			}
		}

		return $byTopic;
	}//end toleranceRowsByTopic()

	/**
	 * Load and reclassify the protocol's findings server-side (REQ-006).
	 *
	 * Resolves the protocol's AuditSamples, gathers their AuditFindings and
	 * recomputes each finding's severity against the matching ToleranceMatrix
	 * row + frozen materialiteit. The computed severity overrides any
	 * client-supplied severity so the aggregation is server-authoritative.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param array<string,array<string,mixed>> $toleranceByTopic ToleranceMatrix rows keyed by topic.
	 * @param float $materialityAmount Frozen materialiteit amount in EUR.
	 *
	 * @return array<int,array<string,mixed>> Findings with a recomputed severity.
	 */
	private function classifiedFindings(string $protocolId, array $toleranceByTopic, float $materialityAmount): array {
		$samples = $this->objects()
			->setRegister($this->register())
			->setSchema('AuditSample')
			->findAll(['filters' => ['protocol' => $protocolId]]);

		$sampleIds = [];
		foreach ($samples as $sample) {
			$id = (string)($sample['id'] ?? ($sample['@self']['id'] ?? ''));
			if ($id !== '') {
				$sampleIds[$id] = true;
			}
		}

		if (empty($sampleIds) === true) {
			return [];
		}

		$findings = $this->objects()
			->setRegister($this->register())
			->setSchema('AuditFinding')
			->findAll([]);

		$classified = [];
		foreach ($findings as $finding) {
			$sampleId = (string)($finding['sample'] ?? '');
			// The `empty($sampleIds)` early return above already guarantees a
			// non-empty map, so the `!== []` conjunct this replaces was dead.
			if (isset($sampleIds[$sampleId]) === false) {
				continue;
			}

			$topic = (string)($finding['topic'] ?? 'other');
			$toleranceRow = ($toleranceByTopic[$topic] ?? []);
			$finding['severity'] = $this->calculator->classifySeverity(
				finding: $finding,
				toleranceRow: $toleranceRow,
				materialityAmount: $materialityAmount
			);
			$classified[] = $finding;
		}//end foreach

		return $classified;
	}//end classifiedFindings()

	/**
	 * Whether every AuditFinding for the protocol is agreed or resolved (REQ-008).
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return bool True when no open/disputed finding remains.
	 */
	private function allFindingsSettled(string $protocolId): bool {
		$toleranceByTopic = $this->toleranceRowsByTopic(protocolId: $protocolId);
		$findings = $this->classifiedFindings(
			protocolId: $protocolId,
			toleranceByTopic: $toleranceByTopic,
			materialityAmount: 0.0
		);

		foreach ($findings as $finding) {
			$status = (string)($finding['status'] ?? 'open');
			if ($status !== 'agreed' && $status !== 'resolved') {
				return false;
			}
		}

		return true;
	}//end allFindingsSettled()

	/**
	 * Whether every in-scope SiSa-regeling has a SiSaAssurance child (REQ-008).
	 *
	 * A protocol with no SiSaAssurance records is treated as having no
	 * SiSa-regelingen in scope (vacuously covered). When SiSaAssurance records
	 * exist, each must carry a non-empty regelingCode.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return bool True when all in-scope regelingen are covered.
	 */
	private function allSisaRegelingenCovered(string $protocolId): bool {
		$assurances = $this->objects()
			->setRegister($this->register())
			->setSchema('SiSaAssurance')
			->findAll(['filters' => ['protocol' => $protocolId]]);

		foreach ($assurances as $assurance) {
			if (trim((string)($assurance['schemeCode'] ?? '')) === '') {
				return false;
			}
		}

		return true;
	}//end allSisaRegelingenCovered()

	/**
	 * Resolve the tenant (organisation) a Controleprotocol belongs to.
	 *
	 * `Controleprotocol.organisationId` is the tenant scope for the whole BADO
	 * bundle: none of the six child schemas (ToleranceMatrix, Materialiteit,
	 * AuditSample, AuditFinding, VerklaringDraft, SiSaAssurance) carries a tenant
	 * field of its own — they hang off the protocol's FK. So the protocol's
	 * organisationId is the only value an ADR-005 membership check can be made
	 * against, and this accessor exists so the controller can make it: every
	 * loader in this class and in AccountantsdossierExportService was private.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return string|null The organisation id, or null when the protocol does not exist.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function organisationIdFor(string $protocolId): ?string {
		if ($protocolId === '') {
			return null;
		}

		// ⚠️ Deliberately NOT resolveProtocol()/resolveObject(). Those go through
		// `findAll(['filters' => ['id' => $id]])`, and `id` is NOT a body
		// property — it is `@self.id`, a bigint column. Postgres answers that
		// query with SQLSTATE[22P02] "invalid input syntax for type bigint",
		// which the service swallows, so the lookup returns null for every
		// protocol that exists. Using it here would have made this guard refuse
		// the legitimate owner as well as the attacker — measured on a
		// two-account rig: owner 200 → 404. ObjectService::find() addresses the
		// object by its identity and is the call the rest of the app uses.
		$protocol = $this->objects()->find(
			id: $protocolId,
			register: $this->register(),
			schema: 'Controleprotocol'
		);

		if ($protocol === null) {
			return null;
		}

		if (is_array($protocol) === false) {
			$protocol = (array)$protocol->jsonSerialize();
		}

		return (string)($protocol['organisationId'] ?? '');
	}//end organisationIdFor()

	/**
	 * Resolve a Controleprotocol from a supplied object or by id.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 * @param array<string,mixed>|null $object The object supplied to the guard, if any.
	 *
	 * @return array<string,mixed>|null The protocol, or null when not found.
	 */
	private function resolveProtocol(string $protocolId, ?array $object): ?array {
		if ($object !== null && isset($object['organisationId']) === true) {
			return $object;
		}

		return $this->resolveObject(schema: 'Controleprotocol', id: $protocolId, object: $object);
	}//end resolveProtocol()

	/**
	 * Resolve an AuditFinding from a supplied object or by id.
	 *
	 * @param string $findingId The AuditFinding.id.
	 * @param array<string,mixed>|null $object The object supplied to the guard, if any.
	 *
	 * @return array<string,mixed>|null The finding, or null when not found.
	 */
	private function resolveFinding(string $findingId, ?array $object): ?array {
		if ($object !== null && isset($object['sample']) === true) {
			return $object;
		}

		return $this->resolveObject(schema: 'AuditFinding', id: $findingId, object: $object);
	}//end resolveFinding()

	/**
	 * Resolve an object of a given schema by id, preferring a supplied object.
	 *
	 * @param string $schema The OpenRegister schema slug.
	 * @param string $id The object id.
	 * @param array<string,mixed>|null $object The object supplied to the caller, if any.
	 *
	 * @return array<string,mixed>|null The object, or null when not found.
	 */
	private function resolveObject(string $schema, string $id, ?array $object): ?array {
		if ($object !== null && $object !== []) {
			return $object;
		}

		if ($id === '') {
			return null;
		}

		// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
		// properties and the entity's `id` is not one, so that shape matched
		// nothing for every value and this resolver returned null for every
		// object it was asked for. find() answers the uuid directly, which
		// makes the id-comparison loop that followed redundant.
		return ObjectIdentifier::findOne(
			scoped: $this->objects()
				->setRegister($this->register())
				->setSchema($schema),
			id: $id
		);
	}//end resolveObject()

	/**
	 * Lazily resolve OpenRegister's ObjectService from the DI container.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objects(): mixed {
		return $this->objectService;
	}//end objects()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
