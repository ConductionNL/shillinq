<?php

/**
 * Unit tests for the edges of the usage-billing services.
 *
 * Pins what the golden and calculator tests leave open: the import's refusal
 * reasons for a row without a customer or resource type or with a broken
 * period, the billability check of a reading, which invoice lines mark a
 * reading invoiced, and that a reading without a rate plan reads none.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Usage
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Usage;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCA\Shillinq\Service\Usage\MeterReadingRating;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Edges of the meter-reading import, billing and rating services.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
class UsageEdgeCasesTest extends TestCase {

	/**
	 * The register location, answering `shillinq`.
	 *
	 * @return RegisterLocation
	 */
	private function location(): RegisterLocation {
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		return $location;
	}//end location()

	/**
	 * A complete import row with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Field overrides; null removes a field.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $overrides = []): array {
		$row = array_merge(
			['customerId' => 'c-1', 'resourceType' => 'kwh', 'quantity' => '2', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'],
			$overrides
		);
		return array_filter($row, static fn ($value): bool => $value !== null);
	}//end row()

	/**
	 * Every malformed row is refused with its own reason and nothing is saved.
	 *
	 * @return void
	 */
	public function testTheImportRefusesEachMalformedRowWithItsReason(): void {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnSelf();
		$store->expects($this->never())->method('saveObject');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$result = (new MeterReadingImportService($store, $this->location(), $l10n))->import(
			administrationId: 'adm-1',
			rows: [
				$this->row(['customerId' => null]),
				$this->row(['resourceType' => null]),
				$this->row(['periodStart' => '01-01-2026']),
				$this->row(['periodEnd' => '2026-02-30x']),
				$this->row(['periodStart' => '2026-02-01', 'periodEnd' => '2026-01-31']),
			]
		);

		$this->assertSame(
			[
				'created' => [],
				'refused' => [
					['row' => 1, 'reason' => 'The customer is missing.'],
					['row' => 2, 'reason' => 'The resource type is missing.'],
					['row' => 3, 'reason' => 'The period start is not a date (YYYY-MM-DD).'],
					['row' => 4, 'reason' => 'The period end is not a date (YYYY-MM-DD).'],
					['row' => 5, 'reason' => 'The period ends before it starts.'],
				],
			],
			$result
		);
	}//end testTheImportRefusesEachMalformedRowWithItsReason()

	/**
	 * Only a rated reading is billable; an invoiced one is a conflict.
	 *
	 * @return void
	 */
	public function testOnlyARatedReadingIsBillable(): void {
		$billed = new BilledReadings(
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(ObjectTransitionRunner::class),
			$this->location()
		);

		$billed->assertBillable(['status' => 'rated'], 'mr-1');

		foreach ([
			[['status' => 'invoiced'], 'Conflict: meter reading mr-2 is already invoiced'],
			[['status' => 'unrated'], 'Meter reading mr-2 is not rated yet'],
			[[], 'Meter reading mr-2 is not rated yet'],
		] as [$reading, $message]) {
			try {
				$billed->assertBillable($reading, 'mr-2');
				$this->fail('Expected a refusal for ' . json_encode($reading));
			} catch (RuntimeException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testOnlyARatedReadingIsBillable()

	/**
	 * Only usage lines that name a reading mark that reading invoiced.
	 *
	 * @return void
	 */
	public function testOnlyUsageLinesWithAReadingAreMarkedInvoiced(): void {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->with('shillinq')->willReturnSelf();
		$store->method('setSchema')->with('MeterReading')->willReturnSelf();
		$patched = [];
		$store->method('patchObject')->willReturnCallback(
			function (string $id, array $data, $register = null, $schema = null) use (&$patched): ObjectEntityInterface {
				$patched[] = [$id, $data, $register, $schema];
				return $this->createStub(ObjectEntityInterface::class);
			}
		);
		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$ran = [];
		$transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use (&$ran): void {
				$ran[] = [$objectId, $action];
			}
		);

		(new BilledReadings($store, $transitions, $this->location()))->markInvoiced(
			[
				['sourceType' => 'usage', 'sourceId' => 'mr-1'],
				['sourceType' => 'time', 'sourceId' => 'te-1'],
				['sourceType' => 'usage', 'sourceId' => ''],
				['description' => 'Handmatige regel'],
				['sourceType' => 'usage', 'sourceId' => 'mr-2'],
			],
			'inv-9'
		);

		$this->assertSame(
			[
				['mr-1', ['invoiceId' => 'inv-9'], 'shillinq', 'MeterReading'],
				['mr-2', ['invoiceId' => 'inv-9'], 'shillinq', 'MeterReading'],
			],
			$patched
		);
		$this->assertSame([['mr-1', 'invoice'], ['mr-2', 'invoice']], $ran);
	}//end testOnlyUsageLinesWithAReadingAreMarkedInvoiced()

	/**
	 * A reading without a rate plan has none, and nothing is looked up.
	 *
	 * @return void
	 */
	public function testAReadingWithoutARatePlanHasNone(): void {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->expects($this->never())->method('setRegister');
		$store->expects($this->never())->method('find');

		$rating = new MeterReadingRating($store, $this->location(), $this->createMock(UsageRatingCalculator::class));

		$this->assertNull($rating->planFor(['administrationId' => 'adm-1']));
		$this->assertNull($rating->planFor(['administrationId' => 'adm-1', 'ratePlanId' => '']));
	}//end testAReadingWithoutARatePlanHasNone()
}//end class
