<?php

/**
 * Shillinq financial-statement layout predicates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Support;

/**
 * Layout predicates over a structured FinancialStatement object, shared by the
 * national-reporting check providers. Side-effect free.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
trait ChecksStatementLayout {
	/**
	 * The nested balanceSheet carries all five statutory components as numbers
	 * (fixed/current assets; equity, provisions, liabilities).
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	private static function balanceLayoutComplete(array $o): bool {
		$bs = ($o['balanceSheet'] ?? null);
		if (is_array($bs) === false) {
			return false;
		}

		foreach (['fixedAssets', 'currentAssets', 'equity', 'provisions', 'liabilities'] as $key) {
			if (array_key_exists($key, $bs) === false || is_numeric($bs[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end balanceLayoutComplete()

	/**
	 * The nested profitAndLoss carries net turnover, operating expenses, financial
	 * result and the result, all as numbers.
	 *
	 * @param array<string, mixed> $o The object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	private static function pnlLayoutComplete(array $o): bool {
		$pl = ($o['profitAndLoss'] ?? null);
		if (is_array($pl) === false) {
			return false;
		}

		foreach (['netTurnover', 'operatingExpenses', 'financialResult', 'result'] as $key) {
			if (array_key_exists($key, $pl) === false || is_numeric($pl[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end pnlLayoutComplete()

	/**
	 * The component code $code appears in the statement's components list.
	 *
	 * @param array<string, mixed> $o    The object.
	 * @param string               $code The component code (e.g. 'balance', 'pnl').
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	private static function hasComponent(array $o, string $code): bool {
		$components = ($o['components'] ?? null);
		if (is_array($components) === false) {
			return false;
		}

		foreach ($components as $c) {
			if (strcasecmp(trim((string)$c), $code) === 0) {
				return true;
			}
		}

		return false;
	}//end hasComponent()
}//end trait
