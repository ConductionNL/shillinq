<?php

/**
 * Unit tests for XbrlTaxonomyLookup: the taxonomy effective for a period.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XbrlTaxonomyLookup::class)]
final class XbrlTaxonomyLookupTest extends TestCase {

	private static function taxonomy(string $id, string $effective, ?string $expiry = null, array $entryPoints = ['kvk-jaarrekening'], string $status = 'active'): array {
		return ['taxonomyId' => $id, 'effectiveDate' => $effective, 'expiryDate' => $expiry, 'entryPoints' => $entryPoints, 'status' => $status];
	}

	public function testTheLatestEffectiveTaxonomyCarryingTheEntryPointWins(): void {
		$rows = [
			self::taxonomy('2024', '2024-01-01'),
			self::taxonomy('2025', '2025-03-01T00:00:00Z'),
			self::taxonomy('2026', '2026-01-01'),
			self::taxonomy('ob-only', '2025-06-01', null, ['belastingdienst-ob']),
			self::taxonomy('archived', '2025-09-01', null, ['kvk-jaarrekening'], 'archived'),
			'not a row',
		];

		$this->assertSame('2025', XbrlTaxonomyLookup::pick($rows, 'kvk-jaarrekening', '2025-01-01', '2025-12-31')['taxonomyId']);
		$this->assertSame('ob-only', XbrlTaxonomyLookup::pick($rows, 'belastingdienst-ob', '2025-07-01', '2025-09-30')['taxonomyId']);
	}

	public function testAnExpiredTaxonomyIsNotEffective(): void {
		$rows = [self::taxonomy('old', '2020-01-01', '2025-01-01')];

		$this->assertNull(XbrlTaxonomyLookup::pick($rows, 'kvk-jaarrekening', '2025-01-01', '2025-12-31'));
		$this->assertSame('old', XbrlTaxonomyLookup::pick($rows, 'kvk-jaarrekening', '2024-01-01', '2024-12-31')['taxonomyId']);
		$this->assertNull(XbrlTaxonomyLookup::pick([self::taxonomy('later', '2026-01-01')], 'kvk-jaarrekening', '2025-01-01', '2025-12-31'));
	}

	public function testItReadsTheRegistersTaxonomies(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->expects($this->once())->method('setSchema')->with('XBRLTaxonomy')->willReturnSelf();
		$objects->method('findAll')->willReturn([self::taxonomy('2025', '2025-01-01')]);

		$this->assertSame('2025', (new XbrlTaxonomyLookup($config, $objects))->effectiveFor('kvk-jaarrekening', '2025-01-01', '2025-12-31')['taxonomyId']);
	}

	public function testWithoutAnEntryPointOrPeriodNothingIsRead(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');
		$lookup = new XbrlTaxonomyLookup($this->createMock(IAppConfig::class), $objects);

		$this->assertNull($lookup->effectiveFor('', '2025-01-01', '2025-12-31'));
		$this->assertNull($lookup->effectiveFor('kvk-jaarrekening', '2025-01-01', ''));
	}
}
