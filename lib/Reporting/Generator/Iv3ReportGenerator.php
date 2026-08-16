<?php

/**
 * IV3 (Informatie voor derden) data-report generator
 *
 * Renders the CBS IV3 report natively, in XML or CSV, aggregating the general-ledger
 * movements by taakveld (CBS task field). Each GLLine carries a `taakveld` (directly
 * or inherited from its Account); lines are summed into per-taakveld baten (credit)
 * and lasten (debit) totals, the canonical IV3 dimension. Rendering is byte-native
 * (XMLWriter / fputcsv) — no office library is involved; an empty period produces a
 * valid empty-but-well-formed file with the totals at zero.
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
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *       NOTE: this generator is one of the three that CONTRADICT their domain
 *       capability (REQ-IV3-004 — "not a PHP renderer"), so retagging it at
 *       bookkeeping-iv3-reporting would make the gate pass against a requirement
 *       the code breaks. That contradiction is the substance of #525.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. Note in
 * particular that bookkeeping-iv3-reporting REQ-IV3-004 requires the IV3 XML
 * to be produced by OR's mapping engine and NOT by a PHP renderer, which is
 * exactly what this class is — pointing there would report conformance to a
 * rule this code breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use XMLWriter;

/**
 * IV3 taakveld-totals generator (XML + CSV) built natively from Account + GLLine.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class Iv3ReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * Construct the IV3 report generator.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'iv3';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['xml', 'csv'];
	}//end supportedFormats()

	/**
	 * Render the IV3 report for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format One of 'xml' | 'csv'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$totals = $this->aggregateByTaskField($context);
		ksort($totals);

		if ($format === 'csv') {
			return $this->renderCsv($totals, $context);
		}

		return $this->renderXml($totals, $context);
	}//end generate()

	/**
	 * Aggregate GLLine movements into per-taakveld lasten (debit) / baten (credit).
	 *
	 * The taakveld is read from the line itself, or inherited from the joined
	 * Account when the line does not carry one.
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,array{expenses:float,revenue:float}>
	 */
	private function aggregateByTaskField(array $context): array {
		$accounts = $this->indexAccountsByNumber($this->loadAll('Account', $this->administrationFilter($context)));
		$lines = $this->loadAll('GLLine', $this->lineFilters($context));

		$totals = [];
		foreach ($lines as $line) {
			if (($line['eliminationFlag'] ?? false) === true) {
				continue;
			}

			$accountNumber = (string)($line['accountNumber'] ?? '');
			$taskField = (string)($line['taskField'] ?? $accounts[$accountNumber]['taskField'] ?? '');
			if ($taskField === '') {
				$taskField = '0.0';
			}

			if (isset($totals[$taskField]) === false) {
				$totals[$taskField] = ['expenses' => 0.0, 'revenue' => 0.0];
			}

			$amount = $this->toFloat($line['amount'] ?? 0);
			if ((string)($line['side'] ?? '') === 'debit') {
				$totals[$taskField]['expenses'] += $amount;
			} else {
				$totals[$taskField]['revenue'] += $amount;
			}
		}//end foreach

		return $totals;
	}//end aggregateByTaakveld()

	/**
	 * Render the taakveld totals as CSV.
	 *
	 * @param array<string,array{expenses:float,revenue:float}> $totals Aggregated totals.
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return GeneratedFile
	 */
	private function renderCsv(array $totals, array $context): GeneratedFile {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, ['taskField', 'expenses', 'revenue', 'saldo']);
		foreach ($totals as $taskField => $row) {
			fputcsv(
				$handle,
				[
					$taskField,
					$this->money($row['expenses']),
					$this->money($row['revenue']),
					$this->money(($row['revenue'] - $row['expenses'])),
				]
			);
		}

		rewind($handle);
		$content = (string)stream_get_contents($handle);
		fclose($handle);

		return new GeneratedFile(
			fileName: $this->fileName('iv3', $context, 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: $content,
		);

	}//end renderCsv()

	/**
	 * Render the taakveld totals as IV3 XML.
	 *
	 * @param array<string,array{expenses:float,revenue:float}> $totals Aggregated totals.
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return GeneratedFile
	 */
	private function renderXml(array $totals, array $context): GeneratedFile {
		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElement('Iv3Rapportage');
		$writer->writeAttribute('period', $this->contextString($context, 'period'));
		$writer->writeAttribute('administration', $this->contextString($context, 'administrationId'));
		$writer->writeAttribute('valuta', 'EUR');
		$writer->writeAttribute('opgesteld', gmdate('Y-m-d\TH:i:s\Z'));

		foreach ($totals as $taskField => $row) {
			$writer->startElement('Taakveld');
			$writer->writeAttribute('code', (string)$taskField);
			$writer->writeElement('Lasten', $this->money($row['expenses']));
			$writer->writeElement('Baten', $this->money($row['revenue']));
			$writer->writeElement('Saldo', $this->money(($row['revenue'] - $row['expenses'])));
			$writer->endElement();
		}

		$writer->endElement();
		// End Iv3Rapportage.
		$writer->endDocument();

		return new GeneratedFile(
			fileName: $this->fileName('iv3', $context, 'xml'),
			mimeType: 'text/xml',
			format: 'xml',
			content: $writer->outputMemory(),
		);

	}//end renderXml()
}//end class
