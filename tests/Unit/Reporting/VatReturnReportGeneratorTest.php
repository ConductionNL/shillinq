<?php

/**
 * Unit tests for VatReturnReportGenerator.
 *
 * The return file is rendered from the return VATReturnService prepared, so
 * the file, the return page and the correction check show the same figures
 * (REQ-VBTW-004). The return is prepared here through the real posting mapper
 * and the real VATReturnService, not from hand-written declarations.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Reporting\Generator\VatReturnReportGenerator;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The VAT return file shows the prepared return's declarations per box.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnReportGeneratorTest extends TestCase {

	/**
	 * The expected file for the Korenbloem quarter.
	 */
	private const SNAPSHOT = __DIR__ . '/../../fixtures/vat-return-korenbloem-2026-Q3.xml';

	/**
	 * The store both the return service and the generator read.
	 *
	 * @var InMemoryObjectServiceStub|null
	 */
	private ?InMemoryObjectServiceStub $store = null;

	/**
	 * Post the Korenbloem quarter through the real posting mapper: a sale of
	 * EUR 10,000 at the low tariff with EUR 900 VAT, and purchases of EUR 4,000
	 * at the high tariff and EUR 2,000 at the low tariff with EUR 1,020 input VAT.
	 *
	 * @return array<string,list<array<string,mixed>>> Rows per schema, with the invoices themselves.
	 */
	private function korenbloemBooks(): array {
		$sale = [
			'id' => 'ar-kb-1', 'invoiceNumber' => '2026-0301', 'invoiceDate' => '2026-08-14',
			'administrationId' => 'adm-kb', 'currency' => 'EUR', 'grossAmount' => 10900.0, 'netAmount' => 10000.0, 'vatAmount' => 900.0,
			'invoiceLines' => [['itemName' => 'Brood en banket', 'netAmount' => 10000.0, 'vatCategory' => 'S', 'vatRate' => 9]],
			'lifecycleState' => 'issued',
		];
		$purchase = [
			'id' => 'ap-kb-1', 'invoiceNumber' => 'INK-301', 'invoiceDate' => '2026-08-02', 'administrationId' => 'adm-kb',
			'totalAmount' => 7020.0, 'taxAmount' => 1020.0,
			'lines' => [
				['accountNumber' => '7000', 'amount' => 4000.0, 'description' => 'Oven', 'taxCode' => 'high'],
				['accountNumber' => '7010', 'amount' => 2000.0, 'description' => 'Meel', 'taxCode' => 'low'],
			],
			'state' => 'posted',
		];

		$posting = new InMemoryObjectStore();
		$tariffs = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/seeds/btw-tariffs-2026.json'), true)['tariffs'];
		$posting->rows['VatTariff'] = $tariffs;

		$action = new MaterialiseGlTransactionAction($posting->mock($this), $this->defaultsConfig(), $this->createMock(LoggerInterface::class));
		$action->execute($sale, [], ['sourceSchema' => 'ARInvoice'], MaterialiseGlTransactionAction::class);
		$action->execute($purchase, [], ['sourceSchema' => 'APInvoice'], MaterialiseGlTransactionAction::class);

		return [
			'GLTransaction' => $posting->savedOf('GLTransaction'),
			'GLLine' => $posting->savedOf('GLLine'),
			'VatTariff' => $tariffs,
			'ARInvoice' => [$sale],
			'APInvoice' => [$purchase],
		];
	}//end korenbloemBooks()

	/**
	 * An app config that answers every key with its default.
	 *
	 * @return IAppConfig
	 */
	private function defaultsConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return $appConfig;
	}//end defaultsConfig()

	/**
	 * The generator over the shared store.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return VatReturnReportGenerator
	 */
	private function generator(?LoggerInterface $logger = null): VatReturnReportGenerator {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);

		return new VatReturnReportGenerator($container, ($logger ?? new NullLogger()));
	}//end generator()

	/**
	 * Prepare the quarter's return through the real VATReturnService.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return array<string,mixed> The prepared return.
	 */
	private function prepare(array $rows): array {
		$saved = [];
		$this->store = new InMemoryObjectServiceStub($rows, $saved, true);

		$service = new VATReturnService(
			appConfig: $this->defaultsConfig(),
			logger: new NullLogger(),
			objectService: $this->store,
		);

		return $service->createReturn(administrationId: 'adm-kb', period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard');
	}//end prepare()

	/**
	 * The file's content with the moment it was drawn up taken out.
	 *
	 * @param string $content The generated XML.
	 *
	 * @return string
	 */
	private function withoutTimestamp(string $content): string {
		return (string)preg_replace('/ opgesteld="[^"]*"/', ' opgesteld=""', $content);
	}//end withoutTimestamp()

	/**
	 * The amounts of one rubriek as [base, vat], base null when the rubriek has none.
	 *
	 * @param string $content The generated XML.
	 * @param string $code    The rubriek code.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	private function rubriek(string $content, string $code): array {
		$dom = new DOMDocument();
		self::assertTrue($dom->loadXML($content));
		$xpath = new DOMXPath($dom);
		$rubrieken = $xpath->query('/BTWAangifte/Rubriek[@code="' . $code . '"]');
		self::assertNotFalse($rubrieken);
		$rubriek = $rubrieken->item(0);
		if ($rubriek !== null) {
			$base = $xpath->query('Bedrag', $rubriek)->item(0);
			return [$base?->textContent, (string)$xpath->query('Omzetbelasting', $rubriek)->item(0)?->textContent];
		}

		self::fail('No rubriek ' . $code);
	}//end rubriek()

	/**
	 * REQ-VBTW-004 "A quarterly return aggregates the period's postings": the
	 * generated file shows the prepared return's figures, 1b EUR 10,000 and
	 * EUR 900, 5b EUR 1,020, payable EUR -120, and equals the snapshot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public function testTheFileShowsThePreparedReturnPerBox(): void {
		$prepared = $this->prepare($this->korenbloemBooks());

		$file = $this->generator()->generate(['period' => '2026-Q3', 'administrationId' => 'adm-kb'], 'xml');

		self::assertSame(['10000.00', '900.00'], $this->rubriek($file->content, '1b'));
		self::assertSame(['0.00', '0.00'], $this->rubriek($file->content, '1a'));
		self::assertSame([null, '900.00'], $this->rubriek($file->content, '5a'));
		self::assertSame([null, '1020.00'], $this->rubriek($file->content, '5b'));
		self::assertSame([null, '-120.00'], $this->rubriek($file->content, '5g'));
		self::assertSame(
			round((float)$prepared['totalVATCollected'] - (float)$prepared['totalVATPaid'], 2),
			(float)$this->rubriek($file->content, '5g')[1]
		);

		self::assertStringEqualsFile(self::SNAPSHOT, $this->withoutTimestamp($file->content));
		self::assertSame('vat-return-2026-Q3.xml', $file->fileName);
	}//end testTheFileShowsThePreparedReturnPerBox()

	/**
	 * A period without a prepared return is refused, even though invoices of
	 * the period exist: the file is never derived from invoices again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public function testAPeriodWithoutAPreparedReturnIsRefused(): void {
		$this->prepare($this->korenbloemBooks());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No VAT return has been prepared for 2026-Q2');

		$this->generator()->generate(['period' => '2026-Q2', 'administrationId' => 'adm-kb'], 'xml');
	}//end testAPeriodWithoutAPreparedReturnIsRefused()

	/**
	 * A filed return is the one rendered when a draft of the same period also
	 * exists, and a declaration without a box is left out and logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
	 */
	public function testTheFiledReturnWinsAndABoxlessDeclarationIsLeftOut(): void {
		$rows = $this->korenbloemBooks();
		$rows['BtwAangifte'] = [
			[
				'id' => 'filed-q3', 'returnNumber' => 'NL-2026-Q3', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 3,
				'administrationId' => 'adm-kb', 'statusCode' => 'filed',
			],
		];
		$rows['VATDeclaration'] = [
			[
				'id' => 'filed-1a', 'declarationNumber' => 'VAT-filed-1A', 'returnId' => 'filed-q3', 'returnBox' => '1a',
				'type' => 'collected', 'taxRate' => 21.0, 'totalVATAmount' => 210.0, 'totalTaxableAmount' => 1000.0, 'lineCount' => 2,
			],
			[
				'id' => 'filed-old', 'declarationNumber' => 'VAT-filed-OLD', 'returnId' => 'filed-q3',
				'type' => 'collected', 'taxRate' => 9.0, 'totalVATAmount' => 45.0, 'totalTaxableAmount' => 500.0, 'lineCount' => 1,
			],
		];
		// A draft of the same quarter, prepared after filing.
		$this->prepare($rows);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			self::stringContains('without a return box'),
			self::equalTo(['returnId' => 'filed-q3', 'declaration' => 'VAT-filed-OLD'])
		);

		$file = $this->generator($logger)->generate(['period' => '2026-Q3', 'administrationId' => 'adm-kb'], 'xml');

		self::assertStringContainsString('aangifte="NL-2026-Q3"', $file->content);
		self::assertStringContainsString('status="filed"', $file->content);
		self::assertSame(['1000.00', '210.00'], $this->rubriek($file->content, '1a'));
		self::assertSame(['0.00', '0.00'], $this->rubriek($file->content, '1b'));
		self::assertSame([null, '210.00'], $this->rubriek($file->content, '5g'));
	}//end testTheFiledReturnWinsAndABoxlessDeclarationIsLeftOut()
}//end class
