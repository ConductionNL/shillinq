<?php

/**
 * A VATCalculationService over the shipped VAT tariff seed, for tests that
 * generate invoices (tax-vat-rates-and-deductibility task 2.2).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Tax\VatTariffResolver;
use OCA\Shillinq\Service\VATCalculationService;

/**
 * Gives a TestCase a VAT service whose tariffs are the shipped seed, plus a
 * retired 6 percent tariff that ended on 2018-12-31.
 */
trait SeededVatTariffs {

	/**
	 * The VAT service over the seeded tariffs.
	 *
	 * @return VATCalculationService
	 */
	protected function seededVat(): VATCalculationService {
		$seed    = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/seeds/btw-tariffs-2026.json'), true);
		$records = [];
		foreach ($seed['tariffs'] as $index => $tariff) {
			$records[] = $tariff + ['id' => 'tariff-' . $index];
		}

		$records[] = ['id' => 'retired', 'code' => 'low-2018', 'ratePercentage' => 6, 'effectiveFrom' => '1969-01-01', 'effectiveTo' => '2018-12-31'];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn($records);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new VATCalculationService(tariffs: new VatTariffResolver(objectService: $objects, registerLocation: $location));
	}//end seededVat()
}//end trait
