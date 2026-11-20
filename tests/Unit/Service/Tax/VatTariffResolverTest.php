<?php

/**
 * VatTariffResolver: the dated lookup over the seeded tariffs and the legacy codes.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Tax\VatTariffRefusedException;
use OCA\Shillinq\Service\Tax\VatTariffResolver;
use OCA\Shillinq\Service\VATCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * The records are the shipped seed, plus one retired tariff.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\Tax\VatTariffResolver
 * @covers \OCA\Shillinq\Service\Tax\VatTariffRefusedException
 * @covers \OCA\Shillinq\Service\VATCalculationService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class VatTariffResolverTest extends TestCase {

	/**
	 * The resolver over the seeded tariffs and a retired 6 % tariff.
	 *
	 * @param int $reads How often the register may be read.
	 *
	 * @return VatTariffResolver
	 */
	private function resolver(int $reads = 1): VatTariffResolver {
		$seed    = json_decode((string)file_get_contents(dirname(__DIR__, 4).'/lib/Settings/seeds/btw-tariffs-2026.json'), true);
		$records = [];
		foreach ($seed['tariffs'] as $index => $tariff) {
			$records[] = $tariff + ['id' => 'tariff-'.$index];
		}

		$records[] = ['id' => 'retired', 'code' => 'low-2018', 'ratePercentage' => 6, 'effectiveFrom' => '1969-01-01', 'effectiveTo' => '2018-12-31'];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->exactly($reads))->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->method('setSchema')->with('VatTariff')->willReturnSelf();
		$objects->method('findAll')->willReturn($records);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new VatTariffResolver(objectService: $objects, registerLocation: $location);
	}//end resolver()

	public function testEachSeededTariffResolvesToItsRate(): void {
		$resolver = $this->resolver();
		$expected = ['high' => 21.0, 'low' => 9.0, 'zero' => 0.0, 'exempt' => 0.0, 'reverse-charge' => 0.0, 'export' => 0.0];
		foreach ($expected as $code => $rate) {
			$this->assertSame($rate, $resolver->rateFor(code: $code, date: '2026-10-05'), $code);
		}

		$this->assertTrue($resolver->forCode(code: 'reverse-charge', date: '2026-10-05')['reverseCharge']);
	}//end testEachSeededTariffResolvesToItsRate()

	public function testTheLegacyCodesResolveToTheSeededTariffs(): void {
		$resolver = $this->resolver();

		$this->assertSame('high', $resolver->forCode(code: 'BTW21', date: '2026-10-05')['code']);
		$this->assertSame('low', $resolver->forCode(code: 'btw9', date: '2026-10-05')['code']);
		$this->assertSame('zero', $resolver->forCode(code: 'BTW0', date: '2026-10-05')['code']);
		$this->assertSame('reverse-charge', $resolver->forCode(code: 'VERLEGD', date: new DateTimeImmutable('2026-10-05'))['code']);
	}//end testTheLegacyCodesResolveToTheSeededTariffs()

	public function testATariffBeforeItsStartIsRefusedNamingTheCodeAndTheDate(): void {
		$this->expectException(VatTariffRefusedException::class);
		$this->expectExceptionMessage('VAT tariff "low" is not valid on the document date 2018-06-30.');

		$this->resolver()->forCode(code: 'low', date: '2018-06-30');
	}//end testATariffBeforeItsStartIsRefusedNamingTheCodeAndTheDate()

	public function testARetiredTariffIsRefusedAfterItsEndAndValidOnItsLastDay(): void {
		$resolver = $this->resolver();
		$this->assertSame(6.0, $resolver->rateFor(code: 'low-2018', date: '2018-12-31'));

		try {
			$resolver->rateFor(code: 'low-2018', date: '2026-10-05');
			$this->fail('A retired tariff was accepted.');
		} catch (VatTariffRefusedException $e) {
			$this->assertSame('low-2018', $e->tariffCode);
			$this->assertSame('2026-10-05', $e->date);
			$this->assertSame('expired', $e->why);
		}
	}//end testARetiredTariffIsRefusedAfterItsEndAndValidOnItsLastDay()

	public function testAnUnknownCodeIsRefusedWithoutAFallbackRate(): void {
		$this->expectException(VatTariffRefusedException::class);
		$this->expectExceptionMessage('VAT tariff "BTW6" does not exist (document date 2026-10-05).');

		$this->resolver()->rateFor(code: 'BTW6', date: '2026-10-05');
	}//end testAnUnknownCodeIsRefusedWithoutAFallbackRate()

	public function testTheRegisterIsReadOncePerResolver(): void {
		$resolver = $this->resolver(reads: 1);
		$resolver->rateFor(code: 'high', date: '2026-10-05');
		$resolver->rateFor(code: 'low', date: '2026-10-05');

		$this->assertSame(21.0, VatTariffResolver::percentageOf(['rate' => 0.21]));
	}//end testTheRegisterIsReadOncePerResolver()

	public function testAMixedRateInvoiceIsTotalledFromTheTariffs(): void {
		$vat = new VATCalculationService(tariffs: $this->resolver());

		$totals = $vat->calculateVAT([
			['costAmountCents' => 100000, 'vatTariff' => 'high', 'documentDate' => '2026-10-05'],
			['costAmountCents' => 5000, 'vatTariff' => 'low', 'documentDate' => '2026-10-05'],
		]);

		$this->assertSame(21450, $totals['vatCents']);
		$this->assertSame([21000, 450], array_column($totals['breakdown'], 'vatCents'));
	}//end testAMixedRateInvoiceIsTotalledFromTheTariffs()

	public function testALineNamingATariffIsRefusedWithoutADateOrAResolver(): void {
		$line = ['costAmountCents' => 100, 'vatTariff' => 'high'];

		try {
			(new VATCalculationService(tariffs: $this->resolver(reads: 0)))->calculateVAT([$line]);
			$this->fail('A line without a document date was totalled.');
		} catch (VatTariffRefusedException $e) {
			$this->assertSame('high', $e->tariffCode);
		}

		$this->expectException(VatTariffRefusedException::class);
		(new VATCalculationService())->calculateVAT([$line + ['documentDate' => '2026-10-05']]);
	}//end testALineNamingATariffIsRefusedWithoutADateOrAResolver()
}//end class
