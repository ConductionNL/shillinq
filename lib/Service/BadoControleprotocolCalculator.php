<?php

/**
 * BADO Controleprotocol Calculator
 *
 * Pure-logic helper for the Tier-3 BADO (Besluit Accountantscontrole Decentrale
 * Overheden) audit protocol (REQ-002, REQ-006, REQ-007). Holds the
 * side-effect-free arithmetic and decision rules that BadoControleprotocolService
 * applies after fetching ToleranceMatrix + AuditFinding + Materialiteit data via
 * the OpenRegister ObjectService: validating tolerance ceilings against BADO
 * statutory maxima, classifying a finding's severity against the materialiteit
 * ceilings, and deriving the four-point audit opinion from the aggregated
 * finding counts. All money arithmetic is performed in integer cents to avoid
 * IEEE-754 equality drift, mirroring BcfCompensationCalculator.
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

/**
 * Side-effect-free BADO tolerance, severity and opinion logic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and
 * returns plain arrays/scalars so the logic is unit-testable in isolation.
 * BadoControleprotocolService wires this helper to live ToleranceMatrix +
 * AuditFinding + Materialiteit data. The methods mirror the declarative shapes
 * documented on the schemas (x-openregister-aggregations / -calculations);
 * this helper is the engine-side fallback for the cross-schema joins and the
 * conditional decision tree the declarative engine cannot yet express
 * (ADR-031).
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 */
class BadoControleprotocolCalculator {
	/**
	 * BADO statutory maximum for an approval ceiling (goedkeurend), in percent
	 * of materialiteit (BADO Article 5 lid 2; Kadernota Rechtmatigheid).
	 *
	 * @var float
	 */
	public const STATUTORY_APPROVAL_MAX = 1.0;

	/**
	 * BADO statutory maximum for a qualification ceiling (met beperking), in
	 * percent of materialiteit (BADO Article 5 lid 2).
	 *
	 * @var float
	 */
	public const STATUTORY_QUALIFICATION_MAX = 3.0;

	/**
	 * Convert a money amount to integer cents (precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Validate one ToleranceMatrix row against the BADO statutory maxima (REQ-002).
	 *
	 * A protocol may tighten a ceiling (set it lower) but never loosen it: every
	 * approval ceiling must be <= 1% and every qualification ceiling <= 3% of
	 * materialiteit (BADO Article 5 lid 2). Returns the list of violated ceiling
	 * field names — an empty list means the row is statutorily valid.
	 *
	 * A negative ceiling is also a violation (fail-closed: a corrupt row may not
	 * silently disable the threshold).
	 *
	 * @param array<string,mixed> $row A ToleranceMatrix row.
	 *
	 * @return array<int,string> Field names whose ceiling exceeds the statutory maximum (or is negative).
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-6
	 */
	public function validateCeilings(array $row): array {
		$approvalFields = [
			'faithfulnessApprovalCeiling',
			'lawfulnessApprovalCeiling',
		];

		$qualificationFields = [
			'faithfulnessQualificationCeiling',
			'lawfulnessQualificationCeiling',
			'uncertaintyCeiling',
		];

		$violations = [];

		foreach ($approvalFields as $field) {
			$value = (float)($row[$field] ?? 0);
			if ($value < 0 || $value > self::STATUTORY_APPROVAL_MAX) {
				$violations[] = $field;
			}
		}

		foreach ($qualificationFields as $field) {
			$value = (float)($row[$field] ?? 0);
			if ($value < 0 || $value > self::STATUTORY_QUALIFICATION_MAX) {
				$violations[] = $field;
			}
		}

		return $violations;
	}//end validateCeilings()

	/**
	 * Derive the EUR ceiling amounts for a topic from materialiteit + a matrix row.
	 *
	 * The ToleranceMatrix stores ceilings as a percentage of materialiteit; the
	 * absolute EUR amount a finding is compared against is
	 * materialiteit × ceilingPercentage / 100. Returns the approval and
	 * qualification ceilings (in cents) for the axis that the matrix row carries.
	 *
	 * @param mixed $materialityAmount Frozen materialiteit amount in EUR.
	 * @param array<string,mixed> $row The ToleranceMatrix row for the topic.
	 * @param string $axis Either 'lawfulness' or 'faithfulness'.
	 *
	 * @return array{approvalCents: int, qualificationCents: int}
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function ceilingCentsForAxis(mixed $materialityAmount, array $row, string $axis): array {
		$materialityCents = $this->toCents(amount: $materialityAmount);

		$approvalKey = 'lawfulnessApprovalCeiling';
		$qualificationKey = 'lawfulnessQualificationCeiling';
		if ($axis === 'faithfulness') {
			$approvalKey = 'faithfulnessApprovalCeiling';
			$qualificationKey = 'faithfulnessQualificationCeiling';
		}

		$approvalPct = (float)($row[$approvalKey] ?? self::STATUTORY_APPROVAL_MAX);
		$qualificationPct = (float)($row[$qualificationKey] ?? self::STATUTORY_QUALIFICATION_MAX);

		return [
			'approvalCents' => (int)round(($materialityCents * $approvalPct) / 100),
			'qualificationCents' => (int)round(($materialityCents * $qualificationPct) / 100),
		];

	}//end ceilingCentsForAxis()

	/**
	 * Classify a single finding's severity against the topic's ceilings (REQ-006).
	 *
	 * Severity is derived purely from the exception amount versus the approval and
	 * qualification ceilings of the relevant axis:
	 *  - amount  < approval ceiling      → acceptabel
	 *  - amount >= approval, < qual      → te-corrigeren (needs correction)
	 *  - amount >= qualification ceiling → materieel (affects opinion)
	 *
	 * A faithful/compliant finding (no exception on the relevant axis) is always
	 * acceptabel regardless of amount.
	 *
	 * @param array<string,mixed> $finding The AuditFinding (amount, rechtmatigheid, getrouwheid, findingType).
	 * @param array<string,mixed> $toleranceRow The ToleranceMatrix row for the finding's topic.
	 * @param mixed $materialityAmount Frozen materialiteit amount in EUR.
	 *
	 * @return string One of: acceptabel, te-corrigeren, materieel.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-9
	 */
	public function classifySeverity(array $finding, array $toleranceRow, mixed $materialityAmount): string {
		$axis = $this->exceptionAxis(finding: $finding);
		if ($axis === null) {
			return 'acceptabel';
		}

		$ceilings = $this->ceilingCentsForAxis(materialityAmount: $materialityAmount, row: $toleranceRow, axis: $axis);
		$amountCents = $this->toCents(amount: ($finding['amount'] ?? 0));

		if ($amountCents >= $ceilings['qualificationCents']) {
			return 'materieel';
		}

		if ($amountCents >= $ceilings['approvalCents']) {
			return 'te-corrigeren';
		}

		return 'acceptabel';
	}//end classifySeverity()

	/**
	 * Determine which axis carries the exception for a finding, or null if none.
	 *
	 * A finding exceptions on rechtmatigheid when its rechtmatigheid axis is
	 * 'exception', on getrouwheid when its getrouwheid axis is 'misstated', or
	 * on the axis named by findingType when the explicit axis fields are absent
	 * (onzekerheid is treated as the getrouwheid axis for ceiling comparison).
	 *
	 * @param array<string,mixed> $finding The AuditFinding.
	 *
	 * @return string|null 'lawfulness', 'faithfulness', or null when no exception.
	 */
	private function exceptionAxis(array $finding): ?string {
		$lawfulness = (string)($finding['lawfulness'] ?? '');
		$faithfulness = (string)($finding['faithfulness'] ?? '');

		if ($lawfulness === 'exception') {
			return 'lawfulness';
		}

		if ($faithfulness === 'misstated') {
			return 'faithfulness';
		}

		// Fall back to findingType when explicit axis outcomes are not recorded.
		if ($lawfulness === '' && $faithfulness === '') {
			$findingType = (string)($finding['findingType'] ?? '');
			if ($findingType === 'lawfulness') {
				return 'lawfulness';
			}

			if ($findingType === 'faithfulness' || $findingType === 'uncertainty') {
				return 'faithfulness';
			}
		}

		return null;
	}//end exceptionAxis()

	/**
	 * Aggregate classified findings per topic against the tolerance matrix (REQ-006).
	 *
	 * Only findings whose status is agreed or resolved contribute (validation
	 * rule 5). For each topic the method sums the rechtmatigheid and getrouwheid
	 * exception amounts (in cents), counts findings by severity, and emits a
	 * per-topic verdict: acceptable, qualified (te-corrigeren present), or
	 * adverse (any materieel). Topics are sorted for deterministic output.
	 *
	 * @param array<int,array<string,mixed>> $findings Classified AuditFinding records.
	 *
	 * @return array<int,array<string,mixed>> One row per topic with counts + verdict.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function aggregateFindings(array $findings): array {
		$byTopic = [];

		foreach ($findings as $finding) {
			$status = (string)($finding['status'] ?? 'open');
			if ($status !== 'agreed' && $status !== 'resolved') {
				continue;
			}

			$topic = (string)($finding['topic'] ?? 'other');
			if (isset($byTopic[$topic]) === false) {
				$byTopic[$topic] = $this->emptyTopicRow(topic: $topic);
			}

			$byTopic[$topic] = $this->accumulateFinding(row: $byTopic[$topic], finding: $finding);
		}//end foreach

		$topics = array_keys($byTopic);
		sort($topics);

		$rows = [];
		foreach ($topics as $topic) {
			$rows[] = $this->finaliseTopicRow(row: $byTopic[$topic]);
		}

		return $rows;
	}//end aggregateFindings()

	/**
	 * Build a zeroed aggregation row for a topic.
	 *
	 * @param string $topic The topic key.
	 *
	 * @return array<string,mixed> A zeroed topic row.
	 */
	private function emptyTopicRow(string $topic): array {
		return [
			'topic' => $topic,
			'acceptabelCount' => 0,
			'teCorrigerenCount' => 0,
			'materieelCount' => 0,
			'rechtmatigheidCents' => 0,
			'getrouwheidCents' => 0,
		];

	}//end emptyTopicRow()

	/**
	 * Fold one finding's severity count + axis amount into a topic row.
	 *
	 * @param array<string,mixed> $row The accumulator row for the topic.
	 * @param array<string,mixed> $finding The classified AuditFinding.
	 *
	 * @return array<string,mixed> The updated row.
	 */
	private function accumulateFinding(array $row, array $finding): array {
		$severityKeys = [
			'materieel' => 'materieelCount',
			'te-corrigeren' => 'teCorrigerenCount',
		];
		$severity = (string)($finding['severity'] ?? 'acceptabel');
		$countKey = ($severityKeys[$severity] ?? 'acceptabelCount');
		$row[$countKey]++;

		$axisKeys = [
			'lawfulness' => 'rechtmatigheidCents',
			'faithfulness' => 'getrouwheidCents',
		];
		$axis = (string)$this->exceptionAxis(finding: $finding);
		if (isset($axisKeys[$axis]) === true) {
			$row[$axisKeys[$axis]] += $this->toCents(amount: ($finding['amount'] ?? 0));
		}

		return $row;
	}//end accumulateFinding()

	/**
	 * Compute the verdict + EUR amounts for a completed topic row.
	 *
	 * @param array<string,mixed> $row The accumulated topic row.
	 *
	 * @return array<string,mixed> The row with verdict + EUR amounts.
	 */
	private function finaliseTopicRow(array $row): array {
		$row['verdict'] = 'acceptable';
		if ($row['materieelCount'] > 0) {
			$row['verdict'] = 'adverse';
		} elseif ($row['teCorrigerenCount'] > 0) {
			$row['verdict'] = 'qualified';
		}

		$row['rechtmatigheidAmount'] = ($row['rechtmatigheidCents'] / 100);
		$row['getrouwheidAmount'] = ($row['getrouwheidCents'] / 100);

		return $row;
	}//end finaliseTopicRow()

	/**
	 * Mechanically derive the BADO audit opinion from aggregated topic verdicts (REQ-007).
	 *
	 * Applies the BADO decision tree (NV COS 700-serie) — first match wins:
	 *  1. pervasive scope limitation               → oordeelonthouding (disclaimer)
	 *  2. any materieel finding above qualification
	 *     ceiling AND pervasive impact             → afkeurend (adverse)
	 *  3. any materieel finding (below pervasive)  → met-beperking (qualified)
	 *  4. no materieel, no uncertainty above ceiling → goedkeurend (clean)
	 *
	 * "Pervasive" is signalled by the caller-supplied $scopeLimitation flag (the
	 * auditor could not test the entire population) and by a materieel count that
	 * spans more than half the audited topics. Without scope limitation a single
	 * materieel finding qualifies the opinion; widespread materieel findings
	 * across the majority of topics make it adverse.
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
		if ($scopeLimitation === true) {
			return 'oordeelonthouding';
		}

		$topicCount = count($topicVerdicts);
		$adverseTopics = 0;
		foreach ($topicVerdicts as $verdict) {
			if ((string)($verdict['verdict'] ?? '') === 'adverse' || (int)($verdict['materieelCount'] ?? 0) > 0) {
				$adverseTopics++;
			}
		}

		if ($adverseTopics === 0) {
			return 'goedkeurend';
		}

		// Pervasive: materieel findings span the majority of audited topics.
		if ($topicCount > 0 && ($adverseTopics * 2) > $topicCount) {
			return 'afkeurend';
		}

		return 'met-beperking';
	}//end deriveOpinion()

	/**
	 * Whether a finding's four-eye workflow is complete (REQ-006).
	 *
	 * A finding may only become resolved when BOTH the controller response and
	 * the auditor conclusion are recorded AND both classification axes
	 * (rechtmatigheid, getrouwheid) carry an outcome. Fail-closed: any missing
	 * element returns false.
	 *
	 * @param array<string,mixed> $finding The AuditFinding.
	 *
	 * @return bool True when both four-eye fields and both axes are populated.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-18
	 */
	public function isFourEyeComplete(array $finding): bool {
		$hasController = (trim((string)($finding['controllerResponse'] ?? '')) !== '');
		$hasAuditor = (trim((string)($finding['auditorConclusion'] ?? '')) !== '');
		$hasRecht = (trim((string)($finding['lawfulness'] ?? '')) !== '');
		$hasGetrouw = (trim((string)($finding['faithfulness'] ?? '')) !== '');

		return $hasController === true
			&& $hasAuditor === true
			&& $hasRecht === true
			&& $hasGetrouw === true;

	}//end isFourEyeComplete()
}//end class
