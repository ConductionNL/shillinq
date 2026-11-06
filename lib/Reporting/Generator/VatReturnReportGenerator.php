<?php

/**
 * BTW-aangifte (VAT return) data-report generator
 *
 * Renders the prepared VAT return of the period (a BtwAangifte with its
 * VATDeclaration records, one per return box) as an XML file laid out by
 * rubriek. The figures are the declarations as VATReturnService prepared them
 * from the booked ledger lines, so the file, the return page and the
 * correction check show the same numbers (REQ-VBTW-004). Nothing is derived
 * here: a period without a prepared return is refused with a message that
 * says so, rather than rendered as a return of zeros someone could file.
 *
 * Rendering is byte-native (XMLWriter), no office or XML-DOM library is used.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md#requirement-req-vbtw-004-the-btw-journal-shall-be-derived-from-period-filtered-gl-aggregations
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use XMLWriter;

/**
 * BTW-aangifte XML generator built from the period's prepared return.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md#requirement-req-vbtw-004-the-btw-journal-shall-be-derived-from-period-filtered-gl-aggregations
 */
final class VatReturnReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * The rubrieken of the return form with a base and a VAT amount, in form order.
	 */
	private const BOX_LABELS = [
		'1a' => 'Leveringen/diensten belast met hoog tarief',
		'1b' => 'Leveringen/diensten belast met laag tarief',
		'1c' => 'Leveringen/diensten belast met overige tarieven, behalve 0%',
		'1d' => 'Privégebruik',
		'1e' => 'Leveringen/diensten belast met 0% of niet bij u belast',
		'2a' => 'Leveringen/diensten waarbij de omzetbelasting naar u is verlegd',
		'3a' => 'Leveringen naar landen buiten de EU',
		'3b' => 'Leveringen naar landen binnen de EU',
		'3c' => 'Installatie/afstandsverkopen binnen de EU',
		'4a' => 'Leveringen/diensten uit landen buiten de EU',
		'4b' => 'Leveringen/diensten uit landen binnen de EU',
	];

	/**
	 * The box input VAT is declared in.
	 */
	private const INPUT_VAT_BOX = '5b';

	/**
	 * Construct the VAT-return report generator.
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
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public static function reportType(): string {
		return 'vat-return';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public static function supportedFormats(): array {
		return ['xml'];
	}//end supportedFormats()

	/**
	 * Render the prepared VAT return of the context administration and period.
	 *
	 * @param array<string, mixed> $context `{ period, administrationId? }`, period as 2026, 2026-Q3 or 2026-07.
	 * @param string $format Must be 'xml'.
	 *
	 * @return GeneratedFile
	 *
	 * @throws RuntimeException When the format is not XML or the period has no prepared return.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public function generate(array $context, string $format): GeneratedFile {
		if ($format !== 'xml') {
			throw new RuntimeException(sprintf('The VAT return file is XML only, not %s.', $format));
		}

		$period = $this->contextString($context, 'period');
		$vatReturn = $this->preparedReturn($context);
		if ($vatReturn === null) {
			throw new RuntimeException(
				sprintf('No VAT return has been prepared for %s. Prepare it on the VAT returns page first.', $period)
			);
		}

		$returnId = (string)($vatReturn['id'] ?? ($vatReturn['@self']['id'] ?? ''));
		$boxes = $this->boxTotals($returnId);

		$owedCt = 0;
		foreach ($boxes as $box => $amounts) {
			if ($box !== self::INPUT_VAT_BOX) {
				$owedCt += $amounts['vat'];
			}
		}

		$deductibleCt = ($boxes[self::INPUT_VAT_BOX]['vat'] ?? 0);

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElement('BTWAangifte');
		$writer->writeAttribute('period', $period);
		$writer->writeAttribute('administration', $this->contextString($context, 'administrationId'));
		$writer->writeAttribute('aangifte', (string)($vatReturn['returnNumber'] ?? ''));
		$writer->writeAttribute('status', (string)($vatReturn['statusCode'] ?? ''));
		$writer->writeAttribute('valuta', 'EUR');
		$writer->writeAttribute('opgesteld', gmdate('Y-m-d\TH:i:s\Z'));

		foreach (self::BOX_LABELS as $box => $label) {
			$this->writeRubriek($writer, $box, $label, ($boxes[$box]['base'] ?? 0), ($boxes[$box]['vat'] ?? 0));
		}

		// A box of an operator-added tariff that is not on the form keeps its own code.
		foreach ($boxes as $box => $amounts) {
			if (isset(self::BOX_LABELS[$box]) === false && $box !== self::INPUT_VAT_BOX) {
				$this->writeRubriek($writer, $box, 'Rubriek ' . $box, $amounts['base'], $amounts['vat']);
			}
		}

		$this->writeRubriek($writer, '5a', 'Verschuldigde omzetbelasting', null, $owedCt);
		$this->writeRubriek($writer, self::INPUT_VAT_BOX, 'Voorbelasting', null, $deductibleCt);
		$this->writeRubriek($writer, '5g', 'Totaal te betalen / terug te vragen', null, ($owedCt - $deductibleCt));

		$writer->endElement();
		// End BTWAangifte.
		$writer->endDocument();

		return new GeneratedFile(
			fileName: $this->fileName('vat-return', $context, 'xml'),
			mimeType: 'text/xml',
			format: 'xml',
			content: $writer->outputMemory(),
		);

	}//end generate()

	/**
	 * The prepared return of the context period: the filed one when the period
	 * was filed, else the draft.
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	private function preparedReturn(array $context): ?array {
		$wanted = $this->periodOf($this->contextString($context, 'period'));
		if ($wanted === null) {
			return null;
		}

		$draft = null;
		foreach ($this->loadAll('BtwAangifte', $this->administrationFilter($context)) as $vatReturn) {
			if ((string)($vatReturn['period'] ?? '') !== $wanted['period']
				|| (int)($vatReturn['periodYear'] ?? 0) !== $wanted['year']
				|| ($wanted['period'] !== 'year' && (int)($vatReturn['periodNumber'] ?? 0) !== $wanted['number'])
			) {
				continue;
			}

			if ((string)($vatReturn['statusCode'] ?? 'draft') !== 'draft') {
				return $vatReturn;
			}

			$draft = ($draft ?? $vatReturn);
		}

		return $draft;
	}//end preparedReturn()

	/**
	 * Read a report period (2026, 2026-Q3 or 2026-07) as the return's period
	 * kind, year and number.
	 *
	 * @param string $period The report period.
	 *
	 * @return array{period:string,year:int,number:int}|null Null when it is none of the three.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	private function periodOf(string $period): ?array {
		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $match) === 1) {
			return ['period' => 'quarter', 'year' => (int)$match[1], 'number' => (int)$match[2]];
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) === 1) {
			return ['period' => 'month', 'year' => (int)$match[1], 'number' => (int)$match[2]];
		}

		if (preg_match('/^\d{4}$/', $period) === 1) {
			return ['period' => 'year', 'year' => (int)$period, 'number' => 0];
		}

		return null;
	}//end periodOf()

	/**
	 * The return's declarations as base and VAT in cents per box. A
	 * declaration without a box (prepared before boxes existed) cannot be
	 * placed on the form: it is left out and logged.
	 *
	 * @param string $returnId The BtwAangifte id.
	 *
	 * @return array<string,array{base:int,vat:int}>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	private function boxTotals(string $returnId): array {
		$boxes = [];
		foreach ($this->loadAll('VATDeclaration', ['filters' => ['returnId' => $returnId]]) as $declaration) {
			$box = (string)($declaration['returnBox'] ?? '');
			if ($box === '') {
				$this->logger->warning(
					'Shillinq VAT return file: a declaration without a return box is left out',
					['returnId' => $returnId, 'declaration' => (string)($declaration['declarationNumber'] ?? '')]
				);
				continue;
			}

			$boxes[$box] ??= ['base' => 0, 'vat' => 0];
			$boxes[$box]['base'] += (int)round($this->toFloat($declaration['totalTaxableAmount'] ?? 0) * 100);
			$boxes[$box]['vat'] += (int)round($this->toFloat($declaration['totalVATAmount'] ?? 0) * 100);
		}

		ksort($boxes);
		return $boxes;
	}//end boxTotals()

	/**
	 * Write a single rubriek element.
	 *
	 * @param XMLWriter $writer The XML writer.
	 * @param string $code Rubriek code (e.g. '1a').
	 * @param string $label Description on the form.
	 * @param int|null $baseCt Taxable base in cents (omitted when null).
	 * @param int $vatCt The VAT amount in cents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	private function writeRubriek(XMLWriter $writer, string $code, string $label, ?int $baseCt, int $vatCt): void {
		$writer->startElement('Rubriek');
		$writer->writeAttribute('code', $code);
		$writer->writeElement('Omschrijving', $label);
		if ($baseCt !== null) {
			$writer->writeElement('Bedrag', $this->money($baseCt / 100));
		}

		$writer->writeElement('Omzetbelasting', $this->money($vatCt / 100));
		$writer->endElement();

	}//end writeRubriek()
}//end class
