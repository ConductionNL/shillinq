<?php

/**
 * Golden test for CAMT.053 parsing in StatementParser.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StatementParser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the parsed lines for a set of CAMT.053 inputs, including rejections.
 */
final class StatementParserCamtGoldenTest extends TestCase {

	/**
	 * Every input's parsed lines match the golden fixture.
	 *
	 * @return void
	 */
	public function testParsedLinesMatchGolden(): void {
		$parser = new StatementParser(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			logger: new NullLogger(),
		);

		$result = [];
		foreach ($this->inputs() as $name => $xml) {
			$result[$name] = $parser->parse(contents: $xml, format: 'camt053');
		}

		$file = __DIR__.'/fixtures/statement-parser-camt.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('CAMT_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(expected: file_get_contents($file), actual: $json."\n");

	}//end testParsedLinesMatchGolden()

	/**
	 * The inputs, by name.
	 *
	 * @return array<string, string>
	 */
	private function inputs(): array {
		$head = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>';
		$tail = '</Stmt></BkToCstmrStmt></Document>';
		$full = '<Ntry><Amt Ccy="EUR">1210.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><ValDt><Dt>2026-01-15</Dt></ValDt>'
			.'<NtryDtls><TxDtls><Refs><EndToEndId>E2E-1</EndToEndId></Refs>'
			.'<RmtInf><Ustrd>Factuur 1</Ustrd><Ustrd>second</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>';
		$debit = '<Ntry><Amt Ccy="USD">300.5</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-01-01</Dt></BookgDt></Ntry>';
		$bare = '<Ntry></Ntry>';
		$twoAmounts = '<Ntry><Amt>7</Amt><CdtDbtInd>dbit</CdtDbtInd><NtryDtls><TxDtls><AmtDtls><Amt>9</Amt></AmtDtls></TxDtls></NtryDtls></Ntry>';
		$garbage = '<Ntry><Amt>abc</Amt><CdtDbtInd>DBIT</CdtDbtInd></Ntry>';
		return [
			'mixed' => $head.$full.$debit.$bare.$twoAmounts.$garbage.$tail,
			'noEntries' => $head.$tail,
			'notXml' => 'this is not xml',
			'empty' => '',
			'doctype' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x "y">]><Document><Ntry><Amt>1</Amt></Ntry></Document>',
			'entityLower' => '<?xml version="1.0"?><Document><!entity><Ntry><Amt>1</Amt></Ntry></Document>',
			'nestedNtry' => '<Root><A><Ntry><Amt>2.25</Amt></Ntry></A><B><Ntry><Amt>-3</Amt><CdtDbtInd>DBIT</CdtDbtInd></Ntry></B></Root>',
		];

	}//end inputs()
}//end class
