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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Standards\RuleCatalogue;
use OCA\Shillinq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Read-only compliance auditor over the register's bookkeeping objects.
 */
class RuleAuditService {

	/**
	 * Max objects loaded per type for the audit.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

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
		$corpusTotal = RuleCatalogue::count();
		$machineCheckable = count(RuleCatalogue::machineCheckable());
		$enforceable = count(RuleEngine::checkedRuleIds());

		$report = [
			'catalogueVersion' => RuleCatalogue::version(),
			'corpusTotal' => $corpusTotal,
			'machineCheckable' => $machineCheckable,
			'enforceableRules' => $enforceable,
			'coveragePct' => $machineCheckable > 0 ? round(($enforceable / $machineCheckable) * 100, 1) : 0.0,
			'types' => [],
			'objectsChecked' => 0,
			'objectsCompliant' => 0,
			'objectsWithViolations' => 0,
			'violationsBySeverity' => ['mandatory' => 0, 'conditional' => 0, 'recommended' => 0],
			'topViolatedRules' => [],
		];

		$byRule = [];

		foreach (RuleEngine::supportedTypes() as $type) {
			$objects = $this->loadAll($type);
			$typeStat = ['checked' => 0, 'compliant' => 0, 'withViolations' => 0, 'violations' => 0];

			foreach ($objects as $object) {
				if ($type === 'GLTransaction') {
					$object['lines'] = $this->loadLines($object);
				}

				$violations = RuleEngine::evaluate($type, $object, $context);
				$typeStat['checked']++;
				$report['objectsChecked']++;

				if (empty($violations) === true) {
					$typeStat['compliant']++;
					$report['objectsCompliant']++;
					continue;
				}

				$typeStat['withViolations']++;
				$report['objectsWithViolations']++;
				foreach ($violations as $violation) {
					$typeStat['violations']++;
					$report['violationsBySeverity'][$violation->severity] = (($report['violationsBySeverity'][$violation->severity] ?? 0) + 1);
					$byRule[$violation->ruleId] = (($byRule[$violation->ruleId] ?? 0) + 1);
				}
			}//end foreach

			$report['types'][$type] = $typeStat;
		}//end foreach

		arsort($byRule);
		foreach (array_slice($byRule, 0, 15, true) as $ruleId => $count) {
			$report['topViolatedRules'][] = ['ruleId' => $ruleId, 'count' => $count];
		}

		return $report;
	}//end audit()

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
		$keys = array_values(
			array_unique(
				array_filter(
					[
						(string)($transaction['id'] ?? $transaction['@self']['id'] ?? ''),
						(string)($transaction['transactionNumber'] ?? ''),
					]
				)
			)
		);

		$lines = [];
		foreach ($keys as $key) {
			try {
				$rows = $this->objectService()
					->setRegister($this->register())
					->setSchema('GLLine')
					->findAll(['filters' => ['transactionId' => $key]]);
			} catch (\Throwable $e) {
				continue;
			}

			foreach ($this->normaliseRows($rows) as $line) {
				$lineId = (string)($line['id'] ?? $line['@self']['id'] ?? spl_object_hash((object)$line));
				$lines[$lineId] = $line;
			}
		}

		return array_values($lines);
	}//end loadLines()

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
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		return $register === '' ? 'shillinq' : $register;
	}//end register()
}//end class
