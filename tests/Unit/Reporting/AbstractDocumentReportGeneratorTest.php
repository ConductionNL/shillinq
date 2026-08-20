<?php

/**
 * Unit tests for AbstractDocumentReportGenerator's docudesk hand-off.
 *
 * Proves reports-via-docudesk REQ-RVD-002 (the built ReportSection tree
 * reaches docudesk's adHocData, and the returned bytes come from docudesk
 * verbatim), REQ-RVD-003 (odt/pdf format mapping to docudesk's odf/pdf
 * vocabulary), REQ-RVD-004 (config-first/discovery-second/fail-closed
 * template selection) and REQ-RVD-005 (docudesk's absence throws
 * DocudeskUnavailableException BEFORE any object loading -- a visible,
 * distinguishable outcome, never a silent drop). Exercised through a minimal
 * anonymous subclass rather than one of the five concrete generators, since
 * they are `final` and this suite targets the shared base's own hand-off
 * logic, decoupled from any one report's business classification.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-003
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-004
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use PHPUnit\Framework\TestCase;

/**
 * A fake docudesk DocumentService recording the call it received.
 */
final class FakeAdrgDocumentService {

	/**
	 * @var array{templateId: string, dataRefs: array<mixed>, options: array<string, mixed>}|null
	 */
	public ?array $lastCall = null;

	/**
	 * @param array<string, mixed> $response The response generateDocument() returns.
	 */
	public function __construct(
		private readonly array $response = ['content' => 'FAKE-PDF-BYTES'],
	) {

	}//end __construct()

	/**
	 * @param string $templateId The template id.
	 * @param array<mixed> $dataRefs The data refs.
	 * @param array<string, mixed> $options The options.
	 *
	 * @return array<string, mixed>
	 */
	public function generateDocument(string $templateId, array $dataRefs, array $options = []): array {
		$this->lastCall = ['templateId' => $templateId, 'dataRefs' => $dataRefs, 'options' => $options];
		return $this->response;
	}//end generateDocument()
}//end class

/**
 * A fake docudesk TemplateService returning a configured template list.
 */
final class FakeAdrgTemplateService {

	/**
	 * @param array<int, array<string, mixed>> $templates The templates getTemplatesByNamespace() returns.
	 */
	public function __construct(
		private readonly array $templates = [],
	) {

	}//end __construct()

	/**
	 * @param string $namespace The namespace.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTemplatesByNamespace(string $namespace): array {
		return $this->templates;
	}//end getTemplatesByNamespace()
}//end class

/**
 * A minimal concrete generator exercising the base's default block vocabulary.
 */
final class FixtureDocumentReportGenerator extends AbstractDocumentReportGenerator {

	/**
	 * Counts loadObjects() invocations -- proves the availability check runs
	 * BEFORE any object loading (REQ-RVD-005).
	 *
	 * @var int
	 */
	public int $loadObjectsCalls = 0;

	private bool $docudeskUp;
	private ?FakeAdrgDocumentService $fakeDocumentService;
	private ?FakeAdrgTemplateService $fakeTemplateService;
	private string $configuredId;

	public function __construct(
		bool $docudeskUp = true,
		?FakeAdrgDocumentService $fakeDocumentService = null,
		?FakeAdrgTemplateService $fakeTemplateService = null,
		string $configuredId = '',
	) {
		$this->docudeskUp = $docudeskUp;
		$this->fakeDocumentService = $fakeDocumentService ?? new FakeAdrgDocumentService();
		$this->fakeTemplateService = $fakeTemplateService ?? new FakeAdrgTemplateService([
			['id' => 'tpl-1', 'category' => 'shillinq-balans'],
		]);
		$this->configuredId = $configuredId;

	}//end __construct()

	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	protected function documentTitle(): string {
		return 'Balans';
	}//end documentTitle()

	protected function build(ReportSection $section, array $context): void {
		$this->addCover($section, 'Balans', 'Balans per peildatum', $context);
		$this->addHeading($section, 'Activa');
		$this->addAmountTable(
			$section,
			'Rekening',
			'Saldo',
			[['label' => '1300 Debiteuren', 'amount' => 1000.0]],
			['label' => 'Totaal activa', 'amount' => 1000.0],
			'EUR'
		);

	}//end build()

	protected function docudeskAvailable(): bool {
		return $this->docudeskUp;
	}//end docudeskAvailable()

	protected function documentService(): object {
		return $this->fakeDocumentService;
	}//end documentService()

	protected function templateService(): object {
		return $this->fakeTemplateService;
	}//end templateService()

	protected function configuredTemplateId(string $reportType): string {
		return $this->configuredId;
	}//end configuredTemplateId()

	protected function loadObjects(string $schema, array $filters = [], int $limit = 10000): array {
		$this->loadObjectsCalls++;
		return [];
	}//end loadObjects()
}//end class

/**
 * Tests AbstractDocumentReportGenerator's docudesk hand-off.
 */
final class AbstractDocumentReportGeneratorTest extends TestCase {

	/**
	 * REQ-RVD-002: the built ReportSection tree reaches adHocData, and the
	 * returned GeneratedFile content is docudesk's response verbatim.
	 */
	public function testBuiltSectionReachesAdHocDataAndBytesAreVerbatim(): void {
		$documentService = new FakeAdrgDocumentService(['content' => 'FAKE-PDF-BYTES']);
		$generator = new FixtureDocumentReportGenerator(true, $documentService);

		$file = $generator->generate(['administrationId' => 'admin-1', 'period' => '2026'], 'pdf');

		$this->assertSame('FAKE-PDF-BYTES', $file->content);
		$this->assertNotNull($documentService->lastCall);
		$this->assertSame([], $documentService->lastCall['dataRefs']);

		$blocks = $documentService->lastCall['options']['adHocData']['report']['blocks'];
		$amountTableBlocks = array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === 'amountTable'));
		$this->assertNotEmpty($amountTableBlocks);
		$this->assertSame('1300 Debiteuren', $amountTableBlocks[0]['lines'][0]['label']);
		$this->assertSame(1000.0, $amountTableBlocks[0]['lines'][0]['amount']);
	}//end testBuiltSectionReachesAdHocDataAndBytesAreVerbatim()

	/**
	 * REQ-RVD-003: 'odt' maps to docudesk's 'odf' format key; the returned
	 * GeneratedFile keeps the public-facing 'odt' label.
	 */
	public function testOdtMapsToDocudeskOdfFormat(): void {
		$documentService = new FakeAdrgDocumentService();
		$generator = new FixtureDocumentReportGenerator(true, $documentService);

		$file = $generator->generate([], 'odt');

		$this->assertSame('odf', $documentService->lastCall['options']['format']);
		$this->assertSame('odt', $file->format);
	}//end testOdtMapsToDocudeskOdfFormat()

	/**
	 * REQ-RVD-003: an unsupported format ('docx', no longer offered) falls
	 * back to the first supported format.
	 */
	public function testUnsupportedFormatFallsBackToFirstSupported(): void {
		$generator = new FixtureDocumentReportGenerator(true);

		$file = $generator->generate([], 'docx');

		$this->assertSame(AbstractDocumentReportGenerator::supportedFormats()[0], $file->format);
	}//end testUnsupportedFormatFallsBackToFirstSupported()

	/**
	 * REQ-RVD-005: docudesk absent throws DocudeskUnavailableException BEFORE
	 * any object loading -- a visible, distinguishable outcome, not silence.
	 */
	public function testDocudeskAbsentThrowsBeforeAnyObjectLoading(): void {
		$generator = new FixtureDocumentReportGenerator(false);

		$this->expectException(DocudeskUnavailableException::class);

		try {
			$generator->generate([], 'pdf');
		} finally {
			$this->assertSame(0, $generator->loadObjectsCalls, 'build() must not run when docudesk is unavailable');
		}
	}//end testDocudeskAbsentThrowsBeforeAnyObjectLoading()

	/**
	 * REQ-RVD-004: zero matching templates fails closed with a diagnostic
	 * naming the searched namespace/category; DocumentService is never called.
	 */
	public function testZeroMatchingTemplatesFailsClosed(): void {
		$documentService = new FakeAdrgDocumentService();
		$templateService = new FakeAdrgTemplateService([]);
		$generator = new FixtureDocumentReportGenerator(true, $documentService, $templateService);

		try {
			$generator->generate([], 'pdf');
			$this->fail('Expected a RuntimeException for zero matching templates.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('shillinq', $e->getMessage());
			$this->assertStringContainsString('shillinq-balans', $e->getMessage());
		}

		$this->assertNull($documentService->lastCall, 'generateDocument() must not be called when no template matches.');
	}//end testZeroMatchingTemplatesFailsClosed()

	/**
	 * REQ-RVD-004: multiple matching templates fails closed rather than
	 * guessing between templates that produce official documents.
	 */
	public function testMultipleMatchingTemplatesFailsClosed(): void {
		$documentService = new FakeAdrgDocumentService();
		$templateService = new FakeAdrgTemplateService([
			['id' => 'tpl-1', 'category' => 'shillinq-balans'],
			['id' => 'tpl-2', 'category' => 'shillinq-balans'],
		]);
		$generator = new FixtureDocumentReportGenerator(true, $documentService, $templateService);

		try {
			$generator->generate([], 'pdf');
			$this->fail('Expected a RuntimeException for multiple matching templates.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('tpl-1', $e->getMessage());
			$this->assertStringContainsString('tpl-2', $e->getMessage());
		}

		$this->assertNull($documentService->lastCall);
	}//end testMultipleMatchingTemplatesFailsClosed()

	/**
	 * REQ-RVD-004: a configured template UUID wins over namespace/category
	 * discovery -- TemplateService is never consulted.
	 */
	public function testConfiguredTemplateIdWinsOverDiscovery(): void {
		$documentService = new FakeAdrgDocumentService();
		$templateService = new FakeAdrgTemplateService([
			['id' => 'discovered-id', 'category' => 'shillinq-balans'],
		]);
		$generator = new FixtureDocumentReportGenerator(
			true,
			$documentService,
			$templateService,
			'11111111-1111-1111-1111-111111111111'
		);

		$generator->generate([], 'pdf');

		$this->assertSame('11111111-1111-1111-1111-111111111111', $documentService->lastCall['templateId']);
	}//end testConfiguredTemplateIdWinsOverDiscovery()
}//end class
