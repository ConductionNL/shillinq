<?php

/**
 * CCM leaf operator call
 *
 * One leaf node of a compiled CCM rule DSL tree, paired with the transaction
 * context it is evaluated against. Exposes the operator, its operand, the
 * operand read as a positional argument list, and the context fields, so each
 * leaf operator family of CcmRuleEngine reads the same derived values.
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

/**
 * A leaf DSL node bound to its evaluation context (read-only).
 *
 * @spec openspec/specs/bookkeeping-ccm-rule-engine/spec.md#req-ccm-002-the-rule-dsl-shall-be-a-constrained-json-expression-language-with-deterministic-evaluation-no-eval-no-arbitrary-code-execution
 */
final class CcmLeafCall {
	/**
	 * The leaf operator name (the node's single key).
	 *
	 * @var string
	 */
	public readonly string $operator;

	/**
	 * The raw operand of the operator.
	 *
	 * @var mixed
	 */
	public readonly mixed $operand;

	/**
	 * The operand read as a positional argument list.
	 *
	 * @var array<int|string,mixed>
	 */
	public readonly array $args;

	/**
	 * The transaction fields of the context.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $fields;

	/**
	 * The full transaction context.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $context;

	/**
	 * Bind a leaf node to its context.
	 *
	 * @param array<string,mixed> $node    The single-key DSL leaf node.
	 * @param array<string,mixed> $context The transaction context.
	 */
	public function __construct(array $node, array $context) {
		$this->operator = (string)array_key_first($node);
		$this->operand = $node[$this->operator];
		$this->args = (array)$this->operand;
		$this->fields = (array)($context['fields'] ?? []);
		$this->context = $context;
	}//end __construct()

	/**
	 * The positional argument at an index, or a default when absent.
	 *
	 * @param int   $index   The argument position.
	 * @param mixed $default The value used when the argument is absent.
	 *
	 * @return mixed The argument value.
	 *
	 * @spec openspec/specs/bookkeeping-ccm-rule-engine/spec.md#req-ccm-002-the-rule-dsl-shall-be-a-constrained-json-expression-language-with-deterministic-evaluation-no-eval-no-arbitrary-code-execution
	 */
	public function arg(int $index, mixed $default): mixed {
		return $this->args[$index] ?? $default;
	}//end arg()

	/**
	 * The context field named by a key, or null.
	 *
	 * @param mixed $key The field key.
	 *
	 * @return mixed The resolved value, or null.
	 *
	 * @spec openspec/specs/bookkeeping-ccm-rule-engine/spec.md#req-ccm-002-the-rule-dsl-shall-be-a-constrained-json-expression-language-with-deterministic-evaluation-no-eval-no-arbitrary-code-execution
	 */
	public function field(mixed $key): mixed {
		return $this->fields[(string)$key] ?? null;
	}//end field()

	/**
	 * The context field named by the positional argument at an index.
	 *
	 * @param int $index The argument position holding the field name.
	 *
	 * @return mixed The resolved value, or null.
	 *
	 * @spec openspec/specs/bookkeeping-ccm-rule-engine/spec.md#req-ccm-002-the-rule-dsl-shall-be-a-constrained-json-expression-language-with-deterministic-evaluation-no-eval-no-arbitrary-code-execution
	 */
	public function argField(int $index): mixed {
		return $this->field(key: $this->arg(index: $index, default: ''));
	}//end argField()
}//end class
