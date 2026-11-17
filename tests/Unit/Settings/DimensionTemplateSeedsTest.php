<?php

/**
 * The cost-center and project templates satisfy the shipped AnalyticalDimension schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The repair step saves each template row as an AnalyticalDimension after
 * stamping its administrationId and dimensionType. A required property the
 * row lacks and the schema gives no default makes OpenRegister refuse the
 * save, so a fresh install gets no cost centres or projects.
 */
class DimensionTemplateSeedsTest extends TestCase {
	use ShippedRegisterAuthorization;

	/**
	 * Every template row carries every required property, or the schema defaults it.
	 *
	 * @return void
	 */
	public function testTemplateRowsCarryEveryRequiredProperty(): void {
		$schema = $this->shippedSchemas()['AnalyticalDimension'];
		$required = array_unique($schema['required'] ?? []);
		self::assertNotSame([], $required);

		$seeds = __DIR__.'/../../../lib/Settings/seeds/';
		$missing = [];
		foreach (['cost-center-templates.json' => 'costCenters', 'project-templates.json' => 'projects'] as $file => $key) {
			$rows = json_decode((string) file_get_contents($seeds.$file), true)[$key];
			self::assertNotEmpty($rows, "$file has no rows.");
			foreach ($rows as $row) {
				$row['administrationId'] = 'ADM-001';
				foreach ($required as $property) {
					$value = ($row[$property] ?? ($schema['properties'][$property]['default'] ?? null));
					if ($value === null) {
						$missing[] = "$file ".($row['code'] ?? '?').": $property";
					}
				}
			}
		}

		self::assertSame([], $missing);
	}//end testTemplateRowsCarryEveryRequiredProperty()
}//end class
