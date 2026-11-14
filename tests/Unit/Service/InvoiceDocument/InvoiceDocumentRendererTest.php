<?php

/**
 * Unit tests for rendering the sales-invoice template through the document app (REQ-SID-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\InvoiceDocument;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentRenderer;
use OCA\Shillinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The renderer over a container holding fake document-app services.
 */
#[CoversClass(InvoiceDocumentRenderer::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(DocudeskTemplateSelection::class)]
final class InvoiceDocumentRendererTest extends TestCase {
	/**
	 * What the fake document service was asked.
	 *
	 * @var array<int,mixed>
	 */
	public array $calls = [];

	/**
	 * A renderer over the given services.
	 *
	 * @param bool        $installed  Whether the app is installed.
	 * @param string      $content    What generateDocument returns as content.
	 * @param array       $templates  The shillinq templates.
	 * @param string      $configured The admin-set template id.
	 *
	 * @return InvoiceDocumentRenderer
	 */
	private function renderer(bool $installed, string $content = '%PDF-1.7', array $templates = [], string $configured = ''): InvoiceDocumentRenderer {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'filinq');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'documents_template_sales-invoice' ? $configured : ''
		);
		$test      = $this;
		$documents = new class($test, $content) {
			public function __construct(private InvoiceDocumentRendererTest $test, private string $content) {
			}

			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->test->calls[] = [$templateId, $dataRefs, $options];
				return ['content' => $this->content];
			}
		};
		$templateService = new class($templates) {
			public function __construct(private array $templates) {
			}

			public function getTemplatesByNamespace(string $namespace): array {
				return $namespace === 'shillinq' ? $this->templates : [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($apps, $config, $documents, $templateService): object {
				return match ($id) {
					IAppManager::class => $apps,
					IAppConfig::class => $config,
					'OCA\\Filinq\\Service\\DocumentService' => $documents,
					'OCA\\Filinq\\Service\\TemplateService' => $templateService,
					default => throw new RuntimeException('no '.$id),
				};
			}
		);

		return new InvoiceDocumentRenderer($container);

	}//end renderer()

	/**
	 * With docudesk present the PDF it renders is returned, from the one
	 * sales-invoice template, with the data as ad-hoc data.
	 *
	 * @return void
	 */
	public function testWithDocudeskThePdfIsReturned(): void {
		$templates = [['id' => 't-report', 'category' => 'shillinq-balans'], ['id' => 't-inv', 'category' => 'sales-invoice']];

		$pdf = $this->renderer(true, '%PDF-1.7 body', $templates)->renderPdf(['invoice' => ['number' => '2026-0042']], 'alice');

		self::assertStringStartsWith('%PDF', $pdf);
		self::assertSame(
			[['t-inv', [], ['format' => 'pdf', 'userId' => 'alice', 'adHocData' => ['invoice' => ['number' => '2026-0042']]]]],
			$this->calls
		);

	}//end testWithDocudeskThePdfIsReturned()

	/**
	 * An admin-set template id wins over discovery; no user is "system".
	 *
	 * @return void
	 */
	public function testTheConfiguredTemplateWins(): void {
		$this->renderer(true, '%PDF', [['id' => 't-inv', 'category' => 'sales-invoice']], 'uuid-9')->renderPdf([], '');

		self::assertSame('uuid-9', $this->calls[0][0]);
		self::assertSame('system', $this->calls[0][2]['userId']);

	}//end testTheConfiguredTemplateWins()

	/**
	 * Without docudesk the absence is its own exception and nothing renders.
	 *
	 * @return void
	 */
	public function testWithoutDocudeskTheAbsenceIsReported(): void {
		$renderer = $this->renderer(false);

		try {
			$renderer->renderPdf([]);
			self::fail('expected the absence');
		} catch (DocudeskUnavailableException $e) {
			self::assertSame('Docudesk is needed to produce invoice documents.', $e->getMessage());
		}

		$this->expectException(DocudeskUnavailableException::class);
		$renderer->assertAvailable();

	}//end testWithoutDocudeskTheAbsenceIsReported()

	/**
	 * No template, two templates, or an answer that is not a PDF are refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$cases = [
			'none' => $this->renderer(true, '%PDF', []),
			'two' => $this->renderer(true, '%PDF', [['id' => 'a', 'category' => 'sales-invoice'], ['id' => 'b', 'category' => 'sales-invoice']]),
			'html' => $this->renderer(true, '<html>', [['id' => 'a', 'category' => 'sales-invoice']]),
			'postscript' => $this->renderer(true, '%!PS-Adobe-3.0', [['id' => 'a', 'category' => 'sales-invoice']]),
		];
		$outcomes = [];
		foreach ($cases as $name => $renderer) {
			try {
				$renderer->renderPdf([]);
				$outcomes[$name] = 'rendered';
			} catch (DocudeskUnavailableException $e) {
				$outcomes[$name] = 'absent';
			} catch (RuntimeException $e) {
				$outcomes[$name] = 'refused';
			}
		}

		self::assertSame(['none' => 'refused', 'two' => 'refused', 'html' => 'refused', 'postscript' => 'refused'], $outcomes);
		self::assertCount(2, $this->calls);

	}//end testRefusals()
}//end class
