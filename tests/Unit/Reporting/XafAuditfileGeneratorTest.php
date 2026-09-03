<?php

/**
 * Unit tests for XafAuditfileGenerator (Dutch Auditfile Financieel XAF 3.2).
 *
 * Proves REQ-MA-011: the generator emits a schema-valid, correctly-namespaced
 * XAF 3.2 document (validated against tests/schemas/xaf-3.2-min.xsd), that it is
 * the Dutch XAF and NOT the OECD SAF-T, and that it enforces administrationId
 * data isolation (no foreign administration's account, relation, or journal line
 * leaks into the file). A fake ObjectService feeds fixture rows; it deliberately
 * IGNORES the query filters so the assertion proves the generator's OWN in-PHP
 * scope guard, not the data layer's.
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
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\SaftReportGenerator;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A fake OpenRegister ObjectService returning fixture rows per schema.
 *
 * It ignores the passed filters on purpose: the generator must enforce its own
 * administrationId isolation even against a filter-ignoring data layer.
 */
final class FakeXafObjectService {

	/**
	 * The current schema selected by setSchema().
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Fixture rows keyed by schema name.
	 *
	 * @param array<string, array<int, array<string,mixed>>> $bySchema Rows per schema.
	 */
	public function __construct(
		private array $bySchema,
	) {

	}//end __construct()

	/**
	 * @param string $register Ignored.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * @param string $schema The schema to read next.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $config Ignored on purpose.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		return ($this->bySchema[$this->schema] ?? []);
	}//end findAll()
}//end class

/**
 * A minimal PSR container resolving only the OpenRegister ObjectService.
 */
final class FakeXafContainer implements ContainerInterface {

	/**
	 * @param FakeXafObjectService $objectService The fake ObjectService.
	 */
	public function __construct(
		private FakeXafObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * @param string $id Service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		if ($id === 'OCA\OpenRegister\Service\ObjectService') {
			return $this->objectService;
		}

		throw new \RuntimeException('Unknown service: ' . $id);
	}//end get()

	/**
	 * @param string $id Service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return $id === 'OCA\OpenRegister\Service\ObjectService';
	}//end has()
}//end class

/**
 * Tests the XAF 3.2 generator.
 */
final class XafAuditfileGeneratorTest extends TestCase {

	/**
	 * Two-administration fixture: WERK-001 (in scope) + WERK-002 (foreign).
	 *
	 * @return array<string, array<int, array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'Account' => [
				['accountNumber' => '1300', 'name' => 'Debiteuren', 'accountType' => 'asset', 'administrationId' => 'WERK-001'],
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'WERK-001'],
				['accountNumber' => '9999', 'name' => 'Foreign account', 'accountType' => 'asset', 'administrationId' => 'WERK-002'],
			],
			// Written in the vocabulary CustomerMaster actually DECLARES —
			// customerId / legalName / vatID / telephone. The fixture used to say
			// customerNumber / name / vatNumber / phone, which is Payee's
			// vocabulary, so it agreed with the generator's bug instead of
			// catching it: no CustomerMaster row can ever carry those keys,
			// because the schema does not declare them and OpenRegister drops
			// what it cannot store.
			'CustomerMaster' => [
				[
					'customerId' => 'D001',
					'legalName' => 'Acme B.V.',
					'kvkNumber' => '87654321',
					'vatID' => 'NL876543210B01',
					'email' => 'facturen@acme.nl',
					'telephone' => '+31201234567',
					'administrationId' => 'WERK-001',
				],
				['customerId' => 'DX', 'legalName' => 'Foreign customer', 'administrationId' => 'WERK-002'],
			],
			'Payee' => [
				['vendorNumber' => 'C001', 'name' => 'Leverancier XYZ B.V.', 'kvkNumber' => '11223344', 'administrationId' => 'WERK-001'],
				['vendorNumber' => 'CX', 'name' => 'Foreign supplier', 'administrationId' => 'WERK-002'],
			],
			'GLTransaction' => [
				['id' => 't1', 'transactionNumber' => '2026-0001', 'postingDate' => '2026-03-01', 'description' => 'Sale', 'administrationId' => 'WERK-001'],
				['id' => 't2', 'transactionNumber' => '2026-9999', 'postingDate' => '2026-03-02', 'description' => 'Foreign', 'administrationId' => 'WERK-002'],
			],
			'GLLine' => [
				['transactionId' => 't1', 'accountNumber' => '1300', 'amount' => 1210.00, 'side' => 'debit', 'lineNumber' => '1'],
				['transactionId' => 't1', 'accountNumber' => '8000', 'amount' => 1000.00, 'side' => 'credit', 'lineNumber' => '2'],
				['transactionId' => 't2', 'accountNumber' => '9999', 'amount' => 500.00, 'side' => 'debit', 'lineNumber' => '1'],
			],
		];
	}//end fixture()

	/**
	 * Build a generator wired to the given per-schema fixture.
	 *
	 * @param array<string, array<int, array<string,mixed>>> $bySchema Rows per schema.
	 *
	 * @return XafAuditfileGenerator
	 */
	private function generator(array $bySchema): XafAuditfileGenerator {
		$container = new FakeXafContainer(new FakeXafObjectService($bySchema));
		return new XafAuditfileGenerator($container, new NullLogger());
	}//end generator()

	/**
	 * HAPPY: the export is schema-valid XAF 3.2 with the mandatory blocks.
	 *
	 * @return void
	 */
	public function testGeneratesSchemaValidNamespacedXaf(): void {
		$rendered = $this->generator($this->fixture())->generate(['administrationId' => 'WERK-001', 'period' => '2026'], 'xml');

		$this->assertSame('xml', $rendered->format);
		$this->assertStringEndsWith('.xml', $rendered->fileName);

		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($rendered->content), 'XAF output is not well-formed XML');

		$this->assertSame('auditfile', $dom->documentElement->localName);
		$this->assertSame(XafAuditfileGenerator::XAF_NS, $dom->documentElement->namespaceURI);

		libxml_use_internal_errors(true);
		$valid = $dom->schemaValidate(__DIR__ . '/../../schemas/xaf-3.2-min.xsd');
		$errors = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
		libxml_clear_errors();
		$this->assertTrue($valid, 'XAF is not schema-valid: ' . implode('; ', $errors));

		// Mandatory blocks present.
		foreach (['header', 'company', 'generalLedger', 'customersSuppliers', 'transactions'] as $block) {
			$this->assertGreaterThan(0, $dom->getElementsByTagNameNS(XafAuditfileGenerator::XAF_NS, $block)->length, $block . ' block missing');
		}
	}//end testGeneratesSchemaValidNamespacedXaf()

	/**
	 * The generator declares the Dutch XAF 3.2 namespace, not OECD SAF-T.
	 *
	 * @return void
	 */
	public function testNamespaceIsXafNotSaft(): void {
		$rendered = $this->generator($this->fixture())->generate(['administrationId' => 'WERK-001', 'period' => '2026'], 'xml');

		$this->assertStringContainsString('http://www.auditfiles.nl/XAF/3.2', $rendered->content);
		$this->assertStringNotContainsString('urn:OECD:StandardAuditFile-Tax:2.00', $rendered->content);

		// The two generators are distinct, coexisting report ids.
		$this->assertSame('xaf', XafAuditfileGenerator::reportType());
		$this->assertSame('saft', SaftReportGenerator::reportType());
	}//end testNamespaceIsXafNotSaft()

	/**
	 * Administration isolation: only WERK-001 rows appear; no WERK-002 leaks.
	 *
	 * @return void
	 */
	public function testAdministrationDataIsolation(): void {
		$rendered = $this->generator($this->fixture())->generate(['administrationId' => 'WERK-001', 'period' => '2026'], 'xml');
		$content = $rendered->content;

		// In-scope WERK-001 data is present.
		$this->assertStringContainsString('<accID>1300</accID>', $content);
		$this->assertStringContainsString('<custSupID>D001</custSupID>', $content);
		$this->assertStringContainsString('<nr>2026-0001</nr>', $content);

		// No WERK-002 account, relation, transaction or line leaked.
		$this->assertStringNotContainsString('9999', $content);
		$this->assertStringNotContainsString('Foreign', $content);
		$this->assertStringNotContainsString('<custSupID>DX</custSupID>', $content);
		$this->assertStringNotContainsString('<custSupID>CX</custSupID>', $content);

		// Every emitted account belongs to WERK-001 (the only accIDs are 1300/8000).
		preg_match_all('/<accID>([^<]+)<\/accID>/', $content, $matches);
		foreach (array_unique($matches[1]) as $accId) {
			$this->assertContains($accId, ['1300', '8000'], 'Foreign accID leaked: ' . $accId);
		}
	}//end testAdministrationDataIsolation()

	/**
	 * EDGE: with no rows the file is still schema-valid with empty containers.
	 *
	 * @return void
	 */
	public function testEmptyBlocksAreWellFormed(): void {
		$rendered = $this->generator([])->generate(['administrationId' => 'WERK-EMPTY', 'period' => '2026'], 'xml');

		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($rendered->content), 'Empty XAF is not well-formed');

		libxml_use_internal_errors(true);
		$valid = $dom->schemaValidate(__DIR__ . '/../../schemas/xaf-3.2-min.xsd');
		libxml_clear_errors();
		$this->assertTrue($valid, 'Empty XAF is not schema-valid');

		// Containers exist but carry no rows.
		$this->assertGreaterThan(0, $dom->getElementsByTagNameNS(XafAuditfileGenerator::XAF_NS, 'generalLedger')->length);
		$this->assertSame(0, $dom->getElementsByTagNameNS(XafAuditfileGenerator::XAF_NS, 'ledgerAccount')->length);
	}//end testEmptyBlocksAreWellFormed()

	/**
	 * REGRESSION: a CustomerMaster row reaches the XAF in ITS OWN vocabulary.
	 *
	 * `writeCustomerSupplier()` is shared with Payee, and Payee declares
	 * vendorNumber / name / vatNumber / phone. CustomerMaster declares
	 * customerId / legalName / vatID / telephone, and nothing else can ever
	 * come out of the register for a customer: OpenRegister writes only the
	 * properties the schema declares, so a read of `customerNumber` is a read
	 * of a key no CustomerMaster row can hold. `custSupID` was therefore always
	 * empty, and the empty-id guard at the top of writeCustomerSupplier()
	 * returned before writing anything — every AR customer was missing from the
	 * audit file, silently, on a run that reported success.
	 *
	 * @return void
	 */
	public function testCustomerMasterVocabularyReachesTheAuditFile(): void {
		$rendered = $this->generator($this->fixture())->generate(['administrationId' => 'WERK-001', 'period' => '2026'], 'xml');
		$content = $rendered->content;

		$this->assertStringContainsString('<custSupID>D001</custSupID>', $content);
		$this->assertStringContainsString('<companyName>Acme B.V.</companyName>', $content);
		$this->assertStringContainsString('<taxRegIdent>NL876543210B01</taxRegIdent>', $content);
		$this->assertStringContainsString('<telephone>+31201234567</telephone>', $content);

		// The supplier half keeps working: Payee's vocabulary is untouched.
		$this->assertStringContainsString('<custSupID>C001</custSupID>', $content);
	}//end testCustomerMasterVocabularyReachesTheAuditFile()
}//end class
