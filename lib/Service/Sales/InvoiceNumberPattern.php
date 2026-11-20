<?php

/**
 * Invoice Number Pattern
 *
 * The pattern of an invoice number series: the tokens `{year}` and
 * `{seq:N}` (the counter, left-padded with zeros to N digits) and literal
 * text around them. `{year}-{seq:4}` gives `2026-0042`.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales;

use InvalidArgumentException;

/**
 * Formats and reads the numbers of one series pattern.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 */
final class InvoiceNumberPattern {

	/**
	 * The pattern when none is set.
	 *
	 * @var string
	 */
	public const DEFAULT = '{year}-{seq:4}';

	/**
	 * The digits of the counter.
	 *
	 * @var int
	 */
	private readonly int $width;

	/**
	 * Constructor.
	 *
	 * @param string $pattern The pattern; it must hold exactly one `{seq:N}`.
	 *
	 * @throws InvalidArgumentException When the pattern has no counter, or more than one.
	 */
	public function __construct(
		private readonly string $pattern = self::DEFAULT,
	) {
		if (preg_match_all('/\{seq:(\d+)\}/', $pattern, $matches) !== 1) {
			throw new InvalidArgumentException("The invoice number pattern '{$pattern}' needs exactly one {seq:N}.");
		}

		$this->width = max(1, (int)$matches[1][0]);
	}//end __construct()

	/**
	 * The number of one counter value in one year.
	 *
	 * @param int $year     The fiscal year.
	 * @param int $sequence The counter value, from 1.
	 *
	 * @return string The invoice number.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
	 */
	public function format(int $year, int $sequence): string {
		$padded = str_pad((string)$sequence, $this->width, '0', STR_PAD_LEFT);

		return (string)preg_replace('/\{seq:\d+\}/', $padded, str_replace('{year}', (string)$year, $this->pattern));
	}//end format()

	/**
	 * The counter value of a number of this pattern and year, or null when it is not one.
	 *
	 * @param string $number The invoice number.
	 * @param int    $year   The fiscal year.
	 *
	 * @return int|null The counter value.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.1
	 */
	public function sequenceOf(string $number, int $year): ?int {
		$regex = '';
		foreach ((array)preg_split('/(\{year\}|\{seq:\d+\})/', $this->pattern, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
			$part = (string)$part;
			if ($part === '{year}') {
				$regex .= (string)$year;
				continue;
			}

			if (preg_match('/^\{seq:\d+\}$/', $part) === 1) {
				$regex .= '(?<seq>\d{' . $this->width . ',})';
				continue;
			}

			$regex .= preg_quote($part, '/');
		}

		if (preg_match('/^' . $regex . '$/', trim($number), $match) !== 1) {
			return null;
		}

		return (int)$match['seq'];
	}//end sequenceOf()
}//end class
