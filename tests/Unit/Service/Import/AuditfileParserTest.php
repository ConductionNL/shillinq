<?php

/**
 * Unit tests for the XAF 3.2 AuditfileParser.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Service\Import\AuditfileParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the deterministic, XXE-safe XAF 3.2 parser (REQ-AIM-003).
 */
final class AuditfileParserTest extends TestCase {

	/**
	 * Absolute path to the XAF fixture.
	 *
	 * @var string
	 */
	private string $fixturePath = __DIR__ . '/../../../fixtures/import/sample-xaf-3.2.xml';

	/**
	 * Parse the fixture and return the normalised structure.
	 *
	 * @return array<string,mixed>
	 */
	private function parseFixture(): array {
		$parser = new AuditfileParser();
		return $parser->parse((string)file_get_contents($this->fixturePath));
	}//end parseFixture()

	/**
	 * The fixture extracts the right ledger-account count.
	 *
	 * @return void
	 */
	public function testExtractsLedgerAccounts(): void {
		$result = $this->parseFixture();
		self::assertCount(4, $result['ledgerAccounts']);
	}//end testExtractsLedgerAccounts()

	/**
	 * RGS codes (leadReference) are extracted onto each account.
	 *
	 * @return void
	 */
	public function testExtractsRgsCodes(): void {
		$result = $this->parseFixture();
		$byCode = [];
		foreach ($result['ledgerAccounts'] as $account) {
			$byCode[$account['code']] = $account['rgsCode'];
		}

		self::assertSame('BVorDebHad', $byCode['1300']);
		self::assertSame('BSchSchCrd', $byCode['1600']);
	}//end testExtractsRgsCodes()

	/**
	 * Relations are extracted with their BTW (taxRegIdent) and KvK.
	 *
	 * @return void
	 */
	public function testExtractsRelationsWithBtw(): void {
		$result = $this->parseFixture();
		self::assertCount(2, $result['relations']);

		$acme = null;
		foreach ($result['relations'] as $relation) {
			if ($relation['name'] === 'Acme B.V.') {
				$acme = $relation;
				break;
			}
		}

		self::assertNotNull($acme);
		self::assertSame('NL876543210B01', $acme['vat']);
		self::assertSame('87654321', $acme['kvk']);
		self::assertSame('facturen@acme.nl', $acme['email']);
	}//end testExtractsRelationsWithBtw()

	/**
	 * The opening balance is extracted and balances (debit == credit).
	 *
	 * @return void
	 */
	public function testOpeningBalanceBalances(): void {
		$result = $this->parseFixture();
		self::assertCount(4, $result['openingBalances']);

		$debit = 0.0;
		$credit = 0.0;
		foreach ($result['openingBalances'] as $line) {
			$debit += $line['debit'];
			$credit += $line['credit'];
		}

		self::assertSame(30000.00, $debit);
		self::assertSame(30000.00, $credit);
	}//end testOpeningBalanceBalances()

	/**
	 * Company identity is extracted.
	 *
	 * @return void
	 */
	public function testExtractsCompany(): void {
		$result = $this->parseFixture();
		self::assertSame('Demo Administratie B.V.', $result['company']['companyName']);
		self::assertSame('NL001234567B01', $result['company']['taxRegIdent']);
	}//end testExtractsCompany()

	/**
	 * A transaction line missing its amount produces an error finding, not a silent drop.
	 *
	 * @return void
	 */
	public function testMalformedLineProducesFinding(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<auditfile xmlns="http://www.auditfiles.nl/XAF/3.2"><company>'
			. '<transactions><journal><jrnID>MEMO</jrnID><transaction><nr>1</nr>'
			. '<trLine><accID>1300</accID></trLine>'
			. '</transaction></journal></transactions></company></auditfile>';

		$parser = new AuditfileParser();
		$result = $parser->parse($xml);

		$codes = array_column($result['findings'], 'code');
		self::assertContains('transaction-line-missing-amount', $codes);

		// The row is kept (not silently dropped) with a null amount.
		$line = $result['journals'][0]['transactions'][0]['lines'][0];
		self::assertNull($line['amount']);
		self::assertSame('1300', $line['accountCode']);
	}//end testMalformedLineProducesFinding()

	/**
	 * Malformed XML returns a findings-bearing empty structure without throwing (XXE-safe).
	 *
	 * @return void
	 */
	public function testMalformedXmlReturnsFindingNotThrow(): void {
		$parser = new AuditfileParser();
		$result = $parser->parse('<auditfile><company><unclosed></auditfile>');

		self::assertSame([], $result['ledgerAccounts']);
		$codes = array_column($result['findings'], 'code');
		self::assertContains('malformed-xml', $codes);
	}//end testMalformedXmlReturnsFindingNotThrow()

	/**
	 * Empty input is handled fail-closed with a finding.
	 *
	 * @return void
	 */
	public function testEmptyInputReturnsFinding(): void {
		$parser = new AuditfileParser();
		$result = $parser->parse('   ');

		$codes = array_column($result['findings'], 'code');
		self::assertContains('empty-input', $codes);
	}//end testEmptyInputReturnsFinding()

	/**
	 * An XXE payload does not resolve external entities (no file disclosure).
	 *
	 * @return void
	 */
	public function testXxeIsSafe(): void {
		$xxe = '<?xml version="1.0"?>'
			. '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<auditfile xmlns="http://www.auditfiles.nl/XAF/3.2"><company>'
			. '<companyName>&xxe;</companyName></company></auditfile>';

		$parser = new AuditfileParser();
		$result = $parser->parse($xxe);

		// The entity must NOT have resolved to file contents.
		$name = ($result['company']['companyName'] ?? '');
		self::assertStringNotContainsString('root:', $name);
	}//end testXxeIsSafe()

	/**
	 * Streaming a fixture from disk yields the same counts as the string path.
	 *
	 * @return void
	 */
	public function testStreamFileMatchesStringParse(): void {
		$parser = new AuditfileParser();
		$streamed = $parser->parseFile($this->fixturePath);

		self::assertCount(4, $streamed['ledgerAccounts']);
		self::assertCount(2, $streamed['relations']);
		self::assertCount(4, $streamed['openingBalances']);
	}//end testStreamFileMatchesStringParse()

}//end class
