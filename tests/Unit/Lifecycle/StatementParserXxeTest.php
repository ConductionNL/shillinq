<?php

/**
 * XXE + round-trip tests for StatementParser.
 *
 * The key test (testXxePayloadReturnsEmptyAndLeaksNothing) proves the XXE fix:
 * a DOCTYPE/ENTITY payload referencing file:///etc/passwd parses to [] and
 * never leaks host file contents into a BankStatementLine. The remaining tests
 * pin the deterministic round-trip parse of CAMT.053 / MT940 / CSV.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-bank-statement-wizard/specs/shillinq-bank-statement-wizard/spec.md
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
use Psr\Log\LoggerInterface;

/**
 * XXE-hardening + parser round-trip tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class StatementParserXxeTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var StatementParser
	 */
	private StatementParser $parser;

	/**
	 * Set up the parser with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->parser = new StatementParser(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * A small valid CAMT.053 parses to the expected lines (DBIT sign, valueDate,
	 * remittance).
	 *
	 * @return void
	 */
	public function testValidCamt053RoundTrips(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Document><BkToCstmrStmt><Stmt>'
			. '<Ntry><Amt Ccy="EUR">1210.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
			. '<ValDt><Dt>2026-05-01</Dt></ValDt>'
			. '<NtryDtls><TxDtls><Refs><EndToEndId>E2E-1</EndToEndId></Refs>'
			. '<RmtInf><Ustrd>Factuur 2026-0042</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
			. '<Ntry><Amt Ccy="EUR">300.50</Amt><CdtDbtInd>DBIT</CdtDbtInd>'
			. '<ValDt><Dt>2026-05-02</Dt></ValDt></Ntry>'
			. '</Stmt></BkToCstmrStmt></Document>';

		$lines = $this->parser->parse(contents: $xml, format: 'camt053');

		self::assertCount(2, $lines);
		self::assertSame('2026-05-01', $lines[0]['valueDate']);
		self::assertEqualsWithDelta(1210.00, $lines[0]['amount'], 0.001);
		self::assertSame('Factuur 2026-0042', $lines[0]['remittanceInfo']);
		self::assertEqualsWithDelta(-300.50, $lines[1]['amount'], 0.001);

	}//end testValidCamt053RoundTrips()

	/**
	 * A small MT940 :61:/:86: text parses into normalised lines.
	 *
	 * @return void
	 */
	public function testValidMt940RoundTrips(): void {
		$mt940 = ":20:STATEMENT\r\n"
			. ":61:260501C1210,00NTRFNONREF\r\n"
			. ":86:Factuur 2026-0042\r\n"
			. ":61:260502D300,50NTRFNONREF\r\n"
			. ":86:Huur mei\r\n";

		$lines = $this->parser->parse(contents: $mt940, format: 'mt940');

		self::assertCount(2, $lines);
		self::assertSame('2026-05-01', $lines[0]['valueDate']);
		self::assertEqualsWithDelta(1210.00, $lines[0]['amount'], 0.001);
		self::assertSame('Factuur 2026-0042', $lines[0]['remittanceInfo']);
		self::assertEqualsWithDelta(-300.50, $lines[1]['amount'], 0.001);

	}//end testValidMt940RoundTrips()

	/**
	 * A CSV with a header row parses into normalised lines.
	 *
	 * @return void
	 */
	public function testValidCsvRoundTrips(): void {
		$csv = "valueDate,amount,currency,remittanceInfo,counterpartyName,counterpartyIban\n"
			. "2026-05-01,1210.00,EUR,Factuur 2026-0042,Acme B.V.,NL00BANK0123456789\n"
			. "2026-05-02,-300.50,EUR,Huur mei,Verhuur B.V.,NL00BANK0987654321\n";

		$lines = $this->parser->parse(contents: $csv, format: 'csv');

		self::assertCount(2, $lines);
		self::assertSame('Acme B.V.', $lines[0]['counterpartyName']);
		self::assertEqualsWithDelta(-300.50, $lines[1]['amount'], 0.001);
		self::assertSame('unmatched', $lines[0]['status']);

	}//end testValidCsvRoundTrips()

	/**
	 * THE KEY TEST — an XXE payload (DOCTYPE + ENTITY referencing
	 * file:///etc/passwd) returns [] and never leaks file contents.
	 *
	 * @return void
	 */
	public function testXxePayloadReturnsEmptyAndLeaksNothing(): void {
		$xxe = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<Document><BkToCstmrStmt><Stmt>'
			. '<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
			. '<ValDt><Dt>2026-05-01</Dt></ValDt>'
			. '<NtryDtls><TxDtls><RmtInf><Ustrd>&xxe;</Ustrd></RmtInf></TxDtls></NtryDtls>'
			. '</Ntry></Stmt></BkToCstmrStmt></Document>';

		$lines = $this->parser->parse(contents: $xxe, format: 'camt053');

		// Fail-closed: a DOCTYPE/ENTITY-bearing document is rejected outright.
		self::assertSame([], $lines);

		// Belt-and-braces: even if the shape changed, no host file content
		// (e.g. the conventional /etc/passwd 'root:' marker) ever surfaces.
		$serialised = json_encode($lines);
		self::assertStringNotContainsString('root:', (string)$serialised);

	}//end testXxePayloadReturnsEmptyAndLeaksNothing()

	/**
	 * A bare ENTITY declaration (no DOCTYPE wrapper) is also rejected.
	 *
	 * @return void
	 */
	public function testEntityDeclarationRejected(): void {
		$payload = '<?xml version="1.0"?><!ENTITY foo "bar"><Document/>';

		self::assertSame([], $this->parser->parse(contents: $payload, format: 'camt053'));

	}//end testEntityDeclarationRejected()
}//end class
