<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Budget
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Budget;

use OCA\Shillinq\Budget\BudgetEditingService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;

/**
 * Gemeente Voorbeeld's 2026 budget over one store.
 */
trait BudgetEditingFixture {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Ids as OpenRegister would mint them.
	 *
	 * @var array<string,string>
	 */
	private array $ids = [
		'budget-2026'  => '6f1d6a0e-3b7a-4b61-9a53-2c7c1f0e2026',
		'lg-lasten'    => '0c2f7a55-6d8e-4f5e-8f0c-1a2b3c4d0001',
		'lg-personeel' => '0c2f7a55-6d8e-4f5e-8f0c-1a2b3c4d0002',
		'lg-afschr'    => '0c2f7a55-6d8e-4f5e-8f0c-1a2b3c4d0003',
		'line-pers'    => '9a8b7c6d-1111-4222-8333-944455556666',
		'line-afschr'  => '9a8b7c6d-1111-4222-8333-944455557777',
	];

	/**
	 * Seed ledger groups, the 2026 budget with Personeel at EUR 2,400,000 manual and depreciation projected.
	 *
	 * @return void
	 */
	private function seed(): void {
		$months = static fn (int $cents, string $prefix = 'month'): array => array_combine(
			array_map(static fn (int $m): string => sprintf('%s%02dAmount', $prefix, $m), range(1, 12)),
			array_fill(0, 12, $cents)
		);

		$this->store = new InMemoryObjectServiceStub(
			[
				'LedgerGroup'  => [
					['id' => $this->ids['lg-lasten'], 'administrationId' => 'adm-voorbeeld', 'code' => 'LASTEN', 'name' => 'Lasten', 'order' => 20, 'parentLedgerGroupId' => null],
					['id' => $this->ids['lg-personeel'], 'administrationId' => 'adm-voorbeeld', 'code' => 'PERS', 'name' => 'Personeel', 'order' => 10, 'parentLedgerGroupId' => 'LASTEN'],
					['id' => $this->ids['lg-afschr'], 'administrationId' => 'adm-voorbeeld', 'code' => 'AFSCHR', 'name' => 'Afschrijvingen', 'order' => 20, 'parentLedgerGroupId' => 'LASTEN'],
					['id' => 'lg-other', 'administrationId' => 'adm-other', 'code' => 'X', 'name' => 'Elsewhere', 'order' => 1],
				],
				'AnnualBudget' => [
					['id' => $this->ids['budget-2026'], 'administrationId' => 'adm-voorbeeld', 'fiscalYear' => 2026, 'name' => 'Begroting 2026', 'isDefault' => true, 'state' => 'active'],
					['id' => 'budget-other', 'administrationId' => 'adm-other', 'fiscalYear' => 2026, 'name' => 'Other', 'isDefault' => true, 'state' => 'draft'],
				],
				'BudgetLine'   => [
					array_merge(['id' => $this->ids['line-pers'], 'administrationId' => 'adm-voorbeeld', 'annualBudgetId' => $this->ids['budget-2026'], 'ledgerGroupId' => $this->ids['lg-personeel'], 'source' => 'manual'], $months(20000000)),
					array_merge(['id' => $this->ids['line-afschr'], 'administrationId' => 'adm-voorbeeld', 'annualBudgetId' => $this->ids['budget-2026'], 'ledgerGroupId' => $this->ids['lg-afschr'], 'source' => 'projected'], $months(500000)),
				],
			]
		);

	}//end seed()

	/**
	 * The service over the store.
	 *
	 * @return BudgetEditingService
	 */
	private function service(): BudgetEditingService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new BudgetEditingService($this->store, $settings);

	}//end service()

	/**
	 * Every row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema): array {
		return $this->store->setSchema($schema)->findAll();

	}//end all()

	/**
	 * Register validation errors for a stored row, the stub's obj-N ids mapped to uuids first.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $row    The stored row.
	 *
	 * @return array<string,mixed>
	 */
	private function registerErrors(string $schema, array $row): array {
		foreach ($row as $key => $value) {
			if (is_string($value) === true && preg_match('/^obj-\d+$/', $value) === 1) {
				$row[$key] = sprintf('00000000-0000-4000-8000-%012d', (int)substr($value, 4));
			}
		}

		return RegisterSchema::errors($schema, $row);

	}//end registerErrors()
}//end trait
