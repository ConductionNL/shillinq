<?php

/**
 * Shillinq SBR XBRL report generator
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\Sbr\AnnualAccountsXbrlBuilder;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use RuntimeException;

/**
 * Answers the `sbr-xbrl` report: the KvK XBRL instance of the period's annual accounts.
 *
 * The financial statement is the one the context names (`statementId`), else
 * the administration's statement whose reporting period ends in the year of
 * the report period. Generating it builds (or rebuilds, while draft) the statement's
 * draft XbrlInstance, which the SBR filings page then lists, and returns its
 * XML as the report file.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-annual-accounts-produce-their-kvk-xbrl-instance-req-tdf-002
 */
final class SbrXbrlReportGenerator implements ReportGeneratorInterface {

	/**
	 * Constructor.
	 *
	 * @param SbrRecords                $records The financial statements.
	 * @param AnnualAccountsXbrlBuilder $builder Builds the instance.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly AnnualAccountsXbrlBuilder $builder,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public static function reportType(): string {
		return 'sbr-xbrl';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public static function supportedFormats(): array {
		return ['xbrl'];
	}//end supportedFormats()

	/**
	 * Build the period's annual accounts instance and return its XML.
	 *
	 * @param array<string, mixed> $context `{ period, administrationId, statementId? }`.
	 * @param string               $format  Must be 'xbrl'.
	 *
	 * @return GeneratedFile
	 *
	 * @throws RuntimeException When the format is not XBRL or the period has no financial statement.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
	 */
	public function generate(array $context, string $format): GeneratedFile {
		if ($format !== 'xbrl') {
			throw new RuntimeException(sprintf('The SBR filing is XBRL only, not %s.', $format));
		}

		$administrationId = trim((string) ($context['administrationId'] ?? ''));
		$period           = trim((string) ($context['period'] ?? ''));
		$statement        = $this->statement(context: $context, administrationId: $administrationId, period: $period);
		if ($statement === null) {
			throw new RuntimeException(
				sprintf('No financial statement ends in %s. Prepare the annual accounts first.', $period)
			);
		}

		$instance = $this->builder->build(statement: $statement, administrationId: $administrationId);

		return new GeneratedFile(
			fileName: sprintf('sbr-xbrl-%s.xbrl', (string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($instance['instanceNumber'] ?? $period))),
			mimeType: 'application/xml',
			format: 'xbrl',
			content: (string) ($instance['instanceXml'] ?? ''),
		);
	}//end generate()

	/**
	 * The statement the context names, else the one whose period ends in the report period.
	 *
	 * @param array<string,mixed> $context          The report context.
	 * @param string              $administrationId The administration.
	 * @param string              $period           The report period (2025, 2025-Q4, 2025-12).
	 *
	 * @return array<string,mixed>|null The statement.
	 */
	private function statement(array $context, string $administrationId, string $period): ?array {
		$named = trim((string) ($context['statementId'] ?? ''));
		if ($named !== '') {
			return $this->records->find(schema: 'FinancialStatement', id: $named);
		}

		if ($period === '') {
			return null;
		}

		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$year = substr($period, 0, 4);
		foreach ($this->records->findAll(schema: 'FinancialStatement', filters: $filters) as $statement) {
			$end = substr(trim((string) ($statement['reportingPeriodEnd'] ?? '')), 0, 10);
			if ($end !== '' && str_starts_with($end, $year) === true) {
				return $statement;
			}
		}

		return null;
	}//end statement()
}//end class
