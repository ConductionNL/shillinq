<?php

/**
 * Abstract base for DOCUMENT report generators
 *
 * Shared machinery for the "document" flavour of the report catalogue — the
 * jaarrekening, balans, winst-en-verliesrekening, management letter and BBV
 * jaarstukken. A document generator builds a single PhpWord document from a
 * DEFAULT layout defined in code (so a fresh install renders without any
 * external template asset) and emits it through one of three writers:
 *
 *  - DOCX via \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')
 *  - ODT  via \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'ODText')
 *  - PDF  by configuring \PhpOffice\PhpWord\Settings::setPdfRendererName(
 *         Settings::PDF_RENDERER_DOMPDF) + setPdfRendererPath(<dompdf dir>) and
 *         createWriter($phpWord, 'PDF') — dompdf renders the HTML projection.
 *
 * All three writers — PhpWord, dompdf — come from the PHPOffice libraries
 * BUNDLED IN OPENREGISTER, a runtime dependency whose autoloader is always
 * active, so this class merely `use`s the classes and adds nothing to
 * shillinq's composer.json.
 *
 * The concrete generators are instantiated with no constructor arguments by
 * ReportGenerationService (mirroring RuleEngine provider discovery), so this
 * base resolves OpenRegister's ObjectService and the register slug lazily from
 * the Nextcloud server container rather than via constructor injection.
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
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\Shared\Converter;
use Throwable;

/**
 * Base class carrying the default layout + the three-writer PhpWord pipeline.
 */
abstract class AbstractDocumentReportGenerator implements ReportGeneratorInterface
{

    /**
     * Editable formats first, PDF last — the order ReportCatalogue offers.
     */
    private const FORMATS = ['docx', 'odt', 'pdf'];

    /**
     * Per-format MIME types for the rendered file.
     */
    private const MIME_TYPES = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'pdf'  => 'application/pdf',
    ];

    /**
     * The default theme colour (Conduction cobalt) for headings/accents.
     */
    protected const ACCENT_COLOUR = '1A2A6C';

    /**
     * Muted colour for sub-labels and footers.
     */
    protected const MUTED_COLOUR = '6B7280';

    /**
     * The formats every document generator can emit, editable first.
     *
     * @return array<int, string>
     */
    public static function supportedFormats(): array
    {
        return self::FORMATS;

    }//end supportedFormats()

    /**
     * Render the report: the subclass builds the PhpWord document from real
     * objects, this base serialises it through the requested writer.
     *
     * @param array<string, mixed> $context `{ reportType, period, administrationId, ... }`.
     * @param string               $format  One of supportedFormats().
     *
     * @return GeneratedFile
     */
    public function generate(array $context, string $format): GeneratedFile
    {
        $useFormat = in_array($format, self::FORMATS, true) === true ? $format : 'docx';

        $phpWord = $this->newDocument();
        $this->build($phpWord, $context);

        $bytes    = $this->render($phpWord, $useFormat);
        $fileName = $this->fileName($context, $useFormat);

        return new GeneratedFile(
            fileName: $fileName,
            mimeType: (self::MIME_TYPES[$useFormat] ?? 'application/octet-stream'),
            format: $useFormat,
            content: $bytes,
        );

    }//end generate()

    /**
     * Build the report body into the prepared PhpWord document. The subclass
     * loads its real objects and lays out the sections; the default cover/styles
     * are already applied by newDocument().
     *
     * @param PhpWord              $phpWord The styled, ready-to-fill document.
     * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
     *
     * @return void
     */
    abstract protected function build(PhpWord $phpWord, array $context): void;

    /**
     * A human title for the report's cover block (e.g. 'Jaarrekening').
     *
     * @return string
     */
    abstract protected function documentTitle(): string;

    // -----------------------------------------------------------------------
    // Default layout / PhpWord build helper.
    // -----------------------------------------------------------------------

    /**
     * Create a PhpWord document pre-loaded with the DEFAULT shillinq layout:
     * document metadata, a base font, named paragraph/heading/table styles and
     * an A4 portrait section geometry. This is the in-code template — no
     * external template file is required for a fresh install to render.
     *
     * @return PhpWord
     */
    protected function newDocument(): PhpWord
    {
        $phpWord = new PhpWord();

        $properties = $phpWord->getDocInfo();
        $properties->setCreator('Shillinq');
        $properties->setCompany('Conduction B.V.');
        $properties->setTitle($this->documentTitle());
        $properties->setCategory('Reporting & Compliance');
        $properties->setDescription('Generated by Shillinq Reporting & Compliance.');

        $phpWord->setDefaultFontName('DejaVu Sans');
        $phpWord->setDefaultFontSize(10);

        $phpWord->addTitleStyle(
            1,
            ['name' => 'DejaVu Sans', 'size' => 16, 'bold' => true, 'color' => self::ACCENT_COLOUR],
            ['spaceBefore' => Converter::pointToTwip(14), 'spaceAfter' => Converter::pointToTwip(6)]
        );
        $phpWord->addTitleStyle(
            2,
            ['name' => 'DejaVu Sans', 'size' => 12, 'bold' => true, 'color' => self::ACCENT_COLOUR],
            ['spaceBefore' => Converter::pointToTwip(10), 'spaceAfter' => Converter::pointToTwip(4)]
        );

        $phpWord->addFontStyle('coverTitle', ['name' => 'DejaVu Sans', 'size' => 26, 'bold' => true, 'color' => self::ACCENT_COLOUR]);
        $phpWord->addFontStyle('coverSubtitle', ['name' => 'DejaVu Sans', 'size' => 13, 'color' => self::MUTED_COLOUR]);
        $phpWord->addFontStyle('coverMeta', ['name' => 'DejaVu Sans', 'size' => 10, 'color' => self::MUTED_COLOUR]);
        $phpWord->addFontStyle('label', ['name' => 'DejaVu Sans', 'size' => 10, 'color' => self::MUTED_COLOUR]);
        $phpWord->addFontStyle('value', ['name' => 'DejaVu Sans', 'size' => 10]);
        $phpWord->addFontStyle('amount', ['name' => 'DejaVu Sans', 'size' => 10]);
        $phpWord->addFontStyle('amountBold', ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true]);
        $phpWord->addFontStyle('note', ['name' => 'DejaVu Sans', 'size' => 9, 'color' => self::MUTED_COLOUR]);

        $phpWord->addParagraphStyle('default', ['spaceAfter' => Converter::pointToTwip(6), 'lineHeight' => 1.15]);
        $phpWord->addParagraphStyle('tight', ['spaceAfter' => Converter::pointToTwip(2)]);

        $phpWord->addTableStyle(
            'reportTable',
            [
                'borderColor' => 'D1D5DB',
                'borderSize'  => 6,
                'cellMargin'  => 60,
            ],
            [
                'bgColor' => 'EEF2FF',
            ]
        );

        return $phpWord;

    }//end newDocument()

    /**
     * Add a styled cover/heading block to a section: a big title, an optional
     * subtitle, the period/administration meta line and a divider.
     *
     * @param Section              $section  The section to write into.
     * @param string               $title    The cover title.
     * @param string               $subtitle Subtitle / report type label.
     * @param array<string, mixed> $context  Generation context for the meta line.
     *
     * @return void
     */
    protected function addCover(Section $section, string $title, string $subtitle, array $context): void
    {
        $section->addText($title, 'coverTitle', ['spaceAfter' => Converter::pointToTwip(2)]);
        if ($subtitle !== '') {
            $section->addText($subtitle, 'coverSubtitle', ['spaceAfter' => Converter::pointToTwip(8)]);
        }

        $period           = (string) ($context['period'] ?? '');
        $administrationId = (string) ($context['administrationId'] ?? '');
        $metaParts        = [];
        if ($period !== '') {
            $metaParts[] = 'Periode: '.$period;
        }

        if ($administrationId !== '') {
            $metaParts[] = 'Administratie: '.$administrationId;
        }

        $metaParts[] = 'Gegenereerd: '.gmdate('Y-m-d H:i').' UTC';
        $section->addText(implode('   ·   ', $metaParts), 'coverMeta', ['spaceAfter' => Converter::pointToTwip(10)]);

        $section->addTextBreak(1);

    }//end addCover()

    /**
     * Add a level-2 section heading.
     *
     * @param Section $section The section to write into.
     * @param string  $heading The heading text.
     *
     * @return void
     */
    protected function addHeading(Section $section, string $heading): void
    {
        $section->addTitle($heading, 2);

    }//end addHeading()

    /**
     * Add a key/value details table (two columns) from an associative array.
     * Empty values are skipped so the default layout stays clean on sparse data.
     *
     * @param Section               $section The section to write into.
     * @param array<string, string> $rows    Label => value pairs (insertion order kept).
     *
     * @return void
     */
    protected function addDetailsTable(Section $section, array $rows): void
    {
        $rows = array_filter($rows, static fn($value): bool => trim((string) $value) !== '');
        if ($rows === []) {
            $section->addText('Geen gegevens beschikbaar.', 'note');
            return;
        }

        $table = $section->addTable('reportTable');
        foreach ($rows as $label => $value) {
            $table->addRow();
            $labelCell = $table->addCell(Converter::cmToTwip(6));
            $labelCell->addText((string) $label, 'label');
            $valueCell = $table->addCell(Converter::cmToTwip(10));
            $valueCell->addText((string) $value, 'value');
        }

    }//end addDetailsTable()

    /**
     * Add a financial amount table: a header row, then label/amount lines, then
     * an optional bold total row. Amounts are right-aligned and formatted.
     *
     * @param Section                                                               $section    The section to write into.
     * @param string                                                                $labelHead  Header for the label column.
     * @param string                                                                $amountHead Header for the amount column.
     * @param array<int, array{label: string, amount: float|int|null, bold?: bool}> $lines      The body lines.
     * @param array{label: string, amount: float|int}|null                          $total      Optional total row.
     * @param string                                                                $currency   ISO-4217 currency for formatting.
     *
     * @return void
     */
    protected function addAmountTable(
        Section $section,
        string $labelHead,
        string $amountHead,
        array $lines,
        ?array $total=null,
        string $currency='EUR'
    ): void {
        if ($lines === [] && $total === null) {
            $section->addText('Geen posten in deze periode.', 'note');
            return;
        }

        $table = $section->addTable('reportTable');

        $table->addRow();
        $table->addCell(Converter::cmToTwip(11), ['bgColor' => self::ACCENT_COLOUR])
            ->addText($labelHead, ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF']);
        $table->addCell(Converter::cmToTwip(5), ['bgColor' => self::ACCENT_COLOUR])
            ->addText($amountHead, ['name' => 'DejaVu Sans', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF'], ['alignment' => 'end']);

        foreach ($lines as $line) {
            $bold  = (bool) ($line['bold'] ?? false);
            $style = $bold === true ? 'amountBold' : 'amount';
            $table->addRow();
            $table->addCell(Converter::cmToTwip(11))->addText((string) ($line['label'] ?? ''), $style);
            $table->addCell(Converter::cmToTwip(5))
                ->addText($this->amountCell($line['amount'] ?? null, $currency), $style, ['alignment' => 'end']);
        }

        if ($total !== null) {
            $table->addRow();
            $table->addCell(Converter::cmToTwip(11), ['bgColor' => 'EEF2FF'])->addText((string) ($total['label'] ?? 'Totaal'), 'amountBold');
            $table->addCell(Converter::cmToTwip(5), ['bgColor' => 'EEF2FF'])
                ->addText($this->amountCell($total['amount'] ?? 0, $currency), 'amountBold', ['alignment' => 'end']);
        }

    }//end addAmountTable()

    /**
     * Format an amount-table cell: a money string when a currency is given, or a
     * plain integer count when the currency is the empty string (used for the
     * count/severity tables that are not monetary).
     *
     * @param float|int|string|null $amount   The cell value.
     * @param string                $currency The currency code, or '' for counts.
     *
     * @return string
     */
    private function amountCell(float|int|string|null $amount, string $currency): string
    {
        if (trim($currency) === '') {
            $value = is_numeric($amount) === true ? (float) $amount : 0.0;
            return number_format($value, 0, ',', '.');
        }

        return $this->money($amount, $currency);

    }//end amountCell()

    /**
     * Add a paragraph of body text (toelichting / notes).
     *
     * @param Section $section The section to write into.
     * @param string  $text    The paragraph text.
     *
     * @return void
     */
    protected function addParagraph(Section $section, string $text): void
    {
        $section->addText($text, 'value', 'default');

    }//end addParagraph()

    /**
     * Add a small grey footnote / note line.
     *
     * @param Section $section The section to write into.
     * @param string  $text    The note text.
     *
     * @return void
     */
    protected function addNote(Section $section, string $text): void
    {
        $section->addText($text, 'note', 'tight');

    }//end addNote()

    /**
     * Add a default A4 portrait section with comfortable margins.
     *
     * @param PhpWord $phpWord The document.
     *
     * @return Section
     */
    protected function addSection(PhpWord $phpWord): Section
    {
        return $phpWord->addSection(
            [
                'orientation'  => 'portrait',
                'marginTop'    => Converter::cmToTwip(2.2),
                'marginBottom' => Converter::cmToTwip(2.0),
                'marginLeft'   => Converter::cmToTwip(2.2),
                'marginRight'  => Converter::cmToTwip(2.2),
            ]
        );

    }//end addSection()

    // -----------------------------------------------------------------------
    // Three-writer rendering pipeline.
    // -----------------------------------------------------------------------

    /**
     * Serialise the PhpWord document through the requested writer and return the
     * raw bytes. DOCX uses the Word2007 writer, ODT the ODText writer, PDF the
     * dompdf-backed PDF writer (Settings::PDF_RENDERER_DOMPDF + the bundled
     * dompdf path). All writers are bundled in OpenRegister.
     *
     * @param PhpWord $phpWord The built document.
     * @param string  $format  docx | odt | pdf.
     *
     * @return string The rendered file bytes.
     */
    protected function render(PhpWord $phpWord, string $format): string
    {
        $writerName = match ($format) {
            'odt' => 'ODText',
            'pdf' => 'PDF',
            default => 'Word2007',
        };

        if ($format === 'pdf') {
            $this->configurePdfRenderer();
        }

        $tmp = tempnam(sys_get_temp_dir(), 'shillinq-report-');
        if ($tmp === false) {
            $tmp = sys_get_temp_dir().'/shillinq-report-'.bin2hex(random_bytes(8));
        }

        try {
            $writer = IOFactory::createWriter($phpWord, $writerName);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            if (is_file($tmp) === true) {
                @unlink($tmp);
            }
        }

        return $bytes;

    }//end render()

    /**
     * Point PhpWord's PDF writer at dompdf. The renderer NAME selects the dompdf
     * writer; the renderer PATH is set to the dompdf library directory bundled in
     * OpenRegister (resolved at runtime from the autoloaded \Dompdf\Dompdf class
     * file location, so it works wherever OpenRegister is installed). PhpWord
     * validates that the path exists, then loads the autoloaded Dompdf class.
     *
     * @return void
     */
    protected function configurePdfRenderer(): void
    {
        PhpWordSettings::setPdfRendererName(PhpWordSettings::PDF_RENDERER_DOMPDF);

        $rendererPath = $this->dompdfLibraryPath();
        if ($rendererPath !== null) {
            PhpWordSettings::setPdfRendererPath($rendererPath);
        }

        // dompdf needs a writable temp/font dir; default to the system temp dir.
        PhpWordSettings::setTempDir(sys_get_temp_dir());

    }//end configurePdfRenderer()

    /**
     * Resolve the directory of the bundled dompdf library from the autoloaded
     * \Dompdf\Dompdf class, so setPdfRendererPath() can validate a real path
     * without hard-coding OpenRegister's vendor location.
     *
     * @return string|null The dompdf library base dir, or null when unresolvable.
     */
    private function dompdfLibraryPath(): ?string
    {
        try {
            if (class_exists('\\Dompdf\\Dompdf') === false) {
                return null;
            }

            $reflection = new \ReflectionClass('\\Dompdf\\Dompdf');
            $file       = $reflection->getFileName();
            if ($file === false) {
                return null;
            }

            // .../dompdf/dompdf/src/Dompdf.php -> .../dompdf/dompdf .
            $dir = dirname($file, 2);
            if (is_dir($dir) === true && is_readable($dir) === true) {
                return $dir;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;

    }//end dompdfLibraryPath()

    // -----------------------------------------------------------------------
    // Shared data access (self-resolved — generators take no constructor args).
    // -----------------------------------------------------------------------

    /**
     * Load every object of a schema in the shillinq register as plain arrays,
     * optionally filtered. Fail-soft: returns an empty list when OpenRegister is
     * unavailable so a report still renders (with a "no data" placeholder).
     *
     * @param string               $schema  The schema slug (e.g. 'Account', 'GLLine').
     * @param array<string, mixed> $filters Optional OpenRegister filters.
     * @param int                  $limit   Max objects to load.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadObjects(string $schema, array $filters=[], int $limit=10000): array
    {
        try {
            $objectService = $this->objectService();
            if ($objectService === null) {
                return [];
            }

            $config = ['limit' => $limit];
            if ($filters !== []) {
                $config['filters'] = $filters;
            }

            $rows = $objectService
                ->setRegister($this->register())
                ->setSchema($schema)
                ->findAll($config);

            return $this->normaliseRows($rows);
        } catch (Throwable $e) {
            return [];
        }//end try

    }//end loadObjects()

    /**
     * Load a single object of a schema by id, as a plain array (null on miss).
     *
     * @param string $schema The schema slug.
     * @param string $id     The object id / uuid.
     *
     * @return array<string, mixed>|null
     */
    protected function loadObject(string $schema, string $id): ?array
    {
        if (trim($id) === '') {
            return null;
        }

        try {
            $objectService = $this->objectService();
            if ($objectService === null) {
                return null;
            }

            $object = $objectService
                ->setRegister($this->register())
                ->setSchema($schema)
                ->find($id);

            if ($object === null) {
                return null;
            }

            if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
                return (array) $object->jsonSerialize();
            }

            return (array) $object;
        } catch (Throwable $e) {
            return null;
        }//end try

    }//end loadObject()

    /**
     * Normalise ObjectService rows (entities or arrays) to plain arrays.
     *
     * @param mixed $rows Raw rows from findAll().
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normaliseRows(mixed $rows): array
    {
        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            if (is_array($row) === true) {
                $out[] = $row;
                continue;
            }

            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $out[] = (array) $row->jsonSerialize();
            }
        }

        return $out;

    }//end normaliseRows()

    /**
     * Lazily resolve OpenRegister's ObjectService from the server container.
     *
     * @return object|null The ObjectService, or null when unavailable.
     */
    protected function objectService(): ?object
    {
        try {
            return \OCP\Server::get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }

    }//end objectService()

    /**
     * The shillinq register slug (from app config, default 'shillinq').
     *
     * @return string
     */
    protected function register(): string
    {
        try {
            $appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
            $register  = $appConfig->getValueString('shillinq', 'register', 'shillinq');
            return $register === '' ? 'shillinq' : $register;
        } catch (Throwable $e) {
            return 'shillinq';
        }

    }//end register()

    // -----------------------------------------------------------------------
    // Formatting helpers.
    // -----------------------------------------------------------------------

    /**
     * Read a numeric field from a row, tolerating string numerics and nulls.
     *
     * @param array<string, mixed> $row     The object row.
     * @param string               ...$keys Candidate keys, in priority order.
     *
     * @return float
     */
    protected function num(array $row, string ...$keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) === true && is_numeric($row[$key]) === true) {
                return (float) $row[$key];
            }
        }

        return 0.0;

    }//end num()

    /**
     * Read a string field from a row, tolerating nested @self ids.
     *
     * @param array<string, mixed> $row     The object row.
     * @param string               ...$keys Candidate keys, in priority order.
     *
     * @return string
     */
    protected function str(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) === true && is_scalar($row[$key]) === true) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';

    }//end str()

    /**
     * Format a monetary value with a thousands separator and the currency code.
     *
     * @param float|int|string|null $amount   The raw amount.
     * @param string                $currency ISO-4217 currency code.
     *
     * @return string
     */
    protected function money(float|int|string|null $amount, string $currency='EUR'): string
    {
        $value = is_numeric($amount) === true ? (float) $amount : 0.0;
        $code  = trim($currency) !== '' ? strtoupper($currency) : 'EUR';
        return $code.' '.number_format($value, 2, ',', '.');

    }//end money()

    /**
     * Resolve the catalogue label for this generator's report type.
     *
     * @return string
     */
    protected function catalogueLabel(): string
    {
        $entry = ReportCatalogue::byId(static::reportType());
        return (string) ($entry['label'] ?? $this->documentTitle());

    }//end catalogueLabel()

    /**
     * Build a safe, descriptive file name for the rendered document.
     *
     * @param array<string, mixed> $context The generation context.
     * @param string               $format  The output format (extension).
     *
     * @return string
     */
    protected function fileName(array $context, string $format): string
    {
        $parts  = [static::reportType()];
        $period = $this->str($context, 'period');
        if ($period !== '') {
            $parts[] = $period;
        }

        $administration = $this->str($context, 'administrationId');
        if ($administration !== '') {
            $parts[] = $administration;
        }

        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts));
        $stem = trim((string) $stem, '-');
        if ($stem === '') {
            $stem = static::reportType();
        }

        return $stem.'.'.$format;

    }//end fileName()
}//end class
