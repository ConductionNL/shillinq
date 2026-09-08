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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
 * A container that knows nothing.
 *
 * The fixture below overrides every seam that would consult the container, so
 * this exists to satisfy the base's constructor and to make the absence of a
 * real container explicit: a test that starts asking it for something gets a
 * NotFound, never a live service off the global server.
 */
final class EmptyAdrgContainer implements ContainerInterface {

	/**
	 * @param string $id The service id.
	 *
	 * @return mixed Never returns.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function get(string $id): mixed {
		throw new class ('Nothing is registered on the test container: ' . $id)
			extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
		};
	}//end get()

	/**
	 * @param string $id The service id.
	 *
	 * @return bool Always false.
	 */
	public function has(string $id): bool {
		return false;
	}//end has()
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
		?ContainerInterface $container = null,
	) {
		parent::__construct($container ?? new EmptyAdrgContainer());
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
 * A generator that overrides NOTHING the container would answer.
 *
 * The fixture above stubs out every resolution seam, which is what makes it a
 * good test of the block vocabulary and a useless one for the resolution
 * itself. This subclass keeps the base's real docudeskAvailable(),
 * documentService(), templateService() and configuredTemplateId(), so the only
 * way it can render is through the container it was handed.
 */
final class ContainerBackedDocumentReportGenerator extends AbstractDocumentReportGenerator {

	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	protected function documentTitle(): string {
		return 'Balans';
	}//end documentTitle()

	protected function build(ReportSection $section, array $context): void {
		$this->addHeading($section, 'Activa');
	}//end build()
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

	/**
	 * Every resolution the base makes goes through the INJECTED container.
	 *
	 * The base used to call \OCP\Server::get(). Outside a booted Nextcloud that
	 * autowires from scratch and can recurse through a constructor cycle until
	 * memory runs out; inside one it is a hidden dependency a test cannot reach.
	 * The doubles registered below exist nowhere else, so this can only pass if
	 * the app manager, the app config and both document-app services came out of
	 * the container the generator was constructed with.
	 *
	 * It also pins the namespace fallback: the first candidate FQCN
	 * (`OCA\Filinq\...`, the post-rename name) is not registered, and resolution
	 * has to fall through to `OCA\DocuDesk\...` rather than read the miss as
	 * "the app is absent".
	 *
	 * @return void
	 */
	public function testEveryResolutionGoesThroughTheInjectedContainer(): void {
		$documentService = new FakeAdrgDocumentService(['content' => 'FROM-THE-CONTAINER']);
		$templateService = new FakeAdrgTemplateService([
			['id' => 'tpl-1', 'category' => 'shillinq-balans'],
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => $id === 'docudesk'
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $appManager, $appConfig, $documentService, $templateService): mixed {
				$asked[] = $id;

				return match ($id) {
					IAppManager::class => $appManager,
					IAppConfig::class => $appConfig,
					'OCA\\DocuDesk\\Service\\DocumentService' => $documentService,
					'OCA\\DocuDesk\\Service\\TemplateService' => $templateService,
					default => throw new \RuntimeException('not registered: ' . $id),
				};
			}
		);

		$generator = new ContainerBackedDocumentReportGenerator($container);

		$file = $generator->generate(['administrationId' => 'admin-1', 'period' => '2026'], 'pdf');

		$this->assertSame('FROM-THE-CONTAINER', $file->content);
		$this->assertSame('tpl-1', $documentService->lastCall['templateId']);
		$this->assertContains(IAppManager::class, $asked);
		$this->assertContains('OCA\\Filinq\\Service\\DocumentService', $asked, 'the post-rename FQCN must be tried first');
		$this->assertContains('OCA\\DocuDesk\\Service\\DocumentService', $asked, 'and the pre-rename one must be the fallback');
	}//end testEveryResolutionGoesThroughTheInjectedContainer()

	/**
	 * A container that cannot produce the document app reads as "docudesk is
	 * absent", which is the visible outcome, not a silent fallback.
	 *
	 * @return void
	 */
	public function testAContainerWithoutTheDocumentAppThrowsDocudeskUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): mixed {
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$generator = new ContainerBackedDocumentReportGenerator($container);

		$this->expectException(DocudeskUnavailableException::class);

		$generator->generate([], 'pdf');
	}//end testAContainerWithoutTheDocumentAppThrowsDocudeskUnavailable()
}//end class
