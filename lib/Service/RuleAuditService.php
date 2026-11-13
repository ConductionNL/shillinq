<?php

/**
 * Rule Audit Service
 *
 * Audits the bookkeeping data in the register against the machine-checkable rule
 * corpus: it loads every object of each engine-supported type, runs the
 * RuleEngine over it, and aggregates a compliance report — coverage (how many
 * corpus rules are actually enforceable today), how many objects were checked,
 * how many are compliant, and the violations grouped by severity and by rule.
 *
 * This is the "does shillinq comply?" answer: it does not change data, it reports
 * the live compliance posture so gaps are visible and traceable back to the
 * standard/law each rule cites.
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
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\RuleAuditServiceInterface;
use OCA\Shillinq\Standards\RuleCatalogue;
use OCA\Shillinq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Read-only compliance auditor over the register's bookkeeping objects.
 */
class RuleAuditService implements RuleAuditServiceInterface {

	/**
	 * Max objects loaded per type for the audit.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The object counters of an empty report.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_COUNTS = [
		'types' => [],
		'objectsChecked' => 0,
		'objectsCompliant' => 0,
		'objectsWithViolations' => 0,
		'violationsBySeverity' => ['mandatory' => 0, 'conditional' => 0, 'recommended' => 0],
		'topViolatedRules' => [],
	];

	/**
	 * Construct the rule audit service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Run the audit and return the structured report.
	 *
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	public function audit(array $context = []): array {
		$run = new RuleAuditRun();
		$run->context = $context;
		$run->report = $this->emptyReport();
		foreach (RuleEngine::supportedTypes() as $type) {
			$this->auditType(run: $run, type: $type);
		}

		$run->report['topViolatedRules'] = $this->topViolatedRules(byRule: $run->byRule);

		return $run->report;
	}//end audit()

	/**
	 * The report before any object is checked: catalogue size and coverage.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyReport(): array {
		$machineCheckable = count(RuleCatalogue::machineCheckable());
		$enforceable = count(RuleEngine::checkedRuleIds());
		$coverage = 0.0;
		if ($machineCheckable > 0) {
			$coverage = round(($enforceable / $machineCheckable) * 100, 1);
		}

		return [
			'catalogueVersion' => RuleCatalogue::version(),
			'corpusTotal' => RuleCatalogue::count(),
			'machineCheckable' => $machineCheckable,
			'enforceableRules' => $enforceable,
			'coveragePct' => $coverage,
		] + self::EMPTY_COUNTS;
	}//end emptyReport()

	/**
	 * Audit every object of one type and record the type's statistics.
	 *
	 * @param RuleAuditRun $run  The run.
	 * @param string       $type The object type.
	 *
	 * @return void
	 */
	private function auditType(RuleAuditRun $run, string $type): void {
		$run->type = $type;
		$run->typeStat = ['checked' => 0, 'compliant' => 0, 'withViolations' => 0, 'violations' => 0];
		foreach ($this->loadAll($type) as $object) {
			$this->auditObject(run: $run, object: $object);
		}

		$run->report['types'][$type] = $run->typeStat;
	}//end auditType()

	/**
	 * Evaluate one object (a GL transaction with its lines) and count it.
	 *
	 * @param RuleAuditRun         $run    The run.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return void
	 */
	private function auditObject(RuleAuditRun $run, array $object): void {
		if ($run->type === 'GLTransaction') {
			$object['lines'] = $this->loadLines($object);
		}

		$violations = RuleEngine::evaluate($run->type, $object, $run->context);
		$run->typeStat['checked']++;
		$run->report['objectsChecked']++;
		$outcome = $this->outcomeOf(violations: $violations);
		$run->typeStat[$outcome[0]]++;
		$run->report[$outcome[1]]++;
		$this->countViolations(run: $run, violations: $violations);
	}//end auditObject()

	/**
	 * Count each violation for the type, per severity and per rule.
	 *
	 * @param RuleAuditRun      $run        The run.
	 * @param array<int, mixed> $violations The object's violations.
	 *
	 * @return void
	 */
	private function countViolations(RuleAuditRun $run, array $violations): void {
		foreach ($violations as $violation) {
			$run->typeStat['violations']++;
			$run->report['violationsBySeverity'][$violation->severity] = (($run->report['violationsBySeverity'][$violation->severity] ?? 0) + 1);
			$run->byRule[$violation->ruleId] = (($run->byRule[$violation->ruleId] ?? 0) + 1);
		}
	}//end countViolations()

	/**
	 * The type-statistic and report counters an object's result goes into.
	 *
	 * @param array<int, mixed> $violations The object's violations.
	 *
	 * @return array{0: string, 1: string} [type counter, report counter].
	 */
	private function outcomeOf(array $violations): array {
		if (empty($violations) === true) {
			return ['compliant', 'objectsCompliant'];
		}

		return ['withViolations', 'objectsWithViolations'];
	}//end outcomeOf()

	/**
	 * The 15 most-violated rules, most violated first.
	 *
	 * @param array<string, int> $byRule ruleId => violation count.
	 *
	 * @return array<int, array{ruleId: string, count: int}>
	 */
	private function topViolatedRules(array $byRule): array {
		arsort($byRule);
		$top = [];
		foreach (array_slice($byRule, 0, 15, true) as $ruleId => $count) {
			$top[] = ['ruleId' => $ruleId, 'count' => $count];
		}

		return $top;
	}//end topViolatedRules()

	/**
	 * Load all objects of a schema (capped), as plain arrays.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleAuditService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Load GLLine rows for a transaction as plain arrays. GLLines reference their
	 * parent via `transactionId` matching EITHER the transaction's OpenRegister
	 * id OR its human `transactionNumber` (mirrors the financial-series join), so
	 * both keys are queried and merged (deduped by line id).
	 *
	 * @param array<string, mixed> $transaction The GL transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadLines(array $transaction): array {
		$lines = [];
		foreach ($this->lineKeys(transaction: $transaction) as $key) {
			try {
				$rows = $this->objectService()->setRegister($this->register())->setSchema('GLLine')->findAll(['filters' => ['transactionId' => $key]]);
			} catch (\Throwable $e) {
				continue;
			}

			foreach ($this->normaliseRows($rows) as $line) {
				$lines[(string)($line['id'] ?? $line['@self']['id'] ?? spl_object_hash((object)$line))] = $line;
			}
		}

		return array_values($lines);
	}//end loadLines()

	/**
	 * The keys GLLines may reference a transaction by: its id and its number.
	 *
	 * @param array<string, mixed> $transaction The GL transaction.
	 *
	 * @return array<int, string> Distinct, non-empty.
	 */
	private function lineKeys(array $transaction): array {
		$keys = [(string)($transaction['id'] ?? $transaction['@self']['id'] ?? ''), (string)($transaction['transactionNumber'] ?? '')];

		return array_values(array_unique(array_filter($keys)));
	}//end lineKeys()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$array = $this->rowArray(row: $row);
			if ($array !== null) {
				$out[] = $array;
			}
		}

		return $out;
	}//end normaliseRows()

	/**
	 * A row as an array: arrays as they are, serialisable entities serialised.
	 *
	 * @param mixed $row Raw row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowArray(mixed $row): array|null {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return null;
	}//end rowArray()

	/**
	 * Resolve the OpenRegister ObjectService from the DI container.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		return $this->objectService;
	}//end objectService()

	/**
	 * Return the configured OpenRegister register slug.
	 *
	 * @return string The configured register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		return $register === '' ? 'shillinq' : $register;
	}//end register()
}//end class
