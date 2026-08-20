<?php

/**
 * Abstract base for DOCUMENT report generators
 *
 * Shared machinery for the "document" flavour of the report catalogue — the
 * jaarrekening, balans, winst-en-verliesrekening, management letter and BBV
 * jaarstukken. A document generator loads and classifies real OpenRegister
 * objects into a `ReportSection` block tree (the same content a human report
 * carries — headings, key/value tables, amount tables, notes), then this
 * base hands that tree to docudesk for rendering:
 * `OCA\DocuDesk\Service\DocumentService::generateDocument($templateId, [],
 * $options)`, resolved by string FQCN through `\OCP\Server::get()` — no
 * compile-time `use` import of any `OCA\DocuDesk\*` class, no composer/
 * info.xml dependency on docudesk. This mirrors hrmq's `HrDocumentService`
 * (`hrmq-docudesk-documents` / `payslip-pdf-docudesk`): the leaf assembles
 * data and classification, docudesk renders (ADR-081 rule 6).
 *
 * Availability is duck-typed (docudeskAvailable()): when docudesk is not
 * installed, or its services cannot be resolved, generate() throws
 * DocudeskUnavailableException BEFORE any object loading, so
 * ReportGenerationService can record a visible `status: 'unavailable'`
 * outcome rather than a silent drop (ADR-081 rule 7).
 *
 * Template selection is config-first, discovery-second, and fails closed:
 * an admin-set `IAppConfig` UUID wins; otherwise exactly one docudesk
 * template with `namespace: "shillinq"` and `category` equal to the
 * ReportCatalogue entry's `templateId` is used — zero or multiple matches
 * refuse to render rather than guess between templates that produce
 * official financial/statutory documents (mirrors hrmq design.md D3).
 *
 * The concrete generators are instantiated with no constructor arguments by
 * ReportGenerationService (mirroring RuleEngine provider discovery), so this
 * base resolves OpenRegister's ObjectService, docudesk's services and the
 * register slug lazily from the Nextcloud server container rather than via
 * constructor injection.
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
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-001
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-003
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-004
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Commenting.InlineComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Reporting\ReportSection;
use RuntimeException;
use Throwable;

/**
 * Base class carrying the default block vocabulary + the docudesk hand-off.
 */
abstract class AbstractDocumentReportGenerator implements ReportGeneratorInterface {

	/**
	 * Public-facing formats, editable first — the order ReportCatalogue offers.
	 * `docx` is no longer offered: docudesk's DocumentService only supports
	 * `pdf` / `odf` / `html` (see DOCUDESK_FORMATS for the mapping).
	 */
	private const FORMATS = ['odt', 'pdf'];

	/**
	 * Maps a public-facing format label to the format key docudesk's
	 * `DocumentService::generateDocument()` `options.format` expects.
	 */
	private const DOCUDESK_FORMATS = [
		'odt' => 'odf',
		'pdf' => 'pdf',
	];

	/**
	 * Per-format MIME types for the rendered file.
	 */
	private const MIME_TYPES = [
		'odt' => 'application/vnd.oasis.opendocument.text',
		'pdf' => 'application/pdf',
	];

	/**
	 * The app id docudesk registers under (IAppManager::isInstalled probe).
	 */
	private const DOCUDESK_APP_ID = 'docudesk';

	/**
	 * docudesk's rendering service, resolved by string FQCN only (no
	 * compile-time import).
	 */
	private const DOCUMENT_SERVICE_FQCN = 'OCA\\DocuDesk\\Service\\DocumentService';

	/**
	 * docudesk's template lookup service, resolved by string FQCN only.
	 */
	private const TEMPLATE_SERVICE_FQCN = 'OCA\\DocuDesk\\Service\\TemplateService';

	/**
	 * The docudesk template namespace shillinq's own templates live under.
	 */
	private const TEMPLATE_NAMESPACE = 'shillinq';

	/**
	 * The default theme colour (Conduction cobalt) for headings/accents — a
	 * style hint carried into `ReportTable` cell styles (e.g. header row
	 * `bgColor`); a docudesk template may use it or apply its own huisstijl.
	 */
	protected const ACCENT_COLOUR = '1A2A6C';

	/**
	 * Muted colour for sub-labels and footers — same style-hint role as
	 * ACCENT_COLOUR.
	 */
	protected const MUTED_COLOUR = '6B7280';

	/**
	 * The formats every document generator can emit, editable first.
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return self::FORMATS;
	}//end supportedFormats()

	/**
	 * Render the report: the subclass builds the ReportSection block tree
	 * from real objects, this base hands it to docudesk for rendering.
	 *
	 * Order matters: availability is checked BEFORE any object loading
	 * (`build()` runs after the docudesk/template checks) so a docudesk
	 * outage never costs an unnecessary OpenRegister scan and always fails
	 * fast with a visible, distinguishable outcome (REQ-RVD-005).
	 *
	 * @param array<string, mixed> $context `{ reportType, period, administrationId, ... }`.
	 * @param string $format One of supportedFormats().
	 *
	 * @return GeneratedFile
	 *
	 * @throws DocudeskUnavailableException When docudesk is not installed or unreachable.
	 * @throws RuntimeException When template selection or docudesk rendering fails.
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$useFormat = in_array($format, self::FORMATS, true) === true ? $format : self::FORMATS[0];

		if ($this->docudeskAvailable() === false) {
			throw new DocudeskUnavailableException(sprintf(
				'Docudesk is not installed or its renderer could not be resolved; "%s" cannot be rendered.',
				static::reportType()
			));
		}

		$selected = $this->selectTemplate();
		if ($selected['templateId'] === null) {
			throw new RuntimeException((string)$selected['error']);
		}

		$section = new ReportSection();
		$this->build($section, $context);

		$reportBody = [
			'title' => $this->documentTitle(),
			'subtitle' => $this->catalogueLabel(),
			'blocks' => $section->toArray(),
		];

		$options = [
			'format' => self::DOCUDESK_FORMATS[$useFormat],
			'userId' => $this->str($context, 'userId') !== '' ? $this->str($context, 'userId') : 'system',
			'adHocData' => [
				'report' => $reportBody,
				'meta' => [
					'reportType' => static::reportType(),
					'period' => $this->str($context, 'period'),
					'administrationId' => $this->str($context, 'administrationId'),
					'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				],
			],
		];

		try {
			$rendered = $this->documentService()->generateDocument($selected['templateId'], [], $options);
		} catch (Throwable $e) {
			throw new RuntimeException('Docudesk rendering failed: ' . $e->getMessage(), 0, $e);
		}

		$content = (string)($rendered['content'] ?? '');
		if ($content === '') {
			throw new RuntimeException('Docudesk returned no document content for "' . static::reportType() . '".');
		}

		return new GeneratedFile(
			fileName: $this->fileName($context, $useFormat),
			mimeType: self::MIME_TYPES[$useFormat],
			format: $useFormat,
			content: $content,
		);

	}//end generate()

	/**
	 * Build the report body into the prepared ReportSection. The subclass
	 * loads its real objects and lays out the sections.
	 *
	 * @param ReportSection $section The block accumulator to fill.
	 * @param array<string, mixed> $context `{ reportType, period, administrationId }`.
	 *
	 * @return void
	 */
	abstract protected function build(ReportSection $section, array $context): void;

	/**
	 * A human title for the report's cover block (e.g. 'Jaarrekening').
	 *
	 * @return string
	 */
	abstract protected function documentTitle(): string;

	// -----------------------------------------------------------------------
	// Default block-vocabulary helpers (mirror the former PhpWord helpers).
	// -----------------------------------------------------------------------

	/**
	 * Add a styled cover block: a big title, an optional subtitle, and the
	 * period/administration meta line.
	 *
	 * @param ReportSection $section The section to write into.
	 * @param string $title The cover title.
	 * @param string $subtitle Subtitle / report type label.
	 * @param array<string, mixed> $context Generation context for the meta line.
	 *
	 * @return void
	 */
	protected function addCover(ReportSection $section, string $title, string $subtitle, array $context): void {
		$period = $this->str($context, 'period');
		$administrationId = $this->str($context, 'administrationId');

		$meta = [];
		if ($period !== '') {
			$meta[] = 'Periode: ' . $period;
		}

		if ($administrationId !== '') {
			$meta[] = 'Administratie: ' . $administrationId;
		}

		$meta[] = 'Gegenereerd: ' . gmdate('Y-m-d H:i') . ' UTC';

		$section->addCover($title, $subtitle, $meta);

	}//end addCover()

	/**
	 * Add a level-2 section heading.
	 *
	 * @param ReportSection $section The section to write into.
	 * @param string $heading The heading text.
	 *
	 * @return void
	 */
	protected function addHeading(ReportSection $section, string $heading): void {
		$section->addTitle($heading, 2);

	}//end addHeading()

	/**
	 * Add a key/value details table (two columns) from an associative array.
	 * Empty values are skipped so the default layout stays clean on sparse data.
	 *
	 * @param ReportSection $section The section to write into.
	 * @param array<string, string> $rows Label => value pairs (insertion order kept).
	 *
	 * @return void
	 */
	protected function addDetailsTable(ReportSection $section, array $rows): void {
		$rows = array_filter($rows, static fn ($value): bool => trim((string)$value) !== '');
		if ($rows === []) {
			$section->addNote('Geen gegevens beschikbaar.');
			return;
		}

		$section->addDetailsTable($rows);

	}//end addDetailsTable()

	/**
	 * Add a financial amount table: a header row, then label/amount lines, then
	 * an optional bold total row.
	 *
	 * @param ReportSection $section The section to write into.
	 * @param string $labelHead Header for the label column.
	 * @param string $amountHead Header for the amount column.
	 * @param array<int, array{label: string, amount: float|int|null, bold?: bool}> $lines The body lines.
	 * @param array{label: string, amount: float|int}|null $total Optional total row.
	 * @param string $currency ISO-4217 currency for formatting.
	 *
	 * @return void
	 */
	protected function addAmountTable(
		ReportSection $section,
		string $labelHead,
		string $amountHead,
		array $lines,
		?array $total = null,
		string $currency = 'EUR',
	): void {
		if ($lines === [] && $total === null) {
			$section->addNote('Geen posten in deze periode.');
			return;
		}

		$section->addAmountTable($labelHead, $amountHead, $lines, $total, $currency);

	}//end addAmountTable()

	/**
	 * Add a paragraph of body text (toelichting / notes).
	 *
	 * @param ReportSection $section The section to write into.
	 * @param string $text The paragraph text.
	 *
	 * @return void
	 */
	protected function addParagraph(ReportSection $section, string $text): void {
		$section->addText($text, 'value', 'default');

	}//end addParagraph()

	/**
	 * Add a small grey footnote / note line.
	 *
	 * @param ReportSection $section The section to write into.
	 * @param string $text The note text.
	 *
	 * @return void
	 */
	protected function addNote(ReportSection $section, string $text): void {
		$section->addNote($text);

	}//end addNote()

	// -----------------------------------------------------------------------
	// Docudesk resolution + template selection.
	// -----------------------------------------------------------------------

	/**
	 * Duck-typed docudesk availability probe: docudesk must be installed AND
	 * both service FQCNs must resolve from the container. Mirrors hrmq's
	 * HrDocumentService::docudeskAvailable() (design.md D5).
	 *
	 * @return bool
	 */
	protected function docudeskAvailable(): bool {
		try {
			$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
			if ($appManager->isInstalled(self::DOCUDESK_APP_ID) === false) {
				return false;
			}

			$this->documentService();
			$this->templateService();
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}//end docudeskAvailable()

	/**
	 * docudesk's DocumentService, resolved by string FQCN only.
	 *
	 * @return object
	 */
	protected function documentService(): object {
		return \OCP\Server::get(self::DOCUMENT_SERVICE_FQCN);
	}//end documentService()

	/**
	 * docudesk's TemplateService, resolved by string FQCN only.
	 *
	 * @return object
	 */
	protected function templateService(): object {
		return \OCP\Server::get(self::TEMPLATE_SERVICE_FQCN);
	}//end templateService()

	/**
	 * Template selection: config-UUID first, then namespace/category
	 * discovery against the ReportCatalogue entry's `templateId`, fail
	 * closed on zero/multiple matches (REQ-RVD-004).
	 *
	 * @return array{templateId: string|null, error: string|null}
	 */
	protected function selectTemplate(): array {
		$reportType = static::reportType();
		$catalogue = ReportCatalogue::byId($reportType);
		$category = (string)($catalogue['templateId'] ?? $reportType);

		$configured = trim($this->configuredTemplateId($reportType));
		if ($configured !== '') {
			return ['templateId' => $configured, 'error' => null];
		}

		try {
			$templates = $this->templateService()->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
		} catch (Throwable $e) {
			return ['templateId' => null, 'error' => 'Could not look up docudesk templates: ' . $e->getMessage()];
		}

		$matches = [];
		foreach ($this->normaliseRows($templates) as $template) {
			if ((string)($template['category'] ?? '') === $category) {
				$matches[] = $template;
			}
		}

		if (count($matches) === 0) {
			return [
				'templateId' => null,
				'error' => sprintf(
					'No docudesk template found for "%s" in namespace "%s" (category "%s"); generation is refused.',
					$reportType,
					self::TEMPLATE_NAMESPACE,
					$category
				),
			];
		}

		if (count($matches) > 1) {
			$ids = array_map(
				static fn (array $t): string => (string)($t['id'] ?? ($t['@self']['id'] ?? '?')),
				$matches
			);

			return [
				'templateId' => null,
				'error' => sprintf(
					'Multiple docudesk templates (%s) for "%s" in namespace "%s"; generation is refused '
					. '(never guess between templates that produce official documents).',
					implode(', ', $ids),
					$reportType,
					self::TEMPLATE_NAMESPACE
				),
			];
		}

		$id = (string)($matches[0]['id'] ?? $matches[0]['@self']['id'] ?? '');
		if ($id === '') {
			return ['templateId' => null, 'error' => 'The matched docudesk template has no id.'];
		}

		return ['templateId' => $id, 'error' => null];
	}//end selectTemplate()

	/**
	 * An admin-configured docudesk template UUID override for this report type.
	 *
	 * @param string $reportType The ReportCatalogue report-type id.
	 *
	 * @return string The configured UUID, or '' when unset/unavailable.
	 */
	protected function configuredTemplateId(string $reportType): string {
		try {
			$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
			return $appConfig->getValueString('shillinq', 'documents_template_' . $reportType, '');
		} catch (Throwable $e) {
			return '';
		}

	}//end configuredTemplateId()

	// -----------------------------------------------------------------------
	// Shared data access (self-resolved — generators take no constructor args).
	// -----------------------------------------------------------------------

	/**
	 * Load every object of a schema in the shillinq register as plain arrays,
	 * optionally filtered. Fail-soft: returns an empty list when OpenRegister is
	 * unavailable so a report still renders (with a "no data" placeholder).
	 *
	 * @param string $schema The schema slug (e.g. 'Account', 'GLLine').
	 * @param array<string, mixed> $filters Optional OpenRegister filters.
	 * @param int $limit Max objects to load.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function loadObjects(string $schema, array $filters = [], int $limit = 10000): array {
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
	 * @param string $id The object id / uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function loadObject(string $schema, string $id): ?array {
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
				return (array)$object->jsonSerialize();
			}

			return (array)$object;
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
	protected function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Lazily resolve OpenRegister's ObjectService from the server container.
	 *
	 * @return object|null The ObjectService, or null when unavailable.
	 */
	protected function objectService(): ?object {
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
	protected function register(): string {
		try {
			$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
			$register = $appConfig->getValueString('shillinq', 'register', 'shillinq');
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
	 * @param array<string, mixed> $row The object row.
	 * @param string ...$keys Candidate keys, in priority order.
	 *
	 * @return float
	 */
	protected function num(array $row, string ...$keys): float {
		foreach ($keys as $key) {
			if (array_key_exists($key, $row) === true && is_numeric($row[$key]) === true) {
				return (float)$row[$key];
			}
		}

		return 0.0;
	}//end num()

	/**
	 * Read a string field from a row, tolerating nested @self ids.
	 *
	 * @param array<string, mixed> $row The object row.
	 * @param string ...$keys Candidate keys, in priority order.
	 *
	 * @return string
	 */
	protected function str(array $row, string ...$keys): string {
		foreach ($keys as $key) {
			if (array_key_exists($key, $row) === true && is_scalar($row[$key]) === true) {
				$value = trim((string)$row[$key]);
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
	 * @param float|int|string|null $amount The raw amount.
	 * @param string $currency ISO-4217 currency code.
	 *
	 * @return string
	 */
	protected function money(float|int|string|null $amount, string $currency = 'EUR'): string {
		$value = is_numeric($amount) === true ? (float)$amount : 0.0;
		$code = trim($currency) !== '' ? strtoupper($currency) : 'EUR';
		return $code . ' ' . number_format($value, 2, ',', '.');
	}//end money()

	/**
	 * Resolve the catalogue label for this generator's report type.
	 *
	 * @return string
	 */
	protected function catalogueLabel(): string {
		$entry = ReportCatalogue::byId(static::reportType());
		return (string)($entry['label'] ?? $this->documentTitle());
	}//end catalogueLabel()

	/**
	 * Build a safe, descriptive file name for the rendered document.
	 *
	 * @param array<string, mixed> $context The generation context.
	 * @param string $format The output format (extension).
	 *
	 * @return string
	 */
	protected function fileName(array $context, string $format): string {
		$parts = [static::reportType()];
		$period = $this->str($context, 'period');
		if ($period !== '') {
			$parts[] = $period;
		}

		$administration = $this->str($context, 'administrationId');
		if ($administration !== '') {
			$parts[] = $administration;
		}

		$stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts));
		$stem = trim((string)$stem, '-');
		if ($stem === '') {
			$stem = static::reportType();
		}

		return $stem . '.' . $format;
	}//end fileName()
}//end class
