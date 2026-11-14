<?php

/**
 * What each document generator hands filinq, end to end.
 *
 * AbstractDocumentReportGeneratorTest proves the hand-off through an anonymous
 * subclass, and the golden tests render the five concrete generators' bodies
 * through `build()` with the docudesk step cut out. Nobody drives a real
 * generator through `generate()`, so the per-generator half of the hand-off —
 * its own document title, the catalogue subtitle, the report-type meta, the
 * template its type selects and the file it names — has never been asserted,
 * and neither has what happens when filinq is absent or its renderer cannot be
 * resolved.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator;
use OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator;
use OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator;
use OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A container answering a fixed service map.
 */
final class HandoffContainer implements ContainerInterface {

	/**
	 * The ids asked for, in order.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * Hold the map.
	 *
	 * @param array<string, object> $services Service id to instance.
	 */
	public function __construct(
		private readonly array $services,
	) {

	}//end __construct()

	/**
	 * Answer the mapped service.
	 *
	 * @param string $id The service id.
	 *
	 * @return mixed The service.
	 *
	 * @throws RuntimeException When the id is not mapped.
	 */
	public function get(string $id): mixed {
		$this->asked[] = $id;
		if (array_key_exists($id, $this->services) === false) {
			throw new RuntimeException('No service ' . $id);
		}

		return $this->services[$id];

	}//end get()

	/**
	 * Whether the id is mapped.
	 *
	 * @param string $id The service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return array_key_exists($id, $this->services);

	}//end has()
}//end class

/**
 * A filinq DocumentService recording what it was asked to render.
 */
final class HandoffDocumentService {

	/**
	 * The calls, as [templateId, options].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * Record the call and answer fixed bytes.
	 *
	 * @param string               $templateId The template.
	 * @param array<mixed>         $dataRefs   The data refs.
	 * @param array<string, mixed> $options    The options.
	 *
	 * @return array<string, mixed>
	 */
	public function generateDocument(string $templateId, array $dataRefs, array $options = []): array {
		$this->calls[] = [$templateId, $options];

		return ['content' => 'RENDERED-BYTES'];

	}//end generateDocument()
}//end class

/**
 * A filinq TemplateService offering one template per statement category.
 */
final class HandoffTemplateService {

	/**
	 * The namespaces asked for.
	 *
	 * @var list<string>
	 */
	public array $namespaces = [];

	/**
	 * Answer one template per shillinq report category.
	 *
	 * @param string $namespace The namespace.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getTemplatesByNamespace(string $namespace): array {
		$this->namespaces[] = $namespace;

		return [
			['id' => 'tpl-jaarrekening', 'category' => 'shillinq-jaarrekening'],
			['id' => 'tpl-balans', 'category' => 'shillinq-balans'],
			['id' => 'tpl-winst-verlies', 'category' => 'shillinq-winst-verlies'],
			['id' => 'tpl-bbv', 'category' => 'shillinq-bbv-jaarstukken'],
			['id' => 'tpl-management-letter', 'category' => 'shillinq-management-letter'],
		];

	}//end getTemplatesByNamespace()
}//end class

/**
 * An object service that holds nothing, so every generator renders its empty
 * state and the hand-off is what remains under test.
 */
final class HandoffEmptyObjectService {

	/**
	 * Accept the register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;

	}//end setRegister()

	/**
	 * Accept the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		return $this;

	}//end setSchema()

	/**
	 * Answer no rows.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		return [];

	}//end findAll()

	/**
	 * Answer no object.
	 *
	 * @param string $id The object id.
	 *
	 * @return mixed Always null.
	 */
	public function find(string $id): mixed {
		return null;

	}//end find()
}//end class

/**
 * The document generators' own half of the filinq hand-off.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BalanceSheetReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ProfitLossReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractStatementReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AccountBucket
 * @uses   \OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection
 * @uses   \OCA\Shillinq\Reporting\Generator\StatementRun
 * @uses   \OCA\Shillinq\Kernel\Model\GeneratedFile
 * @uses   \OCA\Shillinq\Reporting\ReportCatalogue
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Support\FleetAppId
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocumentReportHandoffTest extends TestCase {

	/**
	 * The document service of the last render.
	 *
	 * @var HandoffDocumentService
	 */
	private HandoffDocumentService $documents;

	/**
	 * The container of the last render.
	 *
	 * @var HandoffContainer
	 */
	private HandoffContainer $container;

	/**
	 * Build a container with filinq present under its current namespace.
	 *
	 * @param bool $appInstalled   Whether the app manager reports filinq (or docudesk) installed.
	 * @param bool $servicesExist  Whether the filinq services resolve.
	 *
	 * @return HandoffContainer
	 */
	private function container(bool $appInstalled = true, bool $servicesExist = true): HandoffContainer {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $appInstalled === true && $app === 'filinq'
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->documents = new HandoffDocumentService();
		$services = [
			IAppManager::class => $appManager,
			IAppConfig::class => $appConfig,
			'OCA\\OpenRegister\\Service\\ObjectService' => new HandoffEmptyObjectService(),
		];
		if ($servicesExist === true) {
			$services['OCA\\Filinq\\Service\\DocumentService'] = $this->documents;
			$services['OCA\\Filinq\\Service\\TemplateService'] = new HandoffTemplateService();
		}

		$this->container = new HandoffContainer($services);

		return $this->container;

	}//end container()

	/**
	 * The five document generators with their titles, catalogue labels and
	 * templates.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function generators(): array {
		return [
			'annual accounts' => [AnnualAccountsReportGenerator::class, 'annual-accounts', 'Jaarrekening', 'Jaarrekening', 'tpl-jaarrekening'],
			'balance sheet' => [BalanceSheetReportGenerator::class, 'balance-sheet', 'Balans', 'Balans', 'tpl-balans'],
			'profit and loss' => [
				ProfitLossReportGenerator::class,
				'profit-loss',
				'Winst- en verliesrekening',
				'Winst- en verliesrekening',
				'tpl-winst-verlies',
			],
			'bbv jaarstukken' => [BbvJaarstukkenReportGenerator::class, 'bbv-jaarstukken', 'BBV-jaarstukken', 'BBV-jaarstukken', 'tpl-bbv'],
			'management letter' => [
				ManagementLetterReportGenerator::class,
				'management-letter',
				'Management letter',
				'Management letter',
				'tpl-management-letter',
			],
		];

	}//end generators()

	/**
	 * Each generator renders through its own template and hands filinq its own
	 * title, the catalogue label as the subtitle, its report type and the
	 * context it was given.
	 *
	 * @param class-string $class      The generator.
	 * @param string       $reportType Its report-type id.
	 * @param string       $title      Its document title.
	 * @param string       $label      Its catalogue label.
	 * @param string       $templateId The template its category selects.
	 *
	 * @return void
	 */
	#[DataProvider('generators')]
	public function testEachGeneratorHandsFilinqItsOwnTitleTypeAndTemplate(
		string $class,
		string $reportType,
		string $title,
		string $label,
		string $templateId,
	): void {
		$generator = new $class($this->container());

		$file = $generator->generate(['period' => '2026-Q1', 'administrationId' => 'adm-1', 'userId' => 'alice'], 'pdf');

		$this->assertCount(1, $this->documents->calls);
		[$usedTemplate, $options] = $this->documents->calls[0];
		$report = $options['adHocData']['report'];

		$this->assertSame($templateId, $usedTemplate);
		$this->assertSame([$title, $label], [$report['title'], $report['subtitle']]);
		$this->assertNotSame([], $report['blocks']);
		$this->assertSame(
			['reportType' => $reportType, 'period' => '2026-Q1', 'administrationId' => 'adm-1'],
			array_diff_key($options['adHocData']['meta'], ['generatedAt' => null])
		);
		$this->assertSame('alice', $options['userId']);
		$this->assertSame(
			[$reportType . '-2026-Q1-adm-1.pdf', 'application/pdf', 'pdf', 'RENDERED-BYTES'],
			[$file->fileName, $file->mimeType, $file->format, $file->content]
		);

	}//end testEachGeneratorHandsFilinqItsOwnTitleTypeAndTemplate()

	/**
	 * The editable format maps to filinq's own odf key and names the file after
	 * the report alone when no period or administration is given, and an
	 * unnamed renderer defaults to the system user.
	 *
	 * @return void
	 */
	public function testTheEditableFormatMapsToOdfAndAnUnscopedReportIsNamedAfterItsType(): void {
		$generator = new BalanceSheetReportGenerator($this->container());

		$file = $generator->generate([], 'odt');

		$this->assertSame('odf', $this->documents->calls[0][1]['format']);
		$this->assertSame('system', $this->documents->calls[0][1]['userId']);
		$this->assertSame(
			['balance-sheet.odt', 'application/vnd.oasis.opendocument.text', 'odt'],
			[$file->fileName, $file->mimeType, $file->format]
		);

	}//end testTheEditableFormatMapsToOdfAndAnUnscopedReportIsNamedAfterItsType()

	/**
	 * A file name keeps only safe characters and never ends on a separator.
	 *
	 * @return void
	 */
	public function testAFileNameKeepsOnlySafeCharacters(): void {
		$generator = new ProfitLossReportGenerator($this->container());

		$file = $generator->generate(['period' => '2026/Q1 (concept)', 'administrationId' => 'adm 1!'], 'pdf');

		$this->assertSame('profit-loss-2026-Q1-concept--adm-1.pdf', $file->fileName);

	}//end testAFileNameKeepsOnlySafeCharacters()

	/**
	 * Without filinq installed, no generator renders anything: the absence is
	 * raised as its own exception naming the report type, and the renderer is
	 * never called.
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheReportIsRefusedByName(): void {
		$generator = new AnnualAccountsReportGenerator($this->container(false));

		try {
			$generator->generate(['period' => '2026'], 'pdf');
			$this->fail('Expected a DocudeskUnavailableException.');
		} catch (DocudeskUnavailableException $e) {
			$this->assertStringContainsString('annual-accounts', $e->getMessage());
		}

		$this->assertSame([], $this->documents->calls);

	}//end testWithoutFilinqTheReportIsRefusedByName()

	/**
	 * filinq installed but its renderer missing under every namespace it has
	 * shipped under is the same refusal — never a half-rendered report.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRendererIsTheSameRefusal(): void {
		$generator = new BbvJaarstukkenReportGenerator($this->container(true, false));

		$this->expectException(DocudeskUnavailableException::class);

		try {
			$generator->generate(['period' => '2026'], 'pdf');
		} finally {
			$this->assertContains('OCA\\Filinq\\Service\\DocumentService', $this->container->asked);
			$this->assertContains('OCA\\DocuDesk\\Service\\DocumentService', $this->container->asked);
		}

	}//end testAnUnresolvableRendererIsTheSameRefusal()
}//end class
