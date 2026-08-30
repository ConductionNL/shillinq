<?php

/**
 * BTW-aangifte (VAT return) data-report generator
 *
 * Renders a Dutch periodic VAT return (BTW-aangifte) natively as XML using
 * XMLWriter, laid out by rubriek. It prefers a stored VatReturnFiling record for
 * the period (outputVat / deductibleVat / intraCommunitySupplies) and otherwise
 * derives the rubriek totals from the period's ARInvoice rows: rubriek 1a is the
 * high-rate (21%) turnover + tax, 1b the low/reduced-rate (9%) turnover + tax, 5a
 * the total output tax (1a+1b), 5b the deductible input tax, and the net amount
 * payable is 5a − 5b. Rendering is byte-native — no office or XML-DOM library is
 * used; an empty period still produces a well-formed return with zero totals.
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
 *       capability (REQ-VBTW-004 — the ADR-031 aggregation anti-pattern), so
 *       retagging it at bookkeeping-vat-btw-filing would make the gate pass
 *       against a requirement the code breaks. That contradiction is #525.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. Note in
 * particular that bookkeeping-vat-btw-filing REQ-VBTW-004 forbids a PHP
 * service deriving the rubrieken by walking the GL, calling it the ADR-031
 * aggregation anti-pattern — pointing there would report conformance to a
 * rule this code breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use XMLWriter;

/**
 * BTW-aangifte XML generator built natively from VatReturnFiling + ARInvoice.
 */
final class VatReturnReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

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
	 */
	public static function reportType(): string {
		return 'vat-return';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['xml'];
	}//end supportedFormats()

	/**
	 * Render the VAT return for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'xml'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$period = $this->contextString($context, 'period');

		$section = $this->deriveFromInvoices($context);

		// A stored VatReturnFiling overrides the derived output/input VAT where
		// present (the filing carries the authoritative submitted totals).
		$filing = $this->latestFiling($context);
		if ($filing !== null) {
			$output = $this->toFloat($filing['outputVat'] ?? 0);
			if ($output > 0) {
				$section['5a'] = $output;
			}

			$section['5b'] = $this->toFloat($filing['deductibleVat'] ?? $section['5b']);
			$section['3b'] = $this->toFloat($filing['intraCommunitySupplies'] ?? $section['3b']);
		}

		$payable = ($section['5a'] - $section['5b']);

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElement('BTWAangifte');
		$writer->writeAttribute('period', $period);
		$writer->writeAttribute('administration', $this->contextString($context, 'administrationId'));
		$writer->writeAttribute('valuta', 'EUR');
		$writer->writeAttribute('opgesteld', gmdate('Y-m-d\TH:i:s\Z'));

		// Rubriek 1a — leveringen/diensten belast met hoog tarief (21%).
		$this->writeRubriek($writer, '1a', 'Leveringen/diensten belast met hoog tarief', $section['1a_base'], $section['1a_vat']);
		// Rubriek 1b — leveringen/diensten belast met laag tarief (9%).
		$this->writeRubriek($writer, '1b', 'Leveringen/diensten belast met laag tarief', $section['1b_base'], $section['1b_vat']);
		// Rubriek 3b — intracommunautaire leveringen.
		$this->writeRubriek($writer, '3b', 'Leveringen naar landen binnen de EU', $section['3b'], 0.0);
		// Rubriek 5a — verschuldigde omzetbelasting (totaal).
		$this->writeRubriek($writer, '5a', 'Verschuldigde omzetbelasting', null, $section['5a']);
		// Rubriek 5b — voorbelasting.
		$this->writeRubriek($writer, '5b', 'Voorbelasting', null, $section['5b']);

		// Rubriek 5g — eindtotaal te betalen / terug te vragen.
		$writer->startElement('Rubriek');
		$writer->writeAttribute('code', '5g');
		$writer->writeElement('Omschrijving', 'Totaal te betalen / terug te vragen');
		$writer->writeElement('Omzetbelasting', $this->money($payable));
		$writer->endElement();

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
	 * Derive the rubriek base/VAT totals from the period's ARInvoice rows.
	 *
	 * High rate is recognised as a vatBreakdown group with rate >= 20; reduced rate
	 * as 0 < rate < 20. Falls back to the invoice's netAmount/vatAmount split by the
	 * dominant rate when no vatBreakdown is present.
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,float>
	 */
	private function deriveFromInvoices(array $context): array {
		$totals = [
			'1a_base' => 0.0,
			'1a_vat' => 0.0,
			'1b_base' => 0.0,
			'1b_vat' => 0.0,
			'3b' => 0.0,
			'5a' => 0.0,
			'5b' => 0.0,
		];

		foreach ($this->loadAll('ARInvoice', $this->lineFilters($context)) as $invoice) {
			$breakdown = $invoice['vatBreakdown'] ?? null;
			if (is_array($breakdown) === true && $breakdown !== []) {
				foreach ($breakdown as $group) {
					if (is_array($group) === false) {
						continue;
					}

					$rate = $this->toFloat($group['rate'] ?? 0);
					$base = $this->toFloat($group['taxableAmount'] ?? 0);
					$taxPart = $this->toFloat($group['taxAmount'] ?? 0);
					$this->accumulate($totals, $rate, $base, $taxPart);
				}

				continue;
			}

			// No breakdown: split by a single nominal rate inferred from net/vat.
			$net = $this->toFloat($invoice['netAmount'] ?? 0);
			$vat = $this->toFloat($invoice['vatAmount'] ?? 0);
			$rate = ($net > 0.0) ? round((($vat / $net) * 100), 0) : 0.0;
			$this->accumulate($totals, $rate, $net, $vat);
		}//end foreach

		$totals['5a'] = ($totals['1a_vat'] + $totals['1b_vat']);

		return $totals;
	}//end deriveFromInvoices()

	/**
	 * Add one taxable group's amounts into the rubriek totals by rate band.
	 *
	 * @param array<string,float> $totals Running rubriek totals (by reference).
	 * @param float $rate The VAT rate percentage.
	 * @param float $base The taxable base amount.
	 * @param float $taxPart The tax amount.
	 *
	 * @return void
	 */
	private function accumulate(array &$totals, float $rate, float $base, float $taxPart): void {
		if ($rate >= 20.0) {
			$totals['1a_base'] += $base;
			$totals['1a_vat'] += $taxPart;
			return;
		}

		if ($rate > 0.0) {
			$totals['1b_base'] += $base;
			$totals['1b_vat'] += $taxPart;
		}

	}//end accumulate()

	/**
	 * Pick the most relevant stored VatReturnFiling for the context, if any.
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latestFiling(array $context): ?array {
		$filings = $this->loadAll('VatReturnFiling', $this->administrationFilter($context));
		if ($filings === []) {
			return null;
		}

		$period = $this->contextString($context, 'period');
		if ($period !== '') {
			foreach ($filings as $filing) {
				$end = (string)($filing['periodEndDate'] ?? '');
				if ($end !== '' && str_starts_with($end, substr($period, 0, 4)) === true) {
					return $filing;
				}
			}
		}

		return $filings[0];
	}//end latestFiling()

	/**
	 * Write a single rubriek element.
	 *
	 * @param XMLWriter $writer The XML writer.
	 * @param string $code Rubriek code (e.g. '1a').
	 * @param string $label Human description.
	 * @param float|null $base Taxable base (omitted when null).
	 * @param float $vatAmount The VAT amount.
	 *
	 * @return void
	 */
	private function writeRubriek(XMLWriter $writer, string $code, string $label, ?float $base, float $vatAmount): void {
		$writer->startElement('Rubriek');
		$writer->writeAttribute('code', $code);
		$writer->writeElement('Omschrijving', $label);
		if ($base !== null) {
			$writer->writeElement('Bedrag', $this->money($base));
		}

		$writer->writeElement('Omzetbelasting', $this->money($vatAmount));
		$writer->endElement();

	}//end writeRubriek()
}//end class
