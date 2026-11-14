<?php

/**
 * ICP statement report generator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\Icp\IcpStatementFiler;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use RuntimeException;

/**
 * Serves the `icp-opgaaf` report type (REQ-ICR-002, design D3): the
 * prepared ICP statement of the context period, as its XBRL instance or as
 * a CSV of its lines. It renders what was prepared on the ICP page; a
 * period without a prepared statement is refused, not computed silently.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
 */
final class IcpReportGenerator implements ReportGeneratorInterface {

	/**
	 * Construct the generator.
	 *
	 * @param IcpStatementPreparer $preparer Finds the period's statement.
	 * @param IcpStatementFiler    $filer    Composes its instance.
	 */
	public function __construct(
		private readonly IcpStatementPreparer $preparer,
		private readonly IcpStatementFiler $filer,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
	 */
	public static function reportType(): string {
		return 'icp-opgaaf';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
	 */
	public static function supportedFormats(): array {
		return ['xml', 'csv'];
	}//end supportedFormats()

	/**
	 * Render the prepared statement of the context administration and period.
	 *
	 * @param array<string,mixed> $context `{ period, administrationId }`, period as 2026-Q3 or 2026-07.
	 * @param string              $format  'xml' or 'csv'.
	 *
	 * @return GeneratedFile
	 *
	 * @throws RuntimeException When the format is unsupported or nothing was prepared.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
	 */
	public function generate(array $context, string $format): GeneratedFile {
		if (in_array($format, self::supportedFormats(), true) === false) {
			throw new RuntimeException(sprintf('The ICP statement is XML or CSV, not %s.', $format));
		}

		$period = trim((string)($context['period'] ?? ''));
		$administrationId = trim((string)($context['administrationId'] ?? ''));
		$statement = $this->preparer->statementOf(administrationId: $administrationId, period: $period);
		if ($statement === null) {
			throw new RuntimeException(
				sprintf('No ICP statement has been prepared for %s. Prepare it on the ICP page first.', $period)
			);
		}

		if ($format === 'xml') {
			return new GeneratedFile(
				fileName: self::fileName(period: $period, extension: 'xml'),
				mimeType: 'text/xml',
				format: 'xml',
				content: $this->filer->instanceXml(administrationId: $administrationId, period: $period),
			);
		}

		$rows = [['buyerVatId', 'supplyType', 'amountExclVat']];
		foreach ((array)($statement['lines'] ?? []) as $line) {
			if (is_array($line) === true) {
				$rows[] = [
					(string)($line['buyerVatId'] ?? ''),
					(string)($line['supplyType'] ?? ''),
					self::money(amount: ($line['amountExclVat'] ?? 0)),
				];
			}
		}

		$rows[] = ['total', '', self::money(amount: ($statement['total'] ?? 0))];

		return new GeneratedFile(
			fileName: self::fileName(period: $period, extension: 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: self::csv(rows: $rows),
		);
	}//end generate()

	/**
	 * The file name of the period's statement.
	 *
	 * @param string $period    The period.
	 * @param string $extension The extension.
	 *
	 * @return string
	 */
	private static function fileName(string $period, string $extension): string {
		return 'icp-opgaaf-' . preg_replace('/[^A-Za-z0-9_-]/', '', $period) . '.' . $extension;
	}//end fileName()

	/**
	 * An amount with two decimals, half-even, a dot and no thousands.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return string
	 */
	private static function money(mixed $amount): string {
		$value = 0.0;
		if (is_numeric($amount) === true) {
			$value = (float)$amount;
		}

		$rounded = round(num: $value, precision: 2, mode: PHP_ROUND_HALF_EVEN);

		return number_format(num: $rounded, decimals: 2, decimal_separator: '.', thousands_separator: '');
	}//end money()

	/**
	 * Rows as CSV.
	 *
	 * @param array<int,array<int,string>> $rows The rows, header first.
	 *
	 * @return string
	 */
	private static function csv(array $rows): string {
		$handle = fopen(filename: 'php://temp', mode: 'r+');
		foreach ($rows as $row) {
			fputcsv(stream: $handle, fields: $row, escape: '');
		}

		rewind(stream: $handle);
		$content = (string)stream_get_contents(stream: $handle);
		fclose(stream: $handle);

		return $content;
	}//end csv()
}//end class
