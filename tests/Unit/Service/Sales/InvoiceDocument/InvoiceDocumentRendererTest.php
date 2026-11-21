<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Payment\PayLinkService;
use OCA\Shillinq\Service\Sales\InvoiceDocument\EpcQrPayload;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentData;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentLanguage;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentRenderer;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The invoice PDF is rendered through docudesk, and refused without it
 * (REQ-SID-002).
 *
 * @covers \OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentRenderer
 * @covers \OCA\Shillinq\Service\InvoicePdfGenerator
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentData
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentLanguage
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceDocument\EpcQrPayload
 * @uses   \OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection
 * @uses   \OCA\Shillinq\Support\FleetAppId
 */
final class InvoiceDocumentRendererTest extends TestCase {

	/**
	 * What the fake DocumentService was asked, per call.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A renderer over a container that does or does not hold docudesk.
	 *
	 * @param bool                             $installed  Whether the document app is installed.
	 * @param string                           $content    What docudesk renders.
	 * @param array<int, array<string, mixed>> $templates  The templates in namespace shillinq.
	 * @param string                           $configured An admin-configured template id.
	 *
	 * @return InvoiceDocumentRenderer
	 */
	private function renderer(
		bool $installed = true,
		string $content = "%PDF-1.7\n1 0 obj",
		array $templates = [],
		string $configured = ''
	): InvoiceDocumentRenderer {
		$calls = &$this->calls;
		$documents = new class($content, $calls) {
			/**
			 * @param string                                                   $content The rendered content.
			 * @param array<int, array{0: string, 1: array<string, mixed>}> $calls   The calls so far.
			 */
			public function __construct(private string $content, private array &$calls) {
			}

			/**
			 * @param string               $templateId The template.
			 * @param array<int, mixed>    $refs       The data refs.
			 * @param array<string, mixed> $options    The options.
			 *
			 * @return array<string, string>
			 */
			public function generateDocument(string $templateId, array $refs, array $options): array {
				$this->calls[] = [$templateId, $options];
				return ['content' => $this->content];
			}
		};
		$templateService = new class($templates) {
			/**
			 * @param array<int, array<string, mixed>> $templates The templates.
			 */
			public function __construct(private array $templates) {
			}

			/**
			 * @param string $namespace The namespace.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getTemplatesByNamespace(string $namespace): array {
				if ($namespace !== 'shillinq') {
					return [];
				}

				return $this->templates;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($installed, $documents, $templateService): object {
				if ($installed === true && str_ends_with($id, '\\Service\\DocumentService') === true) {
					return $documents;
				}

				if ($installed === true && str_ends_with($id, '\\Service\\TemplateService') === true) {
					return $templateService;
				}

				throw new RuntimeException('not registered: '.$id);
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'docudesk');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($configured): string {
				if ($key === 'documents_template_sales-invoice') {
					return $configured;
				}

				return $default;
			}
		);

		return new InvoiceDocumentRenderer($container, $apps, $config);
	}

	/**
	 * The one sales-invoice template in docudesk.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function oneTemplate(): array {
		return [
			['id' => 'tpl-invoice', 'namespace' => 'shillinq', 'category' => 'sales-invoice'],
			['id' => 'tpl-balans', 'namespace' => 'shillinq', 'category' => 'shillinq-balans'],
		];
	}

	/**
	 * A generator wired to the renderer.
	 *
	 * @param InvoiceDocumentRenderer $renderer The renderer.
	 *
	 * @return InvoicePdfGenerator
	 */
	private function generator(InvoiceDocumentRenderer $renderer): InvoicePdfGenerator {
		$payLinks = $this->createMock(PayLinkService::class);
		$payLinks->method('forOpenInvoice')->willReturn(null);
		$data = new InvoiceDocumentData(new InvoiceDocumentLanguage(InvoiceDocumentLanguageTest::factory($this)), new EpcQrPayload(), $payLinks);

		return new InvoicePdfGenerator($payLinks, $data, $renderer);
	}

	/**
	 * With docudesk present the served file is the PDF it renders from the
	 * sales-invoice template, with the assembled data in the document language.
	 *
	 * @return void
	 */
	public function testWithDocudeskTheServedFileIsAPdf(): void {
		$generator = $this->generator($this->renderer(templates: self::oneTemplate()));

		self::assertTrue($generator->documentsAvailable());
		$result = $generator->generatePdf(
			invoice: ['invoiceNumber' => '2026-0042', 'currency' => 'GBP', 'grossAmount' => 4800.0, 'documentLanguage' => 'en'],
			lines: [['description' => 'Advisory', 'quantity' => 1, 'unitPrice' => 4800.0]],
			context: [
				'creditor' => ['legalName' => 'Adviesbureau Van Dijk'],
				'recipient' => ['legalName' => 'Brightside Consulting Ltd', 'language' => 'en'],
				'userId' => 'bookkeeper',
				'layout' => ['variant' => 'modern'],
			]
		);

		self::assertSame(['filename', 'pdf', 'mimeType'], array_keys($result));
		self::assertStringStartsWith('%PDF', $result['pdf']);
		self::assertSame(['invoice-2026-0042.pdf', 'application/pdf'], [$result['filename'], $result['mimeType']]);
		self::assertCount(1, $this->calls);
		[$templateId, $options] = $this->calls[0];
		self::assertSame(['tpl-invoice', 'pdf', 'bookkeeper'], [$templateId, $options['format'], $options['userId']]);
		$data = $options['adHocData']['invoiceDocument'];
		self::assertSame(['en', 'GBP', 'modern'], [$data['language'], $data['currency'], $data['layout']['variant']]);
		self::assertSame('2026-0042', $data['invoice']['number']);
		self::assertSame(['modern', 'variant-modern'], [$options['adHocData']['variant']['name'], $options['adHocData']['variant']['className']]);
	}

	/**
	 * Without docudesk nothing is rendered and the refusal says docudesk is needed.
	 *
	 * @return void
	 */
	public function testWithoutDocudeskTheRefusalSaysItIsNeeded(): void {
		$generator = $this->generator($this->renderer(installed: false, templates: self::oneTemplate()));
		self::assertFalse($generator->documentsAvailable());

		$this->expectException(DocudeskUnavailableException::class);
		$this->expectExceptionMessage('Docudesk is needed to produce invoice documents.');
		try {
			$generator->generatePdf(invoice: ['invoiceNumber' => '2026-0042'], lines: []);
		} finally {
			self::assertSame([], $this->calls);
		}
	}

	/**
	 * A generator built without the docudesk hand-off refuses the same way.
	 *
	 * @return void
	 */
	public function testAGeneratorWithoutTheRendererRefuses(): void {
		self::assertFalse((new InvoicePdfGenerator())->documentsAvailable());
		$this->expectException(DocudeskUnavailableException::class);
		(new InvoicePdfGenerator())->generatePdf(invoice: ['invoiceNumber' => '2026-0042'], lines: []);
	}

	/**
	 * An admin-configured template wins over discovery.
	 *
	 * @return void
	 */
	public function testAConfiguredTemplateWins(): void {
		$this->renderer(templates: self::oneTemplate(), configured: 'tpl-house-style')->render(data: [], userId: '');

		self::assertSame(['tpl-house-style', 'system'], [$this->calls[0][0], $this->calls[0][1]['userId']]);
	}

	/**
	 * No sales-invoice template, or docudesk rendering no PDF, is an error,
	 * never a file.
	 *
	 * @return void
	 */
	public function testNoTemplateOrNoPdfIsAnError(): void {
		$errors = [];
		foreach ([$this->renderer(templates: []), $this->renderer(content: '<html></html>', templates: self::oneTemplate())] as $renderer) {
			try {
				$renderer->render(data: [], userId: 'u');
				$errors[] = null;
			} catch (DocudeskUnavailableException $e) {
				$errors[] = 'unavailable';
			} catch (RuntimeException $e) {
				$errors[] = 'error';
			}
		}

		self::assertSame(['error', 'error'], $errors);
		self::assertCount(1, $this->calls);
	}
}
