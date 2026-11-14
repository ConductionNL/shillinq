<?php

/**
 * Golden test for MeterReadingImportService::import: the calls it makes and what it reports per row.
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
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rows are refused or created in order; the rate plan is looked up once per resource type.
 */
class MeterReadingImportGoldenTest extends TestCase {

	/**
	 * The calls made, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The importer over a store with one plan for "kwh", two for "gas" and none for "water".
	 *
	 * @return MeterReadingImportService
	 */
	private function importer(): MeterReadingImportService {
		$schema = '';
		$saved  = 0;
		$store  = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			static function (string|int $name) use (&$schema, $store): ObjectServiceInterface {
				$schema = (string)$name;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$schema): array {
				$this->calls[] = ['findAll', $schema, $config];
				$plans         = ['kwh' => [['id' => 'plan-kwh']], 'gas' => [['id' => 'g1'], ['id' => 'g2']]];
				return ($plans[$config['filters']['resourceType']] ?? []);
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$schema, &$saved): ObjectEntityInterface {
				$this->calls[] = ['saveObject', $schema, $object];
				if (($object['meterId'] ?? '') === 'refused') {
					throw new RuntimeException('duplicate meter');
				}

				$saved++;
				$entity = $this->createStub(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object + ['id' => 'mr-'.$saved]);
				$entity->method('getUuid')->willReturn('mr-'.$saved);
				return $entity;
			}
		);
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new MeterReadingImportService($store, $settings, $l10n);

	}//end importer()

	/**
	 * A valid row.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $extra = []): array {
		return $extra + ['customerId' => 'c-1', 'resourceType' => 'kwh', 'quantity' => '1,5', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'];

	}//end row()

	/**
	 * The full result and every call.
	 *
	 * @return void
	 */
	public function testImportGolden(): void {
		$result = $this->importer()->import(
			administrationId: 'adm-1',
			rows: [
				7 => $this->row(),
				3 => $this->row(['meterId' => 'm-2']),
				9 => $this->row(['quantity' => 'x']),
				2 => $this->row(['resourceType' => 'gas']),
				4 => $this->row(['resourceType' => 'water', 'ratePlanId' => 'own']),
				5 => $this->row(['resourceType' => 'water']),
				6 => $this->row(['meterId' => 'refused']),
				8 => $this->row(['resourceType' => 'gas']),
			]
		);

		$this->assertSame(
			[
				'created' => ['mr-1', 'mr-2', 'mr-3', 'mr-4', 'mr-5', 'mr-6'],
				'refused' => [
					['row' => 3, 'reason' => 'The quantity is not a number.'],
					['row' => 7, 'reason' => 'The register refused the row: duplicate meter'],
				],
			],
			$result
		);

		$reading = static fn (array $extra): array => ['administrationId' => 'adm-1', 'status' => 'unrated'] + $extra;
		$this->assertSame(
			[
				['findAll', 'UsageRatePlan', ['filters' => ['administrationId' => 'adm-1', 'resourceType' => 'kwh'], 'limit' => 2]],
				['saveObject', 'MeterReading', $reading(['customerId' => 'c-1', 'resourceType' => 'kwh', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'ratePlanId' => 'plan-kwh'])],
				['saveObject', 'MeterReading', $reading(['meterId' => 'm-2', 'customerId' => 'c-1', 'resourceType' => 'kwh', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'ratePlanId' => 'plan-kwh'])],
				['findAll', 'UsageRatePlan', ['filters' => ['administrationId' => 'adm-1', 'resourceType' => 'gas'], 'limit' => 2]],
				['saveObject', 'MeterReading', $reading(['customerId' => 'c-1', 'resourceType' => 'gas', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'])],
				['saveObject', 'MeterReading', $reading(['customerId' => 'c-1', 'resourceType' => 'water', 'ratePlanId' => 'own', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'])],
				['findAll', 'UsageRatePlan', ['filters' => ['administrationId' => 'adm-1', 'resourceType' => 'water'], 'limit' => 2]],
				['saveObject', 'MeterReading', $reading(['customerId' => 'c-1', 'resourceType' => 'water', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'])],
				['saveObject', 'MeterReading', $reading(['meterId' => 'refused', 'customerId' => 'c-1', 'resourceType' => 'kwh', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31', 'ratePlanId' => 'plan-kwh'])],
				['saveObject', 'MeterReading', $reading(['customerId' => 'c-1', 'resourceType' => 'gas', 'quantity' => 1.5, 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'])],
			],
			$this->calls
		);

	}//end testImportGolden()

	/**
	 * No rows, no calls.
	 *
	 * @return void
	 */
	public function testEmptyImport(): void {
		$this->assertSame(['created' => [], 'refused' => []], $this->importer()->import(administrationId: 'adm-1', rows: []));
		$this->assertSame([], $this->calls);

	}//end testEmptyImport()
}//end class
