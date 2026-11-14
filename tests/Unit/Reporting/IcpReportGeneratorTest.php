<?php

/**
 * Unit tests for IcpReportGenerator: the `icp-opgaaf` report type.
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
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\Generator\IcpReportGenerator;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\Icp\IcpStatementFiler;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Tests\Unit\Service\Icp\IcpObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ICP statement is a report (REQ-ICR-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpReportGenerator::class)]
#[UsesClass(IcpStatementPreparer::class)]
#[UsesClass(IcpStatementFiler::class)]
#[UsesClass(IcpCalculator::class)]
#[UsesClass(GeneratedFile::class)]
#[UsesClass(ReportCatalogue::class)]
#[UsesClass(ReportGenerationService::class)]
final class IcpReportGeneratorTest extends TestCase {

	use IcpObjectStore;

	/**
	 * The generator over a prepared Q3 statement, or none.
	 *
	 * @param bool $prepared Whether the statement was prepared.
	 *
	 * @return IcpReportGenerator
	 */
	private function generator(bool $prepared=true): IcpReportGenerator {
		$rows = ['Administration' => [['@self' => ['id' => 'adm-1'], 'vatNumber' => 'NL001234567B01']]];
		if ($prepared === true) {
			$rows['IcpOpgaaf'] = [
				[
					'administrationId' => 'adm-1',
					'period' => '2026-Q3',
					'status' => 'draft',
					'lines' => [
						['buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12000.0],
						['buyerVatId' => 'DE123456789', 'supplyType' => 'S', 'amountExclVat' => '3000.005'],
						'not a line',
					],
					'total' => 15000.01,
				],
			];
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$store = $this->objectStore(rows: $rows);
		$icp = $this->createMock(IcpServiceInterface::class);
		$preparer = new IcpStatementPreparer(icpService: $icp, objectService: $store, appConfig: $config);

		return new IcpReportGenerator(
			preparer: $preparer,
			filer: new IcpStatementFiler(
				preparer: $preparer,
				icpService: $icp,
				calculator: new IcpCalculator(),
				digipoort: $this->createMock(DigipoortSbrAdapterInterface::class),
				objectService: $store,
				appConfig: $config,
				time: $this->createMock(ITimeFactory::class),
			),
		);
	}//end generator()

	/**
	 * It serves icp-opgaaf in the catalogue's XML and CSV.
	 *
	 * @return void
	 */
	public function testItServesTheIcpReportTypeInXmlAndCsv(): void {
		$this->assertSame('icp-opgaaf', IcpReportGenerator::reportType());
		$this->assertSame(['xml', 'csv'], IcpReportGenerator::supportedFormats());
		$this->assertSame(['xml', 'csv'], ReportCatalogue::byId('icp-opgaaf')['formats']);
	}//end testItServesTheIcpReportTypeInXmlAndCsv()

	/**
	 * The XML is the instance of the prepared statement.
	 *
	 * @return void
	 */
	public function testTheXmlIsTheInstanceOfThePreparedStatement(): void {
		$file = $this->generator()->generate(['period' => '2026-Q3', 'administrationId' => 'adm-1'], 'xml');

		$this->assertSame(['icp-opgaaf-2026-Q3.xml', 'text/xml', 'xml'], [$file->fileName, $file->mimeType, $file->format]);
		$this->assertStringContainsString('<bd-i:filerVatId>NL001234567B01</bd-i:filerVatId>', $file->content);
		$this->assertSame(2, substr_count($file->content, '<bd-i:IntracommunautairePrestatieMutatieSpecificatie>'));
	}//end testTheXmlIsTheInstanceOfThePreparedStatement()

	/**
	 * The CSV lists the lines and the total, two decimals half-even.
	 *
	 * @return void
	 */
	public function testTheCsvListsTheLinesAndTheTotal(): void {
		$file = $this->generator()->generate(['period' => '2026-Q3', 'administrationId' => 'adm-1'], 'csv');

		$this->assertSame(['icp-opgaaf-2026-Q3.csv', 'text/csv', 'csv'], [$file->fileName, $file->mimeType, $file->format]);
		$this->assertSame(
			"buyerVatId,supplyType,amountExclVat\nBE0123456749,L,12000.00\nDE123456789,S,3000.00\ntotal,,15000.01\n",
			$file->content
		);
	}//end testTheCsvListsTheLinesAndTheTotal()

	/**
	 * A period without a prepared statement and an unsupported format are refused.
	 *
	 * @return void
	 */
	public function testNothingPreparedOrAnotherFormatIsRefused(): void {
		try {
			$this->generator(false)->generate(['period' => '2026-Q3', 'administrationId' => 'adm-1'], 'xml');
			$this->fail('Rendered a statement that was never prepared.');
		} catch (RuntimeException $e) {
			$this->assertSame('No ICP statement has been prepared for 2026-Q3. Prepare it on the ICP page first.', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The ICP statement is XML or CSV, not pdf.');
		$this->generator()->generate(['period' => '2026-Q3', 'administrationId' => 'adm-1'], 'pdf');
	}//end testNothingPreparedOrAnotherFormatIsRefused()

	/**
	 * ReportGenerationService discovers it: icp-opgaaf no longer answers "no generator".
	 *
	 * @return void
	 */
	public function testTheReportServiceNoLongerAnswersNoGenerator(): void {
		$generator = $this->generator();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($generator): object {
				if (str_ends_with($id, '\\IcpReportGenerator') === true) {
					return $generator;
				}

				throw new RuntimeException('not in this container: ' . $id);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = new ReportGenerationService(
			$container,
			$this->createMock(IRootFolder::class),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$session,
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->generate('icp-opgaaf', '2026-Q3', 'adm-1', 'csv', 'scheduler');

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(['icp-opgaaf', 'csv', 'icp-opgaaf-2026-Q3.csv', 'ready'], [$result['reportType'], $result['format'], $result['fileName'], $result['status']]);
	}//end testTheReportServiceNoLongerAnswersNoGenerator()
}//end class
