<?php

/**
 * Golden tests for the XAF 3.2 AuditfileParser.
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
use Psr\Log\LoggerInterface;

/**
 * Pins the full parsed structure, every finding and every log line, for the
 * string and the streaming entry points.
 */
final class AuditfileParserGoldenTest extends TestCase {

	/**
	 * Files written by the test.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * Remove the files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A file with the given contents.
	 *
	 * @param string $contents The contents.
	 *
	 * @return string The path.
	 */
	private function file(string $contents): string {
		$path          = sys_get_temp_dir() . '/xaf-golden-' . uniqid() . '.xml';
		$this->paths[] = $path;
		file_put_contents($path, $contents);
		return $path;
	}//end file()

	/**
	 * A company block exercising every extracted field and branch.
	 *
	 * @param string $suffix Distinguishes two companies.
	 *
	 * @return string
	 */
	private function company(string $suffix): string {
		return '<company>'
			. '<companyIdent> 1234' . $suffix . ' </companyIdent><companyName>Acme ' . $suffix . '</companyName>'
			. ($suffix === '1' ? '<taxRegistration><taxRegIdent>NL001B01</taxRegIdent></taxRegistration>' : '<taxRegIdent>NL002B02</taxRegIdent>')
			. '<currencyCode>EUR</currencyCode>'
			. '<customersSuppliers><customerSupplier><custSupID>C' . $suffix . '</custSupID><companyName>Klant</companyName>'
			. '<companyIdent>777</companyIdent><custSupTp>C</custSupTp><taxRegistration><taxRegIdent>NL9B9</taxRegIdent></taxRegistration>'
			. '<streetAddress><telephone>010</telephone><eMail>a@b.nl</eMail></streetAddress></customerSupplier></customersSuppliers>'
			. '<generalLedger><ledgerAccount><accID>1000</accID><accDesc>Kas</accDesc><accTp>B</accTp><leadReference>BLimKas</leadReference></ledgerAccount>'
			. '<ledgerAccount><accDesc>no id</accDesc></ledgerAccount></generalLedger>'
			. '<openingBalance><obLine><accID>1000</accID><amnt>100.5</amnt><amntTp>d</amntTp></obLine>'
			. '<obLine><accID>0800</accID><amnt>100.5</amnt><amntTp>C</amntTp></obLine><obLine><amnt>1</amnt></obLine>'
			. '<obLine><accID>9</accID><amnt>3</amnt><amntTp>X</amntTp></obLine></openingBalance>'
			. '<transactions><journal><jrnID>MEM' . $suffix . '</jrnID>'
			. '<transaction><nr>1</nr><trLine><accID>1000</accID><amnt>10</amnt><amntTp>d</amntTp></trLine>'
			. '<trLine><accID>0800</accID><amntTp>C</amntTp></trLine></transaction>'
			. '<transaction><nr>2</nr></transaction></journal><journal><jrnID>EMPTY</jrnID></journal></transactions>'
			. '</company>';
	}//end company()

	/**
	 * Every parse outcome and the warnings logged on the way.
	 *
	 * @return array<string, mixed>
	 */
	private function outcomes(): array {
		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$parser = new AuditfileParser($logger);
		$ns     = '<auditfile xmlns="http://www.auditfiles.nl/XAF/3.2">';

		$outcomes = [
			'string full' => $parser->parse('  ' . $ns . $this->company('1') . '</auditfile>  '),
			'string empty' => $parser->parse(" \n "),
			'string doctype' => $parser->parse('<!doctype x><auditfile/>'),
			'string entity' => $parser->parse('<auditfile><!ENTITY x "y"></auditfile>'),
			'string malformed' => $parser->parse('<auditfile><company>'),
			'string bare' => $parser->parse('<auditfile/>'),
			'file two companies' => $parser->parseFile($this->file($ns . $this->company('1') . $this->company('2') . '</auditfile>')),
			'file no company' => $parser->parseFile($this->file($ns . '<header/></auditfile>')),
			'file unreadable' => $parser->parseFile('/nonexistent/xaf.xml'),
		];
		$outcomes['warnings'] = $warnings;

		// The libxml wording differs between builds; only its presence is pinned.
		$outcomes['string malformed']['findings'][0]['context']['detail'] = ($outcomes['string malformed']['findings'][0]['context']['detail'] !== '');
		$outcomes['warnings'][2][1]['detail'] = ($outcomes['warnings'][2][1]['detail'] !== '');
		$outcomes['file unreadable']['findings'][0]['context']['path'] = 'PATH';
		$outcomes['file no company']['findings'][0]['context']['path'] = 'PATH';

		return $outcomes;
	}//end outcomes()

	/**
	 * The outcomes match the snapshot taken before the parser was split.
	 *
	 * @return void
	 */
	public function testOutcomesMatchTheSnapshot(): void {
		$snapshot = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/auditfile-parser-golden.json'), true);
		self::assertSame($snapshot, json_decode((string)json_encode($this->outcomes(), JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutcomesMatchTheSnapshot()
}//end class
