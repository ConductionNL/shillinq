<?php

/**
 * Unit tests for VatLineStamper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tariff codes from invoice lines, the return box each line belongs in, and
 * the VAT arithmetic.
 *
 * @covers \OCA\Shillinq\Service\Vat\VatLineStamper
 */
final class VatLineStamperTest extends TestCase {

	/**
	 * The tariffs the register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TARIFFS = [
		['id' => 't1', 'code' => 'high', 'section' => '1a', 'rate' => 21],
		['id' => 't2', 'code' => 'low', 'section' => '1b', 'ratePercentage' => '0.09'],
		['id' => 't3', 'code' => 'reverse-charge-purchase', 'section' => '2a', 'reverseCharge' => true, 'rate' => 21],
		['id' => 't4', 'code' => '', 'section' => '9z'],
	];

	/**
	 * Each sale line and its tariff.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function saleLines(): array {
		return [
			'zero category' => [['vatCategory' => ' z '], 'zero'],
			'exempt' => [['vatCategory' => 'E'], 'exempt'],
			'reverse charge' => [['vatCategory' => 'AE'], 'reverse-charge-supply'],
			'intra-EU' => [['vatCategory' => 'K'], 'intra-eu-supply'],
			'export' => [['vatCategory' => 'G'], 'export'],
			'standard 21' => [['vatCategory' => 'S', 'vatRate' => 21], 'high'],
			'standard 9 as a fraction' => [['vatCategory' => 'S', 'vatRate' => '0.09'], 'low'],
			'standard 21.00' => [['vatCategory' => 'S', 'vatRate' => '21.00'], 'high'],
			'standard at an unknown rate' => [['vatCategory' => 'S', 'vatRate' => 6], ''],
			'standard without a rate' => [['vatCategory' => 'S'], ''],
			'unknown category' => [['vatCategory' => 'X', 'vatRate' => 21], ''],
			'no category' => [[], ''],
		];
	}//end saleLines()

	/**
	 * A sale line's category, or its standard rate, names its tariff.
	 *
	 * @param array<string, mixed> $line     The line.
	 * @param string               $expected The tariff.
	 *
	 * @dataProvider saleLines
	 *
	 * @return void
	 */
	public function testSaleTariff(array $line, string $expected): void {
		$this->assertSame($expected, $this->stamper()->saleTariff($line));
	}//end testSaleTariff()

	/**
	 * Legacy purchase codes map to tariffs; anything else is taken as it is.
	 *
	 * @return void
	 */
	public function testPurchaseTariff(): void {
		$stamper = $this->stamper();

		$this->assertSame('high', $stamper->purchaseTariff(['taxCode' => 'btw21']));
		$this->assertSame('low', $stamper->purchaseTariff(['taxCode' => ' BTW9 ']));
		$this->assertSame('zero', $stamper->purchaseTariff(['taxCode' => 'BTW0']));
		$this->assertSame('reverse-charge-purchase', $stamper->purchaseTariff(['taxCode' => 'reverse-charge-purchase']));
		$this->assertSame('', $stamper->purchaseTariff([]));
	}//end testPurchaseTariff()

	/**
	 * The return box follows the tariff's section, input VAT goes to 5b, and
	 * a purchase base line has no box unless it is reverse-charged.
	 *
	 * @return void
	 */
	public function testStampNamesTheReturnBox(): void {
		$stamper = $this->stamper();

		$this->assertSame([], $stamper->stamp('', 'base', false));
		$this->assertSame(['vatTariffCode' => 'high', 'vatAmountKind' => 'base', 'vatReturnBox' => '1a'], $stamper->stamp('high', 'base', false));
		$this->assertSame(['vatTariffCode' => 'high', 'vatAmountKind' => 'vat', 'vatReturnBox' => '1a'], $stamper->stamp('high', 'vat', false));
		$this->assertSame(['vatTariffCode' => 'high', 'vatAmountKind' => 'vat', 'vatReturnBox' => '5b'], $stamper->stamp('high', 'vat', true));
		$this->assertSame(['vatTariffCode' => 'high', 'vatAmountKind' => 'base'], $stamper->stamp('high', 'base', true));
		$this->assertSame(
			['vatTariffCode' => 'reverse-charge-purchase', 'vatAmountKind' => 'base', 'vatReturnBox' => '2a'],
			$stamper->stamp('reverse-charge-purchase', 'base', true)
		);
		$this->assertSame(['vatTariffCode' => 'unknown', 'vatAmountKind' => 'base'], $stamper->stamp('unknown', 'base', false));
	}//end testStampNamesTheReturnBox()

	/**
	 * Reverse charge and rates are read from the tariff.
	 *
	 * @return void
	 */
	public function testTariffFacts(): void {
		$stamper = $this->stamper();

		$this->assertTrue($stamper->isReverseCharge('reverse-charge-purchase'));
		$this->assertFalse($stamper->isReverseCharge('high'));
		$this->assertFalse($stamper->isReverseCharge('unknown'));
		$this->assertSame(21.0, $stamper->ratePercentage('high'));
		$this->assertSame(9.0, $stamper->ratePercentage('low'));
		$this->assertNull($stamper->ratePercentage('unknown'));
	}//end testTariffFacts()

	/**
	 * Tariffs are read once; a store that cannot be read gives no tariffs, so
	 * lines are stamped without a box.
	 *
	 * @return void
	 */
	public function testTariffsAreReadOnceAndAFailedReadGivesNone(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->with('shillinq')->willReturnSelf();
		$service->method('setSchema')->with('VatTariff')->willReturnSelf();
		$service->expects($this->once())->method('findAll')->with(['limit' => 500])
			->willThrowException(new RuntimeException('db down'));

		$stamper = new VatLineStamper(objectService: $service, register: 'shillinq');

		$this->assertSame(['vatTariffCode' => 'high', 'vatAmountKind' => 'vat'], $stamper->stamp('high', 'vat', false));
		$this->assertNull($stamper->ratePercentage('high'));
	}//end testTariffsAreReadOnceAndAFailedReadGivesNone()

	/**
	 * VAT in cents from a percentage or a fraction; no rate, no amount.
	 *
	 * @return void
	 */
	public function testVatCents(): void {
		$stamper = $this->stamper();

		$this->assertSame(2100.0, $stamper->vatCents(10000, 21));
		$this->assertSame(900.0, $stamper->vatCents(10000, '0.09'));
		$this->assertSame(0.0, $stamper->vatCents(10000, 0));
		$this->assertNull($stamper->vatCents(10000, 'n/a'));
	}//end testVatCents()

	/**
	 * The VAT total is split over the groups, the rounding difference landing
	 * on the group with the largest base.
	 *
	 * @return void
	 */
	public function testSplitVat(): void {
		$stamper = $this->stamper();

		$this->assertSame([], $stamper->splitVat([], 0));
		$this->assertSame(['' => 125], $stamper->splitVat([], 125));
		$this->assertSame(
			['high' => 2101, 'low' => 45],
			$stamper->splitVat(['high' => ['base' => 10000, 'vat' => 2100.4], 'low' => ['base' => 500, 'vat' => 45.4]], 2146)
		);
		$this->assertSame(
			['high' => 2100, 'low' => -1845],
			$stamper->splitVat(['high' => ['base' => 10000, 'vat' => 2100.0], 'low' => ['base' => -20000, 'vat' => -1800.0]], 255)
		);
	}//end testSplitVat()

	/**
	 * A stamper over the in-memory tariffs.
	 *
	 * @return VatLineStamper
	 */
	private function stamper(): VatLineStamper {
		return new VatLineStamper(
			objectService: new InMemoryObjectServiceStub(['VatTariff' => self::TARIFFS], findAllRendersEntities: true),
			register: 'shillinq'
		);
	}//end stamper()
}//end class
