<?php

/**
 * Unit tests for IcpStatementPreparer: the ICP statement prepared from the
 * books, validated against the register fragment it is written to.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Icp;

use InvalidArgumentException;
use OCA\Shillinq\Service\Icp\IcpInvoiceSupplies;
use OCA\Shillinq\Service\Icp\IcpStatementFiledException;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The ICP statement is prepared from the books (REQ-ICR-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(IcpStatementPreparer::class)]
#[CoversClass(IcpStatementFiledException::class)]
#[UsesClass(IcpService::class)]
#[UsesClass(IcpCalculator::class)]
#[UsesClass(IcpInvoiceSupplies::class)]
#[UsesClass(ReverseChargeNotice::class)]
final class IcpStatementPreparerTest extends TestCase {

	use IcpObjectStore;

	/**
	 * The register fragment the statement schema lives in.
	 *
	 * @var string
	 */
	private const FRAGMENT = __DIR__ . '/../../../../lib/Settings/register.d/bookkeeping-icp-opgaaf.json';

	/**
	 * A posted intra-EU invoice.
	 *
	 * @param string $number The invoice number.
	 * @param string $buyer  The buyer's VAT number.
	 * @param float  $amount The net amount.
	 * @param string $date   The invoice date.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(string $number, string $buyer, float $amount, string $date): array {
		return [
			'invoiceNumber' => $number,
			'administrationId' => 'adm-1',
			'invoiceDate' => $date,
			'lifecycleState' => 'issued',
			'buyerVatId' => $buyer,
			'netAmount' => $amount,
			'invoiceLines' => [['netAmount' => $amount, 'vatCategory' => 'K']],
		];
	}//end invoice()

	/**
	 * The preparer over the rows, with the real ICP service.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return IcpStatementPreparer
	 */
	private function preparer(array $rows): IcpStatementPreparer {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$store = $this->objectStore(rows: $rows);
		$icp = new IcpService(
			appConfig: $config,
			calculator: new IcpCalculator(),
			objectService: $store,
			jurisdiction: $this->createMock(AdministrationJurisdiction::class),
		);

		return new IcpStatementPreparer(icpService: $icp, objectService: $store, appConfig: $config);
	}//end preparer()

	/**
	 * The books of the spec scenario: Q3 2026 supplies to a Belgian and a
	 * German business, an out-of-period supply, and a Q3 VAT return.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function q3Books(): array {
		return [
			'ARInvoice' => [
				self::invoice('2026-0101', 'BE0123456749', 12000.0, '2026-08-03'),
				self::invoice('2026-0102', 'DE123456789', 3000.0, '2026-09-12'),
				self::invoice('2026-0090', 'DE123456789', 999.0, '2026-06-30'),
			],
			'VatReturn' => [['administrationId' => 'adm-1', 'period' => '2026-Q3', 'rubriek3b' => 15000.4]],
		];
	}//end q3Books()

	/**
	 * Prepare for 2026-Q3 writes a draft of two lines and reconciles it with box 3b.
	 *
	 * @return void
	 */
	public function testPreparingTheThirdQuarterWritesTwoLinesAndReconciles(): void {
		$result = $this->preparer(rows: self::q3Books())->prepare(administrationId: 'adm-1', period: '2026-Q3');

		$this->assertCount(1, $this->saves);
		$this->assertSame('IcpOpgaaf', $this->saves[0]['schema']);
		$this->assertSame('shillinq', $this->saves[0]['register']);
		$this->assertNull($this->saves[0]['uuid']);
		$saved = $this->saves[0]['object'];
		$this->assertSame(
			[
				['buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12000.0],
				['buyerVatId' => 'DE123456789', 'supplyType' => 'L', 'amountExclVat' => 3000.0],
			],
			$saved['lines']
		);
		$this->assertSame(['draft', 'initial', 'quarterly', 15000.0], [$saved['status'], $saved['type'], $saved['periodicity'], $saved['total']]);
		$this->assertSame(15000.4, $result['reconciliation']['rubriek3b']);
		$this->assertTrue($result['reconciliation']['matches']);
		$this->assertSame(0.4, $result['reconciliation']['difference']);
		$this->assertFalse($result['replaced']);
		$this->assertSame('new-id', $result['statement']['@self']['id']);
	}//end testPreparingTheThirdQuarterWritesTwoLinesAndReconciles()

	/**
	 * Every field written is a property of the real IcpOpgaaf schema, its
	 * required fields are all there and every enum value is allowed.
	 *
	 * @return void
	 */
	public function testTheDraftIsValidAgainstTheRealFragment(): void {
		$this->preparer(rows: self::q3Books())->prepare(administrationId: 'adm-1', period: '2026-08');
		$schema = json_decode((string)file_get_contents(self::FRAGMENT), true)['components']['schemas']['IcpOpgaaf'];
		$saved = $this->saves[0]['object'];

		$this->assertSame([], array_values(array_diff(array_keys($saved), array_keys($schema['properties']))));
		$this->assertSame([], array_values(array_diff($schema['required'], array_keys($saved))));
		foreach ($saved as $field => $value) {
			if (isset($schema['properties'][$field]['enum']) === true) {
				$this->assertContains($value, $schema['properties'][$field]['enum'], $field);
			}
		}

		$lineSchema = $schema['properties']['lines']['items']['properties'];
		foreach ($saved['lines'] as $line) {
			$this->assertSame([], array_values(array_diff(array_keys($line), array_keys($lineSchema))));
			$this->assertContains($line['supplyType'], $lineSchema['supplyType']['enum']);
		}

		$this->assertSame('monthly', $saved['periodicity']);
		$this->assertSame([['buyerVatId' => 'BE0123456749', 'supplyType' => 'L', 'amountExclVat' => 12000.0]], $saved['lines']);
	}//end testTheDraftIsValidAgainstTheRealFragment()

	/**
	 * A draft of the period is replaced in place.
	 *
	 * @return void
	 */
	public function testADraftIsReplacedInPlace(): void {
		$books = self::q3Books();
		$books['IcpOpgaaf'] = [
			['@self' => ['id' => 'stmt-1'], 'administrationId' => 'adm-1', 'period' => '2026-Q3', 'status' => 'draft', 'type' => 'initial'],
			['@self' => ['id' => 'corr-1'], 'administrationId' => 'adm-1', 'period' => '2026-Q3', 'status' => 'submitted', 'type' => 'correction'],
		];

		$result = $this->preparer(rows: $books)->prepare(administrationId: 'adm-1', period: '2026-Q3');

		$this->assertSame('stmt-1', $this->saves[0]['uuid']);
		$this->assertTrue($result['replaced']);
	}//end testADraftIsReplacedInPlace()

	/**
	 * A filed statement is never replaced, even beside a draft.
	 *
	 * @return void
	 */
	public function testAFiledStatementIsNotReplaced(): void {
		$books = self::q3Books();
		$books['IcpOpgaaf'] = [
			['@self' => ['id' => 'stmt-0'], 'administrationId' => 'adm-1', 'period' => '2026-Q3', 'status' => 'draft'],
			['@self' => ['id' => 'stmt-1'], 'administrationId' => 'adm-1', 'period' => '2026-Q3', 'status' => 'submitted', 'type' => 'initial'],
		];

		try {
			$this->preparer(rows: $books)->prepare(administrationId: 'adm-1', period: '2026-Q3');
			$this->fail('A filed statement was replaced.');
		} catch (IcpStatementFiledException $e) {
			$this->assertSame('The ICP statement of 2026-Q3 is submitted and is not replaced. File a correction instead.', $e->getMessage());
			$this->assertSame(['2026-Q3', 'submitted'], [$e->period, $e->status]);
		}

		$this->assertSame([], $this->saves);
	}//end testAFiledStatementIsNotReplaced()

	/**
	 * A period that is neither a quarter nor a month is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAPeriodThatIsNoQuarterOrMonthIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The period must be a quarter (2026-Q3) or a month (2026-07).');

		$this->preparer(rows: [])->prepare(administrationId: 'adm-1', period: '2026');
	}//end testAPeriodThatIsNoQuarterOrMonthIsRefused()

	/**
	 * The id of a record: @self id, else id, else uuid.
	 *
	 * @return void
	 */
	public function testTheIdOfARecord(): void {
		$preparer = $this->preparer(rows: []);
		$this->assertSame('a', $preparer->idOf(record: ['@self' => ['id' => 'a'], 'id' => 'b']));
		$this->assertSame('b', $preparer->idOf(record: ['id' => 'b', 'uuid' => 'c']));
		$this->assertSame('c', $preparer->idOf(record: ['uuid' => 'c']));
		$this->assertSame('', $preparer->idOf(record: []));
	}//end testTheIdOfARecord()
}//end class
