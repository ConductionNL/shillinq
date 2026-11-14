<?php

/**
 * Invoice Number Audit Service
 *
 * A sales invoice series must be unbroken: every number of a year issued once,
 * none skipped, none twice. This report reads one administration's issued
 * sales invoices of one year and lists the numbers that are missing between
 * the first and the highest number of the series, and the numbers that occur
 * more than once.
 *
 * Only invoices that left draft count: issued, paid, overdue, disputed and
 * written off. A draft has no number yet (the number is taken at issue), so
 * a deleted draft never shows up as a gap. A number that does not follow the
 * series pattern for that year is listed apart rather than guessed into the
 * series.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Reports missing and duplicate numbers in a year of issued sales invoices.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 */
final class InvoiceNumberAuditService {
	/**
	 * The series pattern when the administration has set none.
	 *
	 * @var string
	 */
	public const DEFAULT_PATTERN = '{year}-{seq:4}';

	/**
	 * The lifecycle states of an invoice that carries its number.
	 *
	 * @var array<int, string>
	 */
	public const NUMBERED_STATES = ['issued', 'paid', 'overdue', 'disputed', 'written-off'];

	/**
	 * Invoices read per page.
	 *
	 * @var int
	 */
	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {
	}//end __construct()

	/**
	 * The missing and duplicate numbers of one administration's sales invoices in a year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The fiscal year.
	 * @param string $pattern          The series pattern: `{year}`, `{seq:N}` and literal text.
	 *
	 * @return array<string, mixed> The report: `year`, `checked` (numbers in the series), `first` and
	 *                              `last` (or null), `missing`, `duplicates` and `outsideSeries`.
	 *
	 * @throws InvalidArgumentException When the administration is empty or the pattern has no sequence.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
	 */
	public function gaps(string $administrationId, int $year, string $pattern = self::DEFAULT_PATTERN): array {
		if (trim($administrationId) === '') {
			throw new InvalidArgumentException('An invoice number check needs an administration.');
		}

		[$regex, $width] = $this->compile(pattern: $pattern, year: $year);

		$counts = [];
		$outside = [];
		$checked = 0;
		foreach ($this->numbers(administrationId: $administrationId) as $number) {
			if (preg_match($regex, $number, $match) !== 1) {
				if ($this->isOfYear(number: $number, year: $year) === true) {
					$outside[] = $number;
				}

				continue;
			}

			$checked += 1;
			$sequence = (int)$match['seq'];
			$counts[$sequence] = (($counts[$sequence] ?? 0) + 1);
		}

		ksort($counts);
		$format = fn (int $sequence): string => $this->format(pattern: $pattern, year: $year, sequence: $sequence, width: $width);

		$missing = [];
		$duplicates = [];
		if ($counts !== []) {
			$last = max(array_keys($counts));
			for ($sequence = 1; $sequence <= $last; $sequence++) {
				if (isset($counts[$sequence]) === false) {
					$missing[] = $format($sequence);
				}
			}

			foreach ($counts as $sequence => $count) {
				if ($count > 1) {
					$duplicates[] = $format($sequence);
				}
			}
		}

		sort($outside, SORT_STRING);

		$first = null;
		$last = null;
		if ($counts !== []) {
			$first = $format(min(array_keys($counts)));
			$last = $format(max(array_keys($counts)));
		}

		return [
			'year' => $year,
			'checked' => $checked,
			'first' => $first,
			'last' => $last,
			'missing' => $missing,
			'duplicates' => $duplicates,
			'outsideSeries' => array_values(array_unique($outside)),
		];
	}//end gaps()

	/**
	 * Every number of the administration's numbered sales invoices.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, string> The numbers, trimmed, empty ones left out.
	 */
	private function numbers(string $administrationId): array {
		$scoped = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('ARInvoice');
		$numbers = [];
		$offset = 0;
		do {
			$rows = $scoped->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::PAGE, 'offset' => $offset]);
			foreach ($rows as $row) {
				$invoice = ObjectIdentifier::recordWithId(candidate: $row);
				if ($invoice === null
					|| (string)($invoice['administrationId'] ?? '') !== $administrationId
					|| in_array((string)($invoice['lifecycleState'] ?? ''), self::NUMBERED_STATES, true) === false
				) {
					continue;
				}

				$number = trim((string)($invoice['invoiceNumber'] ?? ''));
				if ($number !== '') {
					$numbers[] = $number;
				}
			}

			$offset += self::PAGE;
		} while (count($rows) === self::PAGE);

		return $numbers;
	}//end numbers()

	/**
	 * The regular expression a number of this year matches, and the sequence width.
	 *
	 * @param string $pattern The series pattern.
	 * @param int    $year    The year.
	 *
	 * @return array{0: string, 1: int} The expression and the zero-padded width.
	 *
	 * @throws InvalidArgumentException When the pattern has no single `{seq:N}`.
	 */
	private function compile(string $pattern, int $year): array {
		if (preg_match_all('/\{seq:(\d+)\}/', $pattern, $sequences) !== 1) {
			throw new InvalidArgumentException("The invoice number pattern '{$pattern}' needs exactly one {seq:N}.");
		}

		$width = (int)$sequences[1][0];
		$parts = preg_split('/(\{year\}|\{seq:\d+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
		$regex = '';
		foreach ((array)$parts as $part) {
			if ($part === '{year}') {
				$regex .= (string)$year;
				continue;
			}

			if (preg_match('/^\{seq:\d+\}$/', (string)$part) === 1) {
				$regex .= '(?<seq>\d{' . max(1, $width) . ',})';
				continue;
			}

			$regex .= preg_quote((string)$part, '/');
		}

		return ['/^' . $regex . '$/', $width];
	}//end compile()

	/**
	 * One number of the series.
	 *
	 * @param string $pattern  The series pattern.
	 * @param int    $year     The year.
	 * @param int    $sequence The sequence value.
	 * @param int    $width    The zero-padded width.
	 *
	 * @return string The number.
	 */
	private function format(string $pattern, int $year, int $sequence, int $width): string {
		$padded = str_pad((string)$sequence, $width, '0', STR_PAD_LEFT);

		return (string)preg_replace('/\{seq:\d+\}/', $padded, str_replace('{year}', (string)$year, $pattern));
	}//end format()

	/**
	 * Whether a number names the year at all, so a number of another year is
	 * not reported as outside this year's series.
	 *
	 * @param string $number The number.
	 * @param int    $year   The year.
	 *
	 * @return bool True when the year occurs in it.
	 */
	private function isOfYear(string $number, int $year): bool {
		return str_contains($number, (string)$year);
	}//end isOfYear()
}//end class
