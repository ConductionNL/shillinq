<?php

/**
 * Pure-logic evaluator for recipient-rule conditions.
 *
 * Each `recipients[*].condition` field on a BookingNotificationTrigger is a
 * single comparison expression evaluated against the booking payload at
 * dispatch time. The grammar is intentionally minimal — no nested boolean
 * combinators, no function calls — so the evaluator is safe to run on
 * operator-provided strings without any kind of code-injection risk.
 *
 * Supported forms:
 *   - `true` / `false` literals → fixed result.
 *   - `<path> <op> <literal>`   → comparison.
 *
 * Operators: `==`, `!=`, `>`, `<`, `>=`, `<=`.
 * Path: dotted key resolution against the variable map (booking.price,
 *       booking.status, booking.duration).
 * Literal: bare number, single/double-quoted string, or boolean keyword.
 *
 * Empty / null condition → always true (the rule is unconditional). Any
 * malformed expression fails closed (returns false) and is logged by the
 * caller; this prevents a broken trigger from silently spamming everyone.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * Safe minimal-grammar comparison evaluator for recipient-rule conditions.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 */
final class RecipientConditionEvaluator
{

    /**
     * Recognised binary operators (order matters — longest first).
     *
     * @var array<int, string>
     */
    private const OPERATORS = ['>=', '<=', '==', '!=', '>', '<'];

    /**
     * Evaluate a condition expression against a variable map.
     *
     * @param string|null          $condition Expression (`booking.price > 100`). Empty/null = always true.
     * @param array<string, mixed> $variables Variable map (e.g. `['booking'=>['price'=>120]]`).
     *
     * @return bool True when the rule fires, false when it is skipped.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
     */
    public function evaluate(?string $condition, array $variables): bool
    {
        $expr = trim((string) $condition);
        if ($expr === '' || $expr === 'true') {
            return true;
        }

        if ($expr === 'false') {
            return false;
        }

        foreach (self::OPERATORS as $op) {
            $idx = strpos($expr, $op);
            if ($idx === false) {
                continue;
            }

            $left  = trim(substr($expr, 0, $idx));
            $right = trim(substr($expr, ($idx + strlen($op))));
            if ($left === '' || $right === '') {
                return false;
            }

            $leftValue  = $this->resolveOperand(operand: $left, variables: $variables);
            $rightValue = $this->resolveOperand(operand: $right, variables: $variables);
            return $this->compare(left: $leftValue, op: $op, right: $rightValue);
        }//end foreach

        // Malformed expression — fail closed.
        return false;
    }//end evaluate()

    /**
     * Resolve one operand to a typed value (literal or path).
     *
     * @param string               $operand   Raw operand text.
     * @param array<string, mixed> $variables Variable map.
     *
     * @return mixed|null Number / string / bool / null when unresolved.
     */
    private function resolveOperand(string $operand, array $variables)
    {
        // Quoted string literal.
        if (preg_match('/^([\'"])(.*)\1$/', $operand, $match) === 1) {
            return $match[2];
        }

        // Numeric literal.
        if (is_numeric($operand) === true) {
            return ((strpos($operand, '.') !== false) ? (float) $operand : (int) $operand);
        }

        // Boolean literal.
        if ($operand === 'true') {
            return true;
        }

        if ($operand === 'false') {
            return false;
        }

        if ($operand === 'null') {
            return null;
        }

        // Dotted path lookup.
        $segments = explode('.', $operand);
        $cursor   = $variables;
        foreach ($segments as $segment) {
            if (is_array($cursor) === true && array_key_exists($segment, $cursor) === true) {
                $cursor = $cursor[$segment];
                continue;
            }

            return null;
        }

        return $cursor;
    }//end resolveOperand()

    /**
     * Compare two resolved operands under a recognised binary operator.
     *
     * @param mixed  $left  Left value.
     * @param string $op    Binary operator.
     * @param mixed  $right Right value.
     *
     * @return bool Result of the comparison.
     */
    private function compare($left, string $op, $right): bool
    {
        // Cast both sides to a common numeric type when both look numeric so
        // payload values stored as JSON strings still compare correctly
        // ("100" > 50 → true).
        if (is_numeric($left) === true && is_numeric($right) === true) {
            $left  = ($left + 0);
            $right = ($right + 0);
        }

        switch ($op) {
            case '==':
                return $left == $right;
            case '!=':
                return $left != $right;
            case '>':
                return $left > $right;
            case '<':
                return $left < $right;
            case '>=':
                return $left >= $right;
            case '<=':
                return $left <= $right;
            default:
                return false;
        }
    }//end compare()

}//end class
