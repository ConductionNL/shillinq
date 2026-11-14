<?php

/**
 * Renders the sales-invoice template through the document app.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-is-rendered-through-docudesk-req-sid-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection;
use OCA\Shillinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * The same channel the document reports use: the document app's
 * DocumentService::generateDocument(templateId, [], options), resolved by
 * string class name through the container, with the template chosen by an
 * admin-set id or, failing that, the one shillinq template in category
 * `sales-invoice`. Absence of the document app is its own exception, so the
 * invoice page can say what is needed instead of serving a file.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-is-rendered-through-docudesk-req-sid-002
 */
class InvoiceDocumentRenderer {

	/**
	 * The template category, the templateId in docudesk-templates.json.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'sales-invoice';

	/**
	 * The fleet name of the document app.
	 *
	 * @var string
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * The template namespace shillinq's templates live under.
	 *
	 * @var string
	 */
	private const TEMPLATE_NAMESPACE = 'shillinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the app manager, app config and the document app's services.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Render the invoice data to PDF bytes.
	 *
	 * @param array<string,mixed> $data   The template data.
	 * @param string              $userId The acting user, 'system' when none.
	 *
	 * @return string The PDF.
	 *
	 * @throws DocudeskUnavailableException When the document app is absent or cannot be resolved.
	 * @throws RuntimeException When no template is chosen, rendering fails, or the answer is not a PDF.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function renderPdf(array $data, string $userId = 'system'): string {
		$documents = $this->documentService();
		$templates = $this->service(relative: 'Service\\TemplateService');

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
					'adHocData' => $data,
				]
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The invoice document could not be rendered: '.$e->getMessage(), 0, $e);
		}

		$content = (string)($rendered['content'] ?? '');
		if (str_starts_with($content, '%PDF') === false) {
			throw new RuntimeException('The document app returned no PDF for the invoice.');
		}

		return $content;

	}//end renderPdf()

	/**
	 * Throw when the document app is absent, before any record is read.
	 *
	 * @return void
	 *
	 * @throws DocudeskUnavailableException When the app is absent or its service cannot be resolved.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function assertAvailable(): void {
		$this->documentService();

	}//end assertAvailable()

	/**
	 * The document service, after checking the app is installed.
	 *
	 * @return object
	 *
	 * @throws DocudeskUnavailableException When the app is absent or its service cannot be resolved.
	 */
	private function documentService(): object {
		try {
			$installed = FleetAppId::isInstalled($this->container->get(IAppManager::class), self::DOCUMENT_APP);
		} catch (Throwable $e) {
			$installed = false;
		}

		if ($installed === false) {
			throw new DocudeskUnavailableException(message: 'Docudesk is needed to produce invoice documents.');
		}

		return $this->service(relative: 'Service\\DocumentService');

	}//end documentService()

	/**
	 * A document-app service under any namespace it has shipped under.
	 *
	 * @param string $relative Class name below the app root.
	 *
	 * @return object
	 *
	 * @throws DocudeskUnavailableException When no namespace resolves.
	 */
	private function service(string $relative): object {
		foreach (FleetAppId::classCandidates(self::DOCUMENT_APP, $relative) as $fqcn) {
			try {
				return $this->container->get($fqcn);
			} catch (Throwable $e) {
				continue;
			}
		}

		throw new DocudeskUnavailableException(message: 'Docudesk is needed to produce invoice documents.');

	}//end service()

	/**
	 * The template: the admin-set id, else the single match in the category.
	 *
	 * @param object $templates The template service.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When none or several templates match.
	 */
	private function templateId(object $templates): string {
		try {
			$configured = trim($this->container->get(IAppConfig::class)->getValueString('shillinq', 'documents_template_'.self::TEMPLATE, ''));
		} catch (Throwable $e) {
			$configured = '';
		}

		if ($configured !== '') {
			return $configured;
		}

		try {
			$rows = $templates->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
		} catch (Throwable $e) {
			throw new RuntimeException('Could not look up docudesk templates: '.$e->getMessage(), 0, $e);
		}

		$normalised = [];
		if (is_array($rows) === false) {
			$rows = [];
		}

		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$normalised[] = $row;
			}
		}

		$selection = new DocudeskTemplateSelection(reportType: self::TEMPLATE, namespace: self::TEMPLATE_NAMESPACE);
		$selected  = $selection->single(rows: $normalised, category: self::TEMPLATE);
		if ($selected['templateId'] === null) {
			throw new RuntimeException((string)$selected['error']);
		}

		return $selected['templateId'];

	}//end templateId()
}//end class
