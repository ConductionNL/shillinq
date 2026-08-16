<?php

/**
 * CCM Rule Engine
 *
 * ADR-031 exception: a pure, deterministic Continuous Controls Monitoring rule
 * evaluator (REQ-CCM-002). Rules are authored as a constrained JSON DSL on the
 * CcmRule schema (lib/Settings/register.d/bookkeeping-ccm-rule-engine.json); this
 * service validates the DSL, compiles it once into an abstract syntax tree (AST),
 * caches the AST keyed by rule identity + revision, and evaluates the AST against
 * a transaction context. The DSL is NOT arbitrary code: there is no eval(),
 * exec(), shell_exec(), assert() or dynamic class instantiation anywhere in this
 * file. Every unknown operator fails validation and denies compilation; every
 * evaluation exception fails closed (the rule does not fire).
 *
 * The engine is the imperative half of the capability — the declarative finding
 * triage workflow, notifications, and nightly materialisation jobs live on the
 * register fragment (x-openregister-lifecycle / -notifications /
 * -scheduled-workflows). When OpenRegister gains a native rule-expression engine
 * this service is replaced by that declaration and deleted.
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
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deterministic compiler + evaluator for the CCM rule DSL (REQ-CCM-002).
 *
 * The compileRule() method validates and compiles a CcmRule's ruleLogic into a cached AST;
 * evaluate() walks the AST against a transaction context and returns a fired
 * boolean plus diagnostic metadata so a finding investigator knows why a rule
 * fired. No dynamic code execution — only a fixed operator dispatch table.
 *
 * The DSL operator dispatch is an inherently large switch; the per-operator
 * cyclomatic complexity is bounded and each case is trivial, so the aggregate
 * class complexity is suppressed here (the alternative — one class per operator —
 * would be far harder to audit against the spec's single operator table).
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CcmRuleEngine {
	/**
	 * The fixed set of leaf (non-compound) DSL operators (REQ-CCM-002).
	 *
	 * @var array<int,string>
	 */
	private const LEAF_OPERATORS = [
		'event-matches',
		'field-equals',
		'field-in-set',
		'field-greater-than',
		'field-between',
		'field-matches-regex',
		'time-is-within',
		'time-is-weekend',
		'time-is-outside-business-hours',
		'user-has-function',
		'user-also-has-function',
		'user-is-in-role',
		'value-deviates-from-baseline',
		'value-violates-benford',
		'count-of-similar-in-period-exceeds',
		'duplicate-of-existing',
		'master-data-changed-within',
		'approval-chain-bypassed',
		'posted-while-period-closing',
		'same-user-as',
	];

	/**
	 * The compound (boolean) DSL operators (REQ-CCM-002).
	 *
	 * @var array<int,string>
	 */
	private const COMPOUND_OPERATORS = ['all-of', 'any-of', 'none-of', 'not'];

	/**
	 * In-memory AST cache, keyed by cache key (rule identity + revision).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $astCache = [];

	/**
	 * Diagnostics collected during the most recent evaluate() call.
	 *
	 * @var array<string,mixed>
	 */
	private array $diagnostics = [];

	/**
	 * Construct the rule engine.
	 *
	 * @param LoggerInterface $logger Logger for compile/evaluate diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate a DSL expression, raising on any unknown or malformed operator.
	 *
	 * REQ-CCM-002 scenario "Rule DSL is validated on registration": an unknown
	 * operator (or a structurally invalid node) must raise so the rule is never
	 * created with un-evaluable logic.
	 *
	 * @param array<string,mixed> $dsl The ruleLogic expression to validate.
	 *
	 * @return bool True when the DSL is valid.
	 *
	 * @throws \InvalidArgumentException When the DSL contains an unknown operator or bad shape.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function validateDsl(array $dsl): bool {
		if ($dsl === []) {
			// An empty expression (e.g. an unconfigured custom-rule slot) is
			// structurally valid but never fires; treat it as a no-op.
			return true;
		}

		if (count($dsl) !== 1) {
			throw new InvalidArgumentException('A DSL node must contain exactly one operator key.');
		}

		$operator = (string)array_key_first($dsl);
		$operand = $dsl[$operator];

		if (in_array($operator, self::COMPOUND_OPERATORS, true) === true) {
			if ($operator === 'not') {
				if (is_array($operand) === false) {
					throw new InvalidArgumentException('Operator "not" requires a single sub-expression.');
				}

				$this->validateDsl(dsl: $operand);
				return true;
			}

			if (is_array($operand) === false) {
				throw new InvalidArgumentException('Compound operator "' . $operator . '" requires an array of sub-expressions.');
			}

			foreach ($operand as $sub) {
				if (is_array($sub) === false) {
					throw new InvalidArgumentException('Compound operator "' . $operator . '" sub-expressions must be objects.');
				}

				$this->validateDsl(dsl: $sub);
			}

			return true;
		}//end if

		if (in_array($operator, self::LEAF_OPERATORS, true) === false) {
			throw new InvalidArgumentException('Unknown DSL operator: ' . $operator);
		}

		return true;
	}//end validateDsl()

	/**
	 * Compile a CcmRule's ruleLogic into a cached AST.
	 *
	 * The AST is a structurally-validated copy of the DSL annotated with a stable
	 * cache key. On a subsequent call with the same rule identity + revision the
	 * cached AST is returned without re-validation (REQ-CCM-002 caching scenario).
	 *
	 * @param array<string,mixed> $rule A CcmRule object array (must contain ruleLogic).
	 *
	 * @return array<string,mixed> The compiled AST: ['cacheKey' => string, 'tree' => array].
	 *
	 * @throws \InvalidArgumentException When the rule has no/invalid ruleLogic.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function compileRule(array $rule): array {
		$cacheKey = $this->cacheKey(rule: $rule);
		if (isset($this->astCache[$cacheKey]) === true) {
			return $this->astCache[$cacheKey];
		}

		$dsl = $rule['ruleLogic'] ?? null;
		if (is_array($dsl) === false) {
			throw new InvalidArgumentException('CcmRule ' . ($rule['ruleCode'] ?? '?') . ' has no ruleLogic object.');
		}

		$this->validateDsl(dsl: $dsl);

		$ast = [
			'cacheKey' => $cacheKey,
			'ruleCode' => (string)($rule['ruleCode'] ?? ''),
			'tree' => $dsl,
		];

		$this->astCache[$cacheKey] = $ast;
		return $ast;
	}//end compileRule()

	/**
	 * Evaluate a compiled AST against a transaction context.
	 *
	 * @param array<string,mixed> $ast The compiled AST from compileRule().
	 * @param array<string,mixed> $context The transaction context (fields, user, functions, baselines).
	 *
	 * @return array{fired:bool,diagnostics:array<string,mixed>} The evaluation outcome.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function evaluate(array $ast, array $context): array {
		$this->diagnostics = [];
		try {
			$tree = ($ast['tree'] ?? []);
			$fired = false;
			if ($tree !== []) {
				$fired = $this->evaluateNode(node: $tree, context: $context);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'CcmRuleEngine: evaluation failed — rule does not fire (fail-closed)',
				['ruleCode' => ($ast['ruleCode'] ?? '?'), 'exception' => $e->getMessage()]
			);
			return ['fired' => false, 'diagnostics' => ['error' => $e->getMessage()]];
		}

		return ['fired' => $fired, 'diagnostics' => $this->diagnostics];
	}//end evaluate()

	/**
	 * Compile then evaluate a CcmRule against a context in one call.
	 *
	 * @param array<string,mixed> $rule A CcmRule object array.
	 * @param array<string,mixed> $context The transaction context.
	 *
	 * @return array{fired:bool,diagnostics:array<string,mixed>} The evaluation outcome.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function evaluateRule(array $rule, array $context): array {
		try {
			$ast = $this->compileRule(rule: $rule);
		} catch (Throwable $e) {
			$this->logger->error(
				'CcmRuleEngine: compilation failed — rule does not fire (fail-closed)',
				['ruleCode' => ($rule['ruleCode'] ?? '?'), 'exception' => $e->getMessage()]
			);
			return ['fired' => false, 'diagnostics' => ['error' => $e->getMessage()]];
		}

		return $this->evaluate(ast: $ast, context: $context);
	}//end evaluateRule()

	/**
	 * Invalidate the cached AST for a rule (call on rule update).
	 *
	 * @param array<string,mixed> $rule The CcmRule whose cache entry to flush.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function invalidate(array $rule): void {
		unset($this->astCache[$this->cacheKey(rule: $rule)]);
	}//end invalidate()

	/**
	 * Build a stable cache key from a rule's identity + revision.
	 *
	 * @param array<string,mixed> $rule The CcmRule.
	 *
	 * @return string The cache key.
	 */
	private function cacheKey(array $rule): string {
		$self = ($rule['@self'] ?? []);
		$id = (string)(($self['id'] ?? null) ?? ($self['uuid'] ?? null) ?? ($self['slug'] ?? ($rule['ruleCode'] ?? 'unknown')));
		$revision = (string)(($self['updated'] ?? null) ?? ($self['version'] ?? ($rule['version'] ?? '0')));

		// Fold a hash of the logic into the key so two rules sharing an id but
		// carrying different logic (or an ad-hoc rule with no stable revision)
		// never collide on a stale cached AST.
		$logicHash = substr(md5((string)json_encode($rule['ruleLogic'] ?? [])), 0, 12);
		return $id . '@' . $revision . ':' . $logicHash;
	}//end cacheKey()

	/**
	 * Evaluate a single AST node (recursive, deterministic, no dynamic code).
	 *
	 * @param array<string,mixed> $node The DSL node.
	 * @param array<string,mixed> $context The transaction context.
	 *
	 * @return bool The boolean truth of the node.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function evaluateNode(array $node, array $context): bool {
		$operator = (string)array_key_first($node);
		$operand = $node[$operator];

		switch ($operator) {
			case 'all-of':
				// An empty conjunction never fires: an unconfigured rule (e.g. a
				// custom-rule slot) must not flag every transaction (fail-closed).
				if ((array)$operand === []) {
					return false;
				}

				foreach ((array)$operand as $sub) {
					if ($this->evaluateNode(node: (array)$sub, context: $context) === false) {
						return false;
					}
				}
				return true;
			case 'any-of':
				foreach ((array)$operand as $sub) {
					if ($this->evaluateNode(node: (array)$sub, context: $context) === true) {
						return true;
					}
				}
				return false;
			case 'none-of':
				// An empty negation never fires (consistent with all-of above).
				if ((array)$operand === []) {
					return false;
				}

				foreach ((array)$operand as $sub) {
					if ($this->evaluateNode(node: (array)$sub, context: $context) === true) {
						return false;
					}
				}
				return true;
			case 'not':
				return $this->evaluateNode(node: (array)$operand, context: $context) === false;
			default:
				return $this->evaluateLeaf(operator: $operator, operand: $operand, context: $context);
		}//end switch
	}//end evaluateNode()

	/**
	 * Evaluate a leaf operator against the context.
	 *
	 * @param string $operator The leaf operator name.
	 * @param mixed $operand The operator operand(s).
	 * @param array<string,mixed> $context The transaction context.
	 *
	 * @return bool The boolean truth of the leaf.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function evaluateLeaf(string $operator, mixed $operand, array $context): bool {
		$args = (array)$operand;
		$fields = (array)($context['fields'] ?? []);

		switch ($operator) {
			case 'event-matches':
				return (string)($context['event'] ?? '') === (string)$operand;
			case 'field-equals':
				return (string)$this->field(fields: $fields, key: ($args[0] ?? '')) === (string)($args[1] ?? '');
			case 'field-in-set':
				return in_array((string)$this->field(fields: $fields, key: ($args[0] ?? '')), array_map('strval', (array)($args[1] ?? [])), true);
			case 'field-greater-than':
				return is_numeric($this->field(fields: $fields, key: ($args[0] ?? ''))) === true
					&& (float)$this->field(fields: $fields, key: ($args[0] ?? '')) > (float)($args[1] ?? 0);

			case 'field-between':
				$val = $this->field(fields: $fields, key: ($args[0] ?? ''));
				return is_numeric($val) === true
					&& (float)$val >= (float)($args[1] ?? 0)
					&& (float)$val <= (float)($args[2] ?? 0);

			case 'field-matches-regex':
				return $this->regexMatch(pattern: (string)($args[1] ?? ''), subject: (string)$this->field(fields: $fields, key: ($args[0] ?? '')));
			case 'time-is-weekend':
				return $this->isWeekend(timestamp: (string)$this->field(fields: $fields, key: (string)$operand));
			case 'time-is-outside-business-hours':
				return $this->isOutsideHours(
					timestamp: (string)$this->field(fields: $fields, key: ($args[0] ?? '')),
					open: (string)($args[1] ?? '08:00'),
					close: (string)($args[2] ?? '18:00')
				);

			case 'time-is-within':
				// Window membership (e.g. last-day-of-period) is resolved upstream
				// into a boolean context flag keyed by the field name.
				return (bool)$this->field(fields: $fields, key: ($args[0] ?? '') . '_within_window');
			case 'user-has-function':
				return in_array((string)($args[1] ?? ''), $this->userFunctions(context: $context, userField: (string)($args[0] ?? '')), true);
			case 'user-also-has-function':
				$funcs = $this->userFunctions(context: $context, userField: (string)($args[0] ?? ''));
				return in_array((string)($args[1] ?? ''), $funcs, true)
					&& in_array((string)($args[2] ?? ''), $funcs, true);

			case 'user-is-in-role':
				return in_array((string)($args[1] ?? ''), $this->userRoles(context: $context, userField: (string)($args[0] ?? '')), true);
			case 'value-deviates-from-baseline':
				return $this->deviatesFromBaseline(args: $args, context: $context, fields: $fields);
			case 'value-violates-benford':
				return (bool)$this->field(fields: $fields, key: 'benford_violation');
			case 'count-of-similar-in-period-exceeds':
				$count = (int)$this->field(fields: $fields, key: 'similar_count');
				return $count > (int)($args[2] ?? 0);
			case 'duplicate-of-existing':
				return (bool)$this->field(fields: $fields, key: 'duplicate_match');
			case 'master-data-changed-within':
				return (bool)$this->field(fields: $fields, key: 'master_data_changed');
			case 'approval-chain-bypassed':
				return (bool)$this->field(fields: $fields, key: 'approval_chain_bypassed');
			case 'posted-while-period-closing':
				return (bool)$this->field(fields: $fields, key: 'period_closing');
			case 'same-user-as':
				$a = (string)$this->field(fields: $fields, key: ($args[0] ?? ''));
				$b = (string)$this->field(fields: $fields, key: ($args[1] ?? ''));
				return $a !== '' && $a === $b;
			default:
				// Unknown operator at evaluation time fails closed (validation
				// should already have rejected it on registration).
				$this->logger->warning('CcmRuleEngine: unknown operator at evaluation: ' . $operator);
				return false;
		}//end switch
	}//end evaluateLeaf()

	/**
	 * Resolve a field value from the context fields by dotted/plain key.
	 *
	 * @param array<string,mixed> $fields The context fields.
	 * @param mixed $key The field key.
	 *
	 * @return mixed The resolved value, or null.
	 */
	private function field(array $fields, mixed $key): mixed {
		return $fields[(string)$key] ?? null;
	}//end field()

	/**
	 * Resolve the SoD function codes held by the user named in a context field.
	 *
	 * @param array<string,mixed> $context The transaction context.
	 * @param string $userField The field naming the user (e.g. posting_user).
	 *
	 * @return array<int,string> The function codes held by that user.
	 */
	private function userFunctions(array $context, string $userField): array {
		$fields = (array)($context['fields'] ?? []);
		$userId = (string)($fields[$userField] ?? $userField);
		$map = (array)($context['userFunctions'] ?? []);
		return array_map('strval', (array)($map[$userId] ?? []));
	}//end userFunctions()

	/**
	 * Resolve the roles held by the user named in a context field.
	 *
	 * @param array<string,mixed> $context The transaction context.
	 * @param string $userField The field naming the user.
	 *
	 * @return array<int,string> The roles held by that user.
	 */
	private function userRoles(array $context, string $userField): array {
		$fields = (array)($context['fields'] ?? []);
		$userId = (string)($fields[$userField] ?? $userField);
		$map = (array)($context['userRoles'] ?? []);
		return array_map('strval', (array)($map[$userId] ?? []));
	}//end userRoles()

	/**
	 * True when a value deviates from its materialised baseline beyond the
	 * configured z-score; records diagnostics (z_score, baseline_mean, stddev).
	 *
	 * @param array<int,mixed> $args The operator args [field, scope, zThreshold, window].
	 * @param array<string,mixed> $context The transaction context (carries baselines).
	 * @param array<string,mixed> $fields The transaction fields.
	 *
	 * @return bool True when the value deviates beyond the threshold.
	 */
	private function deviatesFromBaseline(array $args, array $context, array $fields): bool {
		$value = (float)($fields[(string)($args[0] ?? '')] ?? 0);
		$scope = (string)($args[1] ?? '');
		$threshold = (float)($args[2] ?? 3.0);

		$baselines = (array)($context['baselines'] ?? []);
		$baseline = (array)($baselines[$scope] ?? []);
		$mean = (float)($baseline['mean'] ?? 0.0);
		$stddev = (float)($baseline['stddev'] ?? 0.0);

		if ($stddev <= 0.0) {
			return false;
		}

		$zScore = (($value - $mean) / $stddev);
		$this->diagnostics['z_score'] = round($zScore, 4);
		$this->diagnostics['baseline_mean'] = $mean;
		$this->diagnostics['baseline_stddev'] = $stddev;

		return abs($zScore) > $threshold;
	}//end deviatesFromBaseline()

	/**
	 * Safe regex match: a malformed pattern denies the match (fail-closed).
	 *
	 * @param string $pattern The regex pattern (without delimiters).
	 * @param string $subject The subject string.
	 *
	 * @return bool True on a match.
	 */
	private function regexMatch(string $pattern, string $subject): bool {
		if ($pattern === '') {
			return false;
		}

		$delimited = '/' . str_replace('/', '\/', $pattern) . '/u';

		// Guard preg_match against a malformed pattern without the @ operator: an
		// invalid pattern raises a warning that we trap and convert to fail-closed.
		set_error_handler(static fn (): bool => true);
		try {
			$result = preg_match($delimited, $subject);
		} finally {
			restore_error_handler();
		}

		if ($result === false) {
			$this->logger->warning('CcmRuleEngine: invalid regex pattern denied: ' . $pattern);
			return false;
		}

		return $result === 1;
	}//end regexMatch()

	/**
	 * True when an ISO-8601 timestamp falls on Saturday or Sunday.
	 *
	 * @param string $timestamp The ISO-8601 timestamp.
	 *
	 * @return bool True on a weekend.
	 */
	private function isWeekend(string $timestamp): bool {
		if ($timestamp === '') {
			return false;
		}

		$dow = (int)(new DateTimeImmutable($timestamp))->format('N');
		return $dow >= 6;
	}//end isWeekend()

	/**
	 * True when an ISO-8601 timestamp falls outside the [open, close) window.
	 *
	 * @param string $timestamp The ISO-8601 timestamp.
	 * @param string $open The business-hours open time, HH:MM.
	 * @param string $close The business-hours close time, HH:MM.
	 *
	 * @return bool True when outside business hours.
	 */
	private function isOutsideHours(string $timestamp, string $open, string $close): bool {
		if ($timestamp === '') {
			return false;
		}

		$minutes = (int)(new DateTimeImmutable($timestamp))->format('G') * 60 + (int)(new DateTimeImmutable($timestamp))->format('i');
		$openMin = $this->toMinutes(hhmm: $open);
		$closeMin = $this->toMinutes(hhmm: $close);

		return $minutes < $openMin || $minutes >= $closeMin;
	}//end isOutsideHours()

	/**
	 * Convert an HH:MM string to minutes-since-midnight.
	 *
	 * @param string $hhmm The HH:MM time.
	 *
	 * @return int Minutes since midnight.
	 */
	private function toMinutes(string $hhmm): int {
		$parts = explode(':', $hhmm);
		return ((int)($parts[0] ?? 0) * 60) + (int)($parts[1] ?? 0);
	}//end toMinutes()
}//end class
