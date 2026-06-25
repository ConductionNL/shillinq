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
 * @spec openspec/changes/reporting-compliance-consolidation/specs/reporting/spec.md
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
 */
final class Iv3ReportGenerator implements ReportGeneratorInterface
{

    use ReportDataTrait;

    /**
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param LoggerInterface    $logger    Logger.
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
    public static function reportType(): string
    {
        return 'iv3';

    }//end reportType()

    /**
     * {@inheritDoc}
     *
     * @return array<int, string>
     */
    public static function supportedFormats(): array
    {
        return ['xml', 'csv'];

    }//end supportedFormats()

    /**
     * Render the IV3 report for the context administration + period.
     *
     * @param array<string, mixed> $context `{ period?, administrationId? }`.
     * @param string               $format  One of 'xml' | 'csv'.
     *
     * @return GeneratedFile
     */
    public function generate(array $context, string $format): GeneratedFile
    {
        $totals = $this->aggregateByTaakveld($context);
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
     * @return array<string,array{lasten:float,baten:float}>
     */
    private function aggregateByTaakveld(array $context): array
    {
        $accounts = $this->indexAccountsByNumber($this->loadAll('Account', $this->administrationFilter($context)));
        $lines    = $this->loadAll('GLLine', $this->lineFilters($context));

        $totals = [];
        foreach ($lines as $line) {
            if (($line['eliminationFlag'] ?? false) === true) {
                continue;
            }

            $accountNumber = (string) ($line['accountNumber'] ?? '');
            $taakveld      = (string) ($line['taakveld'] ?? $accounts[$accountNumber]['taakveld'] ?? '');
            if ($taakveld === '') {
                $taakveld = '0.0';
            }

            if (isset($totals[$taakveld]) === false) {
                $totals[$taakveld] = ['lasten' => 0.0, 'baten' => 0.0];
            }

            $amount = $this->toFloat($line['amount'] ?? 0);
            if ((string) ($line['side'] ?? '') === 'debit') {
                $totals[$taakveld]['lasten'] += $amount;
            } else {
                $totals[$taakveld]['baten'] += $amount;
            }
        }//end foreach

        return $totals;

    }//end aggregateByTaakveld()

    /**
     * Render the taakveld totals as CSV.
     *
     * @param array<string,array{lasten:float,baten:float}> $totals  Aggregated totals.
     * @param array<string,mixed>                           $context Report context.
     *
     * @return GeneratedFile
     */
    private function renderCsv(array $totals, array $context): GeneratedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['taakveld', 'lasten', 'baten', 'saldo']);
        foreach ($totals as $taakveld => $row) {
            fputcsv(
                $handle,
                [
                    $taakveld,
                    $this->money($row['lasten']),
                    $this->money($row['baten']),
                    $this->money(($row['baten'] - $row['lasten'])),
                ]
            );
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
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
     * @param array<string,array{lasten:float,baten:float}> $totals  Aggregated totals.
     * @param array<string,mixed>                           $context Report context.
     *
     * @return GeneratedFile
     */
    private function renderXml(array $totals, array $context): GeneratedFile
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('Iv3Rapportage');
        $writer->writeAttribute('periode', $this->contextString($context, 'period'));
        $writer->writeAttribute('administratie', $this->contextString($context, 'administrationId'));
        $writer->writeAttribute('valuta', 'EUR');
        $writer->writeAttribute('opgesteld', gmdate('Y-m-d\TH:i:s\Z'));

        foreach ($totals as $taakveld => $row) {
            $writer->startElement('Taakveld');
            $writer->writeAttribute('code', (string) $taakveld);
            $writer->writeElement('Lasten', $this->money($row['lasten']));
            $writer->writeElement('Baten', $this->money($row['baten']));
            $writer->writeElement('Saldo', $this->money(($row['baten'] - $row['lasten'])));
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
