<?php

/**
 * Invoice Document Renderer
 *
 * Renders the sales invoice PDF through docudesk (sales-invoice-document
 * design D3, REQ-SID-002): the assembled template data of
 * {@see InvoiceDocumentData} goes to the document app's
 * `DocumentService::generateDocument()` with the `sales-invoice` template,
 * and the PDF it renders comes back. Shillinq emits no PDF bytes itself here
 * (ADR-075).
 *
 * The document app is resolved by string class name through the container
 * and {@see FleetAppId}, so shillinq neither imports nor depends on it. When
 * it is absent, or its services do not resolve, rendering refuses with a
 * {@see DocudeskUnavailableException} before anything is sent, so the caller
 * can say that docudesk is needed instead of serving something else.
 *
 * The template is the one an admin configured
 * (`documents_template_sales-invoice`), else the single docudesk template in
 * namespace `shillinq` whose category is `sales-invoice`; none or several
 * refuse, as for the report documents (REQ-RVD-004).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Kernel\Contracts\InvoicePdfGeneratorInterface;
use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection;
use OCA\Shillinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * The docudesk hand-off of the sales invoice document.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
 */
class InvoiceDocumentRenderer {

	/**
	 * The template id in `docudesk-templates.json` and its docudesk category.
	 */
	public const TEMPLATE_ID = 'sales-invoice';

	/**
	 * What the caller shows when docudesk is absent (REQ-SID-002).
	 */
	public const UNAVAILABLE_MESSAGE = InvoicePdfGeneratorInterface::DOCUDESK_NEEDED;

	/**
	 * The canonical fleet name of the document app.
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * The docudesk template namespace of shillinq's templates.
	 */
	private const TEMPLATE_NAMESPACE = 'shillinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Resolves the document app's services by name.
	 * @param IAppManager        $appManager Whether the document app is installed.
	 * @param IAppConfig         $appConfig  An admin-configured template id.
	 * @param string             $templateFile The template registry holding the variants.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly string $templateFile = __DIR__.'/../../../Settings/docudesk-templates.json',
	) {
	}//end __construct()

	/**
	 * Render the invoice document as a PDF.
	 *
	 * @param array<string, mixed> $data   The template data of {@see InvoiceDocumentData::assemble()}.
	 * @param string               $userId The acting user, or `system`.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws DocudeskUnavailableException When docudesk is not installed or cannot be resolved.
	 * @throws RuntimeException             When no single template is found or docudesk renders no PDF.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	public function render(array $data, string $userId): string {
		if ($this->available() === false) {
			throw new DocudeskUnavailableException(message: self::UNAVAILABLE_MESSAGE);
		}

		$documents = $this->service(relative: 'Service\\DocumentService');
		$templates = $this->service(relative: 'Service\\TemplateService');
		if ($documents === null || $templates === null) {
			throw new DocudeskUnavailableException(message: self::UNAVAILABLE_MESSAGE);
		}

		$templateId = $this->templateId(templates: $templates);
		if ($userId === '') {
			$userId = 'system';
		}

		try {
			$rendered = $documents->generateDocument(
				$templateId,
				[],
				[
					'format' => 'pdf',
					'userId' => $userId,
					'adHocData' => [
						'invoiceDocument' => $data,
						'variant' => $this->variant(name: (string)($data['layout']['variant'] ?? '')),
					],
				]
			);
		} catch (Throwable $e) {
			throw new RuntimeException('Docudesk could not render the invoice: '.$e->getMessage(), 0, $e);
		}

		$content = (string)($rendered['content'] ?? '');
		if (str_starts_with($content, '%PDF') === false) {
			throw new RuntimeException('Docudesk returned no PDF for the invoice.');
		}

		return $content;
	}//end render()

	/**
	 * The settings of a layout variant from the template registry; the
	 * default variant's for an unknown or empty name.
	 *
	 * @param string $name classic, modern or compact.
	 *
	 * @return array<string, string> The variant's settings, with its `name`.
	 *
	 * @throws RuntimeException When the registry has no sales-invoice template or no default variant.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.1
	 */
	public function variant(string $name): array {
		$registry = [];
		if (is_readable($this->templateFile) === true) {
			$registry = json_decode((string)file_get_contents($this->templateFile), true);
		}

		$template = [];
		foreach ((array)($registry['templates'] ?? []) as $candidate) {
			if (($candidate['templateId'] ?? null) === self::TEMPLATE_ID) {
				$template = (array)$candidate;
				break;
			}
		}

		$variants = (array)($template['variants'] ?? []);
		if (isset($variants[$name]) === false) {
			$name = (string)($template['defaultVariant'] ?? '');
		}

		if (isset($variants[$name]) === false) {
			throw new RuntimeException('The template registry has no sales-invoice variant "'.$name.'".');
		}

		return ['name' => $name] + array_map('strval', (array)$variants[$name]);
	}//end variant()

	/**
	 * Whether docudesk is installed and its document and template services resolve.
	 *
	 * @return bool True when an invoice document can be rendered.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the one pure resolver of the document app's names.
	 */
	public function available(): bool {
		return FleetAppId::isInstalled(appManager: $this->appManager, canonical: self::DOCUMENT_APP) === true
			&& $this->service(relative: 'Service\\DocumentService') !== null
			&& $this->service(relative: 'Service\\TemplateService') !== null;
	}//end available()

	/**
	 * A template row as an array, whether docudesk returns entities or arrays.
	 *
	 * @param mixed $row A row of getTemplatesByNamespace().
	 *
	 * @return mixed The row as an array, or the row unchanged.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	private static function plainRow(mixed $row): mixed {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return $row;
	}//end plainRow()

	/**
	 * The configured template id, else the one discovered in docudesk.
	 *
	 * @param object $templates The document app's TemplateService.
	 *
	 * @return string The template id.
	 *
	 * @throws RuntimeException When none or several templates match.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 */
	private function templateId(object $templates): string {
		$configured = trim($this->appConfig->getValueString('shillinq', 'documents_template_'.self::TEMPLATE_ID, ''));
		if ($configured !== '') {
			return $configured;
		}

		try {
			$found = $templates->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
		} catch (Throwable $e) {
			throw new RuntimeException('Could not look up docudesk templates: '.$e->getMessage(), 0, $e);
		}

		$rows = [];
		if (is_array($found) === true) {
			$rows = array_values(array_filter(array_map(self::plainRow(...), $found), 'is_array'));
		}

		$selection = new DocudeskTemplateSelection(reportType: self::TEMPLATE_ID, namespace: self::TEMPLATE_NAMESPACE);
		$selected = $selection->single(rows: $rows, category: self::TEMPLATE_ID);
		if ($selected['templateId'] === null) {
			throw new RuntimeException((string)$selected['error']);
		}

		return $selected['templateId'];
	}//end templateId()

	/**
	 * One of the document app's services, or null.
	 *
	 * @param string $relative The class name below the app root.
	 *
	 * @return object|null The service.
	 *
	 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-2.2
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the one pure resolver of the document app's names.
	 */
	private function service(string $relative): ?object {
		return FleetAppId::getService(container: $this->container, canonical: self::DOCUMENT_APP, relative: $relative);
	}//end service()
}//end class
