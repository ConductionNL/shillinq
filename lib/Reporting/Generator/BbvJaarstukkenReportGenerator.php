<?php

/**
 * BBV jaarstukken (programmaverantwoording) report generator
 *
 * Produces the NL public-sector BBV jaarstukken / programmaverantwoording for
 * one fiscal year, rendered as editable DOCX/ODT or as a dompdf PDF from the
 * default in-code layout. The content is read from a real BbvStatement object in
 * the shillinq register: the document parts (art. 24), the programmaplan and its
 * programmes, the mandatory paragraphs (art. 9), the taakvelden overview (with
 * estimated revenue/expense per task field), the jaarrekening composition and
 * the capitalised fixed assets (arts. 59/62).
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
 * @spec exclude The reporting capability has no canonical spec. This tag points
 *  at no target rather than at an archived change directory, which cannot resolve.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;

/**
 * Renders BBV jaarstukken / programmaverantwoording from a BbvStatement object.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class BbvJaarstukkenReportGenerator extends AbstractDocumentReportGenerator
{
    /**
     * The catalogue report-type id this generator produces.
     *
     * @return string
     */
    public static function reportType(): string
    {
        return 'bbv-jaarstukken';

    }//end reportType()

    /**
     * Cover title for the document.
     *
     * @return string
     */
    protected function documentTitle(): string
    {
        return 'BBV-jaarstukken';

    }//end documentTitle()

    /**
     * Build the BBV jaarstukken body from the BbvStatement object.
     *
     * @param PhpWord              $phpWord The styled document.
     * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
     *
     * @return void
     */
    protected function build(PhpWord $phpWord, array $context): void
    {
        $statement = $this->resolveStatement($context);
        $currency  = $this->str($statement, 'currency');
        if ($currency === '') {
            $currency = 'EUR';
        }

        $section = $this->addSection($phpWord);
        $this->addCover(
            $section,
            'BBV-jaarstukken',
            $this->documentTypeLabel($this->str($statement, 'documentType')).' — programmaverantwoording',
            $context
        );

        // --- Kerngegevens ---
        $this->addHeading($section, 'Kerngegevens');
        $this->addDetailsTable(
            $section,
            [
                'Documenttype'  => $this->documentTypeLabel($this->str($statement, 'documentType')),
                'Soort lichaam' => $this->entityTypeLabel($this->str($statement, 'entityType')),
                'Jurisdictie'   => $this->str($statement, 'jurisdiction'),
                'Boekjaar'      => $this->str($statement, 'fiscalYear'),
                'Valuta'        => $currency,
                'Onderdelen'    => $this->joinList($statement['parts'] ?? []),
            ]
        );

        // --- Programmaplan (art. 8) ---
        $section->addTextBreak(1);
        $this->buildProgrammaplan($section, $statement, $currency);

        // --- Verplichte paragrafen (art. 9) ---
        $section->addTextBreak(1);
        $this->addHeading($section, 'Verplichte paragrafen (art. 9 BBV)');
        $this->buildParagraphs($section, $statement);

        // --- Taakvelden ---
        $section->addTextBreak(1);
        $this->buildTaakvelden($section, $statement, $currency);

        // --- Jaarrekening (art. 24) ---
        $section->addTextBreak(1);
        $this->buildJaarrekening($section, $statement);

        // --- Vaste activa (arts. 59/62) ---
        $section->addTextBreak(1);
        $this->buildFixedAssets($section, $statement, $currency);

    }//end build()

    /**
     * Build the programmaplan section: the programmes plus the general cover
     * funds, overhead, VPB charge and onvoorzien.
     *
     * @param Section              $section   The section.
     * @param array<string, mixed> $statement The BbvStatement object.
     * @param string               $currency  The presentation currency.
     *
     * @return void
     */
    private function buildProgrammaplan(Section $section, array $statement, string $currency): void
    {
        $this->addHeading($section, 'Programmaplan (art. 8 BBV)');

        $plan = $statement['programmaplan'] ?? [];
        if (is_array($plan) === false) {
            $plan = [];
        }

        $programmes = $plan['programmes'] ?? [];
        if (is_array($programmes) === true && $programmes !== []) {
            $table = $section->addTable('reportTable');
            $table->addRow();
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4), ['bgColor' => self::ACCENT_COLOUR])
                ->addText('Code', ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF']);
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(12), ['bgColor' => self::ACCENT_COLOUR])
                ->addText('Programma', ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF']);

            foreach ($programmes as $programme) {
                if (is_array($programme) === false) {
                    continue;
                }

                $table->addRow();
                $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4))->addText($this->str($programme, 'code'), 'value');
                $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(12))->addText($this->str($programme, 'name'), 'value');
            }
        } else {
            $this->addNote($section, 'Geen programma\'s opgenomen in het programmaplan.');
        }

        $section->addTextBreak(1);
        $this->addAmountTable(
            $section,
            'Algemene posten',
            'Bedrag',
            [
                ['label' => 'Algemene dekkingsmiddelen', 'amount' => $this->num($plan, 'algemeneDekkingsmiddelen')],
                ['label' => 'Overhead', 'amount' => $this->num($plan, 'overhead')],
                ['label' => 'Heffing vennootschapsbelasting (VPB)', 'amount' => $this->num($plan, 'vpbCharge')],
                ['label' => 'Onvoorzien', 'amount' => $this->num($plan, 'onvoorzien')],
            ],
            null,
            $currency
        );

    }//end buildProgrammaplan()

    /**
     * List the mandatory BBV paragraphs (art. 9), flagging which of the seven
     * required paragraphs are present.
     *
     * @param Section              $section   The section.
     * @param array<string, mixed> $statement The BbvStatement object.
     *
     * @return void
     */
    private function buildParagraphs(Section $section, array $statement): void
    {
        $required = [
            'lokaleHeffingen'           => 'Lokale heffingen',
            'weerstandsvermogen'        => 'Weerstandsvermogen en risicobeheersing',
            'onderhoudKapitaalgoederen' => 'Onderhoud kapitaalgoederen',
            'financiering'              => 'Financiering',
            'bedrijfsvoering'           => 'Bedrijfsvoering',
            'verbondenPartijen'         => 'Verbonden partijen',
            'grondbeleid'               => 'Grondbeleid',
        ];

        $present = [];
        foreach (($statement['paragraphs'] ?? []) as $paragraph) {
            $present[strtolower((string) $paragraph)] = true;
        }

        $rows = [];
        foreach ($required as $key => $label) {
            $isPresent    = (isset($present[strtolower($key)]) === true || isset($present[strtolower($label)]) === true);
            $rows[$label] = $isPresent === true ? 'Aanwezig' : 'Ontbreekt';
        }

        $this->addDetailsTable($section, $rows);

    }//end buildParagraphs()

    /**
     * Build the taakvelden overview: estimated revenue and expense per taakveld.
     *
     * @param Section              $section   The section.
     * @param array<string, mixed> $statement The BbvStatement object.
     * @param string               $currency  The presentation currency.
     *
     * @return void
     */
    private function buildTaakvelden(Section $section, array $statement, string $currency): void
    {
        $this->addHeading($section, 'Overzicht taakvelden');

        $taakvelden = $statement['taakvelden'] ?? [];
        if (is_array($taakvelden) === false || $taakvelden === []) {
            $this->addNote($section, 'Geen taakvelden opgenomen.');
            return;
        }

        $table = $section->addTable('reportTable');
        $table->addRow();
        foreach (['Code', 'Taakveld', 'Baten', 'Lasten'] as $head) {
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4), ['bgColor' => self::ACCENT_COLOUR])
                ->addText($head, ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF']);
        }

        $totalRevenue = 0.0;
        $totalExpense = 0.0;
        foreach ($taakvelden as $taakveld) {
            if (is_array($taakveld) === false) {
                continue;
            }

            $revenue       = $this->num($taakveld, 'estimatedRevenue');
            $expense       = $this->num($taakveld, 'estimatedExpense');
            $totalRevenue += $revenue;
            $totalExpense += $expense;

            $table->addRow();
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4))->addText($this->str($taakveld, 'code'), 'value');
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4))->addText($this->str($taakveld, 'name'), 'value');
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4))->addText($this->money($revenue, $currency), 'amount', ['alignment' => 'end']);
            $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4))->addText($this->money($expense, $currency), 'amount', ['alignment' => 'end']);
        }

        $table->addRow();
        $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(8), ['bgColor' => 'EEF2FF', 'gridSpan' => 2])->addText('Totaal', 'amountBold');
        $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4), ['bgColor' => 'EEF2FF'])->addText($this->money($totalRevenue, $currency), 'amountBold', ['alignment' => 'end']);
        $table->addCell(\PhpOffice\PhpWord\Shared\Converter::cmToTwip(4), ['bgColor' => 'EEF2FF'])->addText($this->money($totalExpense, $currency), 'amountBold', ['alignment' => 'end']);

    }//end buildTaakvelden()

    /**
     * Build the jaarrekening composition (art. 24): which components are present.
     *
     * @param Section              $section   The section.
     * @param array<string, mixed> $statement The BbvStatement object.
     *
     * @return void
     */
    private function buildJaarrekening(Section $section, array $statement): void
    {
        $this->addHeading($section, 'Jaarrekening (art. 24 BBV)');

        $jaarrekening = $statement['jaarrekening'] ?? [];
        if (is_array($jaarrekening) === false) {
            $jaarrekening = [];
        }

        $this->addDetailsTable(
            $section,
            [
                'Overzicht van baten en lasten' => $this->yesNo($jaarrekening['overzichtBatenLasten'] ?? null),
                'Balans'                        => $this->yesNo($jaarrekening['balans'] ?? null),
                'Rechtmatigheidsverantwoording' => $this->yesNo($jaarrekening['rechtmatigheidsverantwoording'] ?? null),
                'Accountantsverklaring'         => $this->yesNo($jaarrekening['accountantsverklaring'] ?? null),
            ]
        );

    }//end buildJaarrekening()

    /**
     * Build the fixed-assets overview (arts. 59/62): capitalisation + valuation.
     *
     * @param Section              $section   The section.
     * @param array<string, mixed> $statement The BbvStatement object.
     * @param string               $currency  The presentation currency.
     *
     * @return void
     */
    private function buildFixedAssets(Section $section, array $statement, string $currency): void
    {
        $this->addHeading($section, 'Vaste activa (art. 59/62 BBV)');

        $assets = $statement['fixedAssets'] ?? [];
        if (is_array($assets) === false || $assets === []) {
            $this->addNote($section, 'Geen vaste activa opgenomen.');
            return;
        }

        $lines = [];
        $total = 0.0;
        foreach ($assets as $asset) {
            if (is_array($asset) === false) {
                continue;
            }

            $cost    = $this->num($asset, 'acquisitionCost');
            $total  += $cost;
            $kind    = $this->str($asset, 'kind') === 'intangible' ? 'immaterieel' : 'materieel';
            $capit   = (($asset['capitalised'] ?? false) === true ? 'geactiveerd' : 'niet geactiveerd');
            $lines[] = [
                'label'  => $this->str($asset, 'name').' ('.$kind.', '.$capit.')',
                'amount' => $cost,
            ];
        }

        $this->addAmountTable(
            $section,
            'Vast actief',
            'Verkrijgings-/vervaardigingsprijs',
            $lines,
            ['label' => 'Totaal vaste activa', 'amount' => $total],
            $currency
        );

    }//end buildFixedAssets()

    /**
     * Resolve the BbvStatement object for the report. A statementId / bbvStatementId
     * in the context is honoured first; otherwise the statement whose fiscalYear
     * matches the period (and, when given, administration) is picked, falling
     * back to the first available. Returns an empty array when none match.
     *
     * @param array<string, mixed> $context `{ period, administrationId, statementId? }`.
     *
     * @return array<string, mixed>
     */
    private function resolveStatement(array $context): array
    {
        $id = $this->str($context, 'statementId', 'bbvStatementId', 'objectId');
        if ($id !== '') {
            $object = $this->loadObject('BbvStatement', $id);
            if ($object !== null) {
                return $object;
            }
        }

        $administrationId = $this->str($context, 'administrationId');
        $filters          = [];
        if ($administrationId !== '') {
            $filters['administrationId'] = $administrationId;
        }

        $candidates = $this->loadObjects('BbvStatement', $filters);
        if ($candidates === [] && $filters !== []) {
            $candidates = $this->loadObjects('BbvStatement');
        }

        if ($candidates === []) {
            return [];
        }

        $period = $this->str($context, 'period');
        if ($period !== '') {
            // Match the year (the BBV fiscalYear is a number; the period may be '2026' or '2026-...').
            $year = preg_replace('/[^0-9]/', '', $period);
            foreach ($candidates as $candidate) {
                if ($year !== '' && (string) (int) $this->num($candidate, 'fiscalYear') === (string) (int) $year) {
                    return $candidate;
                }
            }
        }

        // Prefer a jaarstukken document over a begroting when present.
        foreach ($candidates as $candidate) {
            if ($this->str($candidate, 'documentType') === 'jaarstukken') {
                return $candidate;
            }
        }

        return $candidates[0];

    }//end resolveStatement()

    /**
     * Map a documentType enum to a Dutch label.
     *
     * @param string $type The documentType value.
     *
     * @return string
     */
    private function documentTypeLabel(string $type): string
    {
        return match ($type) {
            'begroting'   => 'Begroting',
            'jaarstukken' => 'Jaarstukken',
            default       => ($type !== '' ? $type : 'Jaarstukken'),
        };

    }//end documentTypeLabel()

    /**
     * Map an entityType enum to a Dutch label.
     *
     * @param string $type The entityType value.
     *
     * @return string
     */
    private function entityTypeLabel(string $type): string
    {
        return match ($type) {
            'gemeente'  => 'Gemeente',
            'provincie' => 'Provincie',
            'waterschap' => 'Waterschap',
            default     => $type,
        };

    }//end entityTypeLabel()

    /**
     * Join a list of scalar values into a comma-separated string.
     *
     * @param mixed $list The list (array) value.
     *
     * @return string
     */
    private function joinList(mixed $list): string
    {
        if (is_array($list) === false || $list === []) {
            return '';
        }

        return implode(', ', array_map('strval', $list));

    }//end joinList()

    /**
     * Render a boolean as Ja / Nee (empty string when null).
     *
     * @param mixed $value The boolean-ish value.
     *
     * @return string
     */
    private function yesNo(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return ((bool) $value === true) ? 'Ja' : 'Nee';

    }//end yesNo()
}//end class
