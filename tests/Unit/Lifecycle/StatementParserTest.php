<?php

/**
 * Unit tests for StatementParser.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md#req-br-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StatementParser;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for StatementParser covering REQ-BR-003 / REQ-BR-004.
 */
class StatementParserTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The parser under test.
	 *
	 * @var StatementParser
	 */
	private StatementParser $parser;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->parser = new StatementParser(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * CSV import parses rows into normalised line maps with unmatched status.
	 *
	 * @return void
	 */
	public function testCsvParsesRows(): void {
		$csv = "valueDate,amount,currency,remittanceInfo,counterpartyName,counterpartyIban\n"
			. "2026-01-15,1210.00,EUR,Betaling factuur 2026-0042,Acme B.V.,NL00BANK0123456789\n"
			. "2026-01-16,-300.50,EUR,Huur januari,Verhuur B.V.,NL00BANK0987654321\n";

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$lines = $this->parser->parse(contents: $csv, format: 'csv');

		self::assertCount(2, $lines);
		self::assertSame('2026-01-15', $lines[0]['valueDate']);
		self::assertEqualsWithDelta(1210.00, $lines[0]['amount'], 0.001);
		self::assertSame('unmatched', $lines[0]['status']);
		self::assertEqualsWithDelta(-300.50, $lines[1]['amount'], 0.001);

	}//end testCsvParsesRows()

	/**
	 * CAMT.053 import parses entries, applying debit/credit sign, XXE-safe.
	 *
	 * @return void
	 */
	public function testCamt053ParsesEntries(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Document><BkToCstmrStmt><Stmt>'
			. '<Ntry><Amt Ccy="EUR">1210.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
			. '<ValDt><Dt>2026-01-15</Dt></ValDt>'
			. '<NtryDtls><TxDtls><Refs><EndToEndId>E2E-1</EndToEndId></Refs>'
			. '<RmtInf><Ustrd>Factuur 2026-0042</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
			. '<Ntry><Amt Ccy="EUR">300.50</Amt><CdtDbtInd>DBIT</CdtDbtInd>'
			. '<ValDt><Dt>2026-01-16</Dt></ValDt></Ntry>'
			. '</Stmt></BkToCstmrStmt></Document>';

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$lines = $this->parser->parse(contents: $xml, format: 'camt053');

		self::assertCount(2, $lines);
		self::assertEqualsWithDelta(1210.00, $lines[0]['amount'], 0.001);
		self::assertSame('Factuur 2026-0042', $lines[0]['remittanceInfo']);
		// DBIT entry is negated.
		self::assertEqualsWithDelta(-300.50, $lines[1]['amount'], 0.001);

	}//end testCamt053ParsesEntries()

	/**
	 * An unknown format returns an empty array (no partial import).
	 *
	 * @return void
	 */
	public function testUnknownFormatReturnsEmpty(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame([], $this->parser->parse(contents: 'garbage', format: 'ofx'));

	}//end testUnknownFormatReturnsEmpty()

	/**
	 * allLinesResolved returns true when no unmatched lines remain (REQ-BR-004).
	 *
	 * @return void
	 */
	public function testAllLinesResolvedTrueWhenNoneUnmatched(): void {
		$this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: []));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->parser->allLinesResolved(statementId: 'BS-2026-001'));

	}//end testAllLinesResolvedTrueWhenNoneUnmatched()

	/**
	 * allLinesResolved returns false when at least one unmatched line remains.
	 *
	 * @return void
	 */
	public function testAllLinesResolvedFalseWhenUnmatchedRemain(): void {
		$this->container->method('get')
			->willReturn($this->buildObjectServiceStub(lines: [['lineId' => 'L1', 'status' => 'unmatched']]));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->parser->allLinesResolved(statementId: 'BS-2026-001'));

	}//end testAllLinesResolvedFalseWhenUnmatchedRemain()

	/**
	 * allLinesResolved fails closed (false) on exception.
	 *
	 * @return void
	 */
	public function testAllLinesResolvedFailsClosed(): void {
		$this->container->method('get')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->parser->allLinesResolved(statementId: 'BS-2026-002'));

	}//end testAllLinesResolvedFailsClosed()

	/**
	 * Build an anonymous ObjectService stub returning lines from findAll().
	 *
	 * @param array<mixed> $lines Records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $lines): object {
		return new class($lines) {
			/**
			 * Records to return.
			 *
			 * @var array<mixed>
			 */
			private array $lines;

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $lines Lines to return.
			 */
			public function __construct(array $lines) {
				$this->lines = $lines;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return all stubbed lines.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->lines;
			}//end findAll()
		};
	}//end buildObjectServiceStub()
}//end class
