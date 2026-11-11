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
 * The DSL operator dispatch maps each leaf operator to its family
 * (LEAF_FAMILIES) and each family evaluates its own few operators; the
 * aggregate class complexity is suppressed here (the alternative — one class
 * per operator — would be far harder to audit against the spec's single
 * operator table).
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
	 * The family each leaf operator is evaluated by (one entry per
	 * LEAF_OPERATORS member).
	 *
	 * @var array<string,string>
	 */
	private const LEAF_FAMILIES = [
		'event-matches'                      => 'signal',
		'field-equals'                       => 'field',
		'field-in-set'                       => 'field',
		'field-greater-than'                 => 'field',
		'field-between'                      => 'field',
		'field-matches-regex'                => 'field',
		'time-is-within'                     => 'time',
		'time-is-weekend'                    => 'time',
		'time-is-outside-business-hours'     => 'time',
		'user-has-function'                  => 'user',
		'user-also-has-function'             => 'user',
		'user-is-in-role'                    => 'user',
		'value-deviates-from-baseline'       => 'signal',
		'value-violates-benford'             => 'flag',
		'count-of-similar-in-period-exceeds' => 'signal',
		'duplicate-of-existing'              => 'flag',
		'master-data-changed-within'         => 'flag',
		'approval-chain-bypassed'            => 'flag',
		'posted-while-period-closing'        => 'flag',
		'same-user-as'                       => 'user',
	];

	/**
	 * The upstream-resolved boolean context field each flag operator reads.
	 *
	 * @var array<string,string>
	 */
	private const FLAG_FIELDS = [
		'value-violates-benford'      => 'benford_violation',
		'duplicate-of-existing'       => 'duplicate_match',
		'master-data-changed-within'  => 'master_data_changed',
		'approval-chain-bypassed'     => 'approval_chain_bypassed',
		'posted-while-period-closing' => 'period_closing',
	];

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
		if (in_array($operator, self::COMPOUND_OPERATORS, true) === true) {
			$this->validateCompound(operator: $operator, operand: $dsl[$operator]);
			return true;
		}

		if (in_array($operator, self::LEAF_OPERATORS, true) === false) {
			throw new InvalidArgumentException('Unknown DSL operator: ' . $operator);
		}

		return true;
	}//end validateDsl()

	/**
	 * Validate the operand of a compound operator: "not" takes one
	 * sub-expression, the others an array of sub-expressions.
	 *
	 * @param string $operator The compound operator.
	 * @param mixed  $operand  Its operand.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the operand has the wrong shape.
	 */
	private function validateCompound(string $operator, mixed $operand): void {
		if ($operator === 'not') {
			if (is_array($operand) === false) {
				throw new InvalidArgumentException('Operator "not" requires a single sub-expression.');
			}

			$this->validateDsl(dsl: $operand);
			return;
		}

		if (is_array($operand) === false) {
			throw new InvalidArgumentException('Compound operator "' . $operator . '" requires an array of sub-expressions.');
		}

		$this->validateSubExpressions(operator: $operator, operands: $operand);
	}//end validateCompound()

	/**
	 * Validate each sub-expression of a compound operator.
	 *
	 * @param string                  $operator The compound operator.
	 * @param array<int|string,mixed> $operands Its sub-expressions.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a sub-expression is not an object or is invalid.
	 */
	private function validateSubExpressions(string $operator, array $operands): void {
		foreach ($operands as $sub) {
			if (is_array($sub) === false) {
				throw new InvalidArgumentException('Compound operator "' . $operator . '" sub-expressions must be objects.');
			}

			$this->validateDsl(dsl: $sub);
		}
	}//end validateSubExpressions()

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
	 * @param array<string,mixed> $node    The DSL node.
	 * @param array<string,mixed> $context The transaction context.
	 *
	 * @return bool The boolean truth of the node.
	 */
	private function evaluateNode(array $node, array $context): bool {
		$operator = (string)array_key_first($node);
		$operand = $node[$operator];

		return match ($operator) {
			'all-of' => $this->allOf(operands: (array)$operand, context: $context),
			'any-of' => $this->anyOf(operands: (array)$operand, context: $context),
			'none-of' => $this->noneOf(operands: (array)$operand, context: $context),
			'not' => $this->evaluateNode(node: (array)$operand, context: $context) === false,
			default => $this->evaluateLeaf(call: new CcmLeafCall(node: $node, context: $context)),
		};
	}//end evaluateNode()

	/**
	 * True when every sub-node holds. An empty conjunction never fires: an
	 * unconfigured rule (e.g. a custom-rule slot) must not flag every
	 * transaction (fail-closed).
	 *
	 * @param array<int|string,mixed> $operands The sub-nodes.
	 * @param array<string,mixed>     $context  The transaction context.
	 *
	 * @return bool The truth of the conjunction.
	 */
	private function allOf(array $operands, array $context): bool {
		if ($operands === []) {
			return false;
		}

		foreach ($operands as $sub) {
			if ($this->evaluateNode(node: (array)$sub, context: $context) === false) {
				return false;
			}
		}

		return true;
	}//end allOf()

	/**
	 * True when at least one sub-node holds.
	 *
	 * @param array<int|string,mixed> $operands The sub-nodes.
	 * @param array<string,mixed>     $context  The transaction context.
	 *
	 * @return bool The truth of the disjunction.
	 */
	private function anyOf(array $operands, array $context): bool {
		foreach ($operands as $sub) {
			if ($this->evaluateNode(node: (array)$sub, context: $context) === true) {
				return true;
			}
		}

		return false;
	}//end anyOf()

	/**
	 * True when no sub-node holds. An empty negation never fires (consistent
	 * with all-of).
	 *
	 * @param array<int|string,mixed> $operands The sub-nodes.
	 * @param array<string,mixed>     $context  The transaction context.
	 *
	 * @return bool The truth of the negated disjunction.
	 */
	private function noneOf(array $operands, array $context): bool {
		if ($operands === []) {
			return false;
		}

		return $this->anyOf(operands: $operands, context: $context) === false;
	}//end noneOf()

	/**
	 * Evaluate a leaf operator against the context, dispatching on the
	 * operator's family.
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool The boolean truth of the leaf.
	 */
	private function evaluateLeaf(CcmLeafCall $call): bool {
		return match (self::LEAF_FAMILIES[$call->operator] ?? '') {
			'flag' => (bool)$call->field(key: self::FLAG_FIELDS[$call->operator]),
			'field' => $this->evaluateFieldLeaf(call: $call),
			'time' => $this->evaluateTimeLeaf(call: $call),
			'user' => $this->evaluateUserLeaf(call: $call),
			'signal' => $this->evaluateSignalLeaf(call: $call),
			default => $this->rejectUnknownOperator(operator: $call->operator),
		};
	}//end evaluateLeaf()

	/**
	 * Evaluate a field comparison operator (equals, in-set, greater-than,
	 * between, matches-regex).
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool The boolean truth of the leaf.
	 */
	private function evaluateFieldLeaf(CcmLeafCall $call): bool {
		$value = $call->argField(index: 0);

		return match ($call->operator) {
			'field-equals' => (string)$value === (string)$call->arg(index: 1, default: ''),
			'field-in-set' => in_array((string)$value, array_map('strval', (array)$call->arg(index: 1, default: [])), true),
			'field-greater-than' => is_numeric($value) === true && (float)$value > (float)$call->arg(index: 1, default: 0),
			'field-between' => $this->isNumericBetween(call: $call, value: $value),
			default => $this->regexMatch(pattern: (string)$call->arg(index: 1, default: ''), subject: (string)$value),
		};
	}//end evaluateFieldLeaf()

	/**
	 * True when a value is numeric and inside the operator's inclusive bounds.
	 *
	 * @param CcmLeafCall $call  The leaf node bound to its context.
	 * @param mixed       $value The field value under test.
	 *
	 * @return bool True when the value lies within [args[1], args[2]].
	 */
	private function isNumericBetween(CcmLeafCall $call, mixed $value): bool {
		return is_numeric($value) === true
			&& (float)$value >= (float)$call->arg(index: 1, default: 0)
			&& (float)$value <= (float)$call->arg(index: 2, default: 0);
	}//end isNumericBetween()

	/**
	 * Evaluate a time operator (weekend, outside business hours, within a
	 * window). Window membership (e.g. last-day-of-period) is resolved
	 * upstream into a boolean context flag keyed by the field name.
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool The boolean truth of the leaf.
	 */
	private function evaluateTimeLeaf(CcmLeafCall $call): bool {
		return match ($call->operator) {
			'time-is-weekend' => $this->isWeekend(timestamp: (string)$call->field(key: (string)$call->operand)),
			'time-is-outside-business-hours' => $this->isOutsideHours(
				timestamp: (string)$call->argField(index: 0),
				open: (string)$call->arg(index: 1, default: '08:00'),
				close: (string)$call->arg(index: 2, default: '18:00')
			),
			default => (bool)$call->field(key: $call->arg(index: 0, default: '') . '_within_window'),
		};
	}//end evaluateTimeLeaf()

	/**
	 * Evaluate a user operator (has function, also has function, is in role,
	 * same user as).
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool The boolean truth of the leaf.
	 */
	private function evaluateUserLeaf(CcmLeafCall $call): bool {
		$userField = (string)$call->arg(index: 0, default: '');
		$code = (string)$call->arg(index: 1, default: '');

		return match ($call->operator) {
			'user-has-function' => in_array($code, $this->userFunctions(context: $call->context, userField: $userField), true),
			'user-also-has-function' => $this->holdsBothFunctions(call: $call),
			'user-is-in-role' => in_array($code, $this->userRoles(context: $call->context, userField: $userField), true),
			default => $this->isSameUser(call: $call),
		};
	}//end evaluateUserLeaf()

	/**
	 * True when the user holds both function codes named by args[1] and args[2].
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool True when both functions are held.
	 */
	private function holdsBothFunctions(CcmLeafCall $call): bool {
		$funcs = $this->userFunctions(context: $call->context, userField: (string)$call->arg(index: 0, default: ''));
		return in_array((string)$call->arg(index: 1, default: ''), $funcs, true)
			&& in_array((string)$call->arg(index: 2, default: ''), $funcs, true);
	}//end holdsBothFunctions()

	/**
	 * True when the two fields named by args[0] and args[1] hold the same
	 * non-empty user.
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool True when both fields name the same user.
	 */
	private function isSameUser(CcmLeafCall $call): bool {
		$a = (string)$call->argField(index: 0);
		$b = (string)$call->argField(index: 1);
		return $a !== '' && $a === $b;
	}//end isSameUser()

	/**
	 * Evaluate a signal operator (event matches, deviates from baseline,
	 * count of similar in period exceeds).
	 *
	 * @param CcmLeafCall $call The leaf node bound to its context.
	 *
	 * @return bool The boolean truth of the leaf.
	 */
	private function evaluateSignalLeaf(CcmLeafCall $call): bool {
		return match ($call->operator) {
			'event-matches' => (string)($call->context['event'] ?? '') === (string)$call->operand,
			'value-deviates-from-baseline' => $this->deviatesFromBaseline(call: $call),
			default => (int)$call->field(key: 'similar_count') > (int)$call->arg(index: 2, default: 0),
		};
	}//end evaluateSignalLeaf()

	/**
	 * Unknown operator at evaluation time fails closed (validation should
	 * already have rejected it on registration).
	 *
	 * @param string $operator The unknown operator name.
	 *
	 * @return bool Always false.
	 */
	private function rejectUnknownOperator(string $operator): bool {
		$this->logger->warning('CcmRuleEngine: unknown operator at evaluation: ' . $operator);
		return false;
	}//end rejectUnknownOperator()

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
	 * @param CcmLeafCall $call The leaf node; args are [field, scope, zThreshold, window].
	 *
	 * @return bool True when the value deviates beyond the threshold.
	 */
	private function deviatesFromBaseline(CcmLeafCall $call): bool {
		$args = $call->args;
		$value = (float)($call->fields[(string)($args[0] ?? '')] ?? 0);
		$scope = (string)($args[1] ?? '');
		$threshold = (float)($args[2] ?? 3.0);

		$baselines = (array)($call->context['baselines'] ?? []);
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
