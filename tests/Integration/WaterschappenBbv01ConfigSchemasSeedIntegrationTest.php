<?php

/**
 * Config-schemas-and-seed integration test for member 01 of the
 * bookkeeping-waterschappen-bbv-variant chain.
 *
 * Loads the slice-01 register fragment + slice-01 seed fixture and asserts
 * that the BBVProgramme and BudgetBBVMapping registers materialise with the
 * declared properties, that every fixture record satisfies the schema's
 * required fields, that the demo allocation rows sum to 100 per GL account
 * (the invariant the aggregation service in chain member 02 relies on),
 * and that the GLTransaction + GLLine fixture pair the later chain members
 * (02 aggregation, 08 compliance service, 11 testing) consume is consistent
 * (balanced + references known GL accounts).
 *
 * No OpenRegister runtime is exercised — the fragment JSON is deep-merged
 * into the base shillinq_register.json the same way
 * SettingsService::loadRegisterConfigData() does at install time, then the
 * resulting OpenAPI components are inspected.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#integration-test-scaffold
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the BBVProgramme + BudgetBBVMapping registers materialise as
 * declared and the demo seed fixture is internally consistent. Used by chain
 * members 02 (aggregation), 08 (compliance service) and 11 (testing) as the
 * shared bootstrap for fixture round-trip tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WaterschappenBbv01ConfigSchemasSeedIntegrationTest extends TestCase {

	/**
	 * Schemas the slice-01 fragment SHALL declare.
	 *
	 * @var array<int,string>
	 */
	private const SCHEMAS = [
		'BBVProgramme',
		'BudgetBBVMapping',
	];

	/**
	 * Load the base shillinq_register.json + merge every register.d/*.json
	 * fragment exactly the way SettingsService does at install time. Returns
	 * the merged OpenAPI components object.
	 *
	 * @return array<string,mixed>
	 */
	private function loadMergedComponents(): array {
		$basePath = __DIR__ . '/../../lib/Settings/shillinq_register.json';
		$baseRaw = file_get_contents($basePath);
		if ($baseRaw === false) {
			self::fail('Could not read shillinq_register.json base config.');
		}

		$base = json_decode($baseRaw, true);
		if (is_array($base) === false) {
			self::fail('shillinq_register.json base config is not valid JSON.');
		}

		$fragmentDir = __DIR__ . '/../../lib/Settings/register.d';
		$fragments = glob($fragmentDir . '/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragmentRaw = file_get_contents($fragmentPath);
			if ($fragmentRaw === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentRaw, true);
			if (is_array($fragmentData) === false) {
				continue;
			}

			$base = self::deepMerge(base: $base, overlay: $fragmentData);
		}

		return ($base['components'] ?? []);
	}//end loadMergedComponents()

	/**
	 * Deep-merge an overlay onto a base; mirror of SettingsService::deepMergeConfig
	 * (associative arrays merge by key, list arrays concatenate, scalars overwrite).
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge.
	 *
	 * @return array<mixed>
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}//end deepMerge()

	/**
	 * Load the slice-01 seed fixture.
	 *
	 * Exposed to later chain members (02, 08, 11) via the fixture file path
	 * which is the canonical place to materialise BBVProgramme + BudgetBBVMapping
	 * + GL records together.
	 *
	 * @return array<string,mixed>
	 */
	public static function fixture(): array {
		$path = __DIR__ . '/../fixtures/WaterschappenBbv01SeedData.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read WaterschappenBbv01SeedData fixture.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('WaterschappenBbv01SeedData fixture is not valid JSON.');
		}

		return $data;
	}//end fixture()

	/**
	 * Both schemas must materialise after the slice-01 fragment is merged
	 * into the base config.
	 *
	 * @return void
	 */
	public function testBothSchemasMaterialise(): void {
		$components = $this->loadMergedComponents();
		self::assertArrayHasKey('schemas', $components, 'Merged config must expose components.schemas.');

		$schemas = $components['schemas'];
		foreach (self::SCHEMAS as $name) {
			self::assertArrayHasKey(
				$name,
				$schemas,
				'Schema ' . $name . ' should be declared by the slice-01 fragment.'
			);
			self::assertSame(
				$name,
				($schemas[$name]['slug'] ?? null),
				'Schema ' . $name . ' should declare a matching slug.'
			);
			self::assertSame(
				'object',
				($schemas[$name]['type'] ?? null),
				'Schema ' . $name . ' should be an object.'
			);
		}

	}//end testBothSchemasMaterialise()

	/**
	 * BBVProgramme declares the five required fields per REQ-BBVW-001
	 * (programmeCode, programmeName, fiscalYear, status, administrationId).
	 *
	 * @return void
	 */
	public function testBbvProgrammeRequiredFields(): void {
		$components = $this->loadMergedComponents();
		$programme = $components['schemas']['BBVProgramme'];

		foreach (['programmeCode', 'programmeName', 'fiscalYear', 'status', 'administrationId'] as $field) {
			self::assertContains(
				$field,
				$programme['required'],
				'BBVProgramme MUST require ' . $field . ' per REQ-BBVW-001.'
			);
			self::assertArrayHasKey(
				$field,
				$programme['properties'],
				'BBVProgramme MUST declare property ' . $field . '.'
			);
		}

		$statusEnum = $programme['properties']['status']['enum'];
		self::assertContains('active', $statusEnum, 'BBVProgramme.status MUST enumerate "active".');
		self::assertContains(
			'archived',
			$statusEnum,
			'BBVProgramme.status MUST enumerate "archived" so operators archive instead of delete (design D1).'
		);

	}//end testBbvProgrammeRequiredFields()

	/**
	 * BudgetBBVMapping declares the five required fields per REQ-BBVW-002
	 * (glAccountNumber, programmeCode, allocationPercentage, effectiveFrom,
	 * administrationId).
	 *
	 * @return void
	 */
	public function testBudgetBbvMappingRequiredFields(): void {
		$components = $this->loadMergedComponents();
		$mapping = $components['schemas']['BudgetBBVMapping'];

		foreach ([
			'glAccountNumber',
			'programmeCode',
			'allocationPercentage',
			'effectiveFrom',
			'administrationId',
		] as $field
		) {
			self::assertContains(
				$field,
				$mapping['required'],
				'BudgetBBVMapping MUST require ' . $field . ' per REQ-BBVW-002.'
			);
			self::assertArrayHasKey(
				$field,
				$mapping['properties'],
				'BudgetBBVMapping MUST declare property ' . $field . '.'
			);
		}

		self::assertSame(0, $mapping['properties']['allocationPercentage']['minimum']);
		self::assertSame(100, $mapping['properties']['allocationPercentage']['maximum']);
		self::assertTrue(
			($mapping['properties']['effectiveTo']['nullable'] ?? false),
			'BudgetBBVMapping.effectiveTo MUST be nullable for open-ended mappings.'
		);

	}//end testBudgetBbvMappingRequiredFields()

	/**
	 * BBVProgramme + BudgetBBVMapping carry the foreign-key fields to
	 * Administration / BBVProgramme / Account so the downstream UI +
	 * aggregation service can join.
	 *
	 * WITHDRAWN ASSERTIONS — all four `x-openregister-relations` entries
	 * (BBVProgramme.administration, and BudgetBBVMapping.programme / .account /
	 * .administration). The per-schema block was retired by ADR-062 rule 7 on
	 * 2026-07-08 in favour of a property-level `$ref`. NONE of the four is
	 * expressible in the canonical dialect, so all four were removed rather
	 * than migrated: every one of them pointed at a BUSINESS KEY rather than
	 * the target's object identity — `Administration.administrationCode`,
	 * `BBVProgramme.programmeCode` and `Account.accountNumber` respectively.
	 * OpenRegister resolves a `$ref` against the target's object id, so a
	 * `$ref` on any of these would name a target it could never reach.
	 * Cardinality has no slot in the canonical dialect either — a scalar
	 * `$ref` IS the many-to-one form.
	 *
	 * The join this test exists to protect is still pinned, at the level the
	 * register still declares it: the three foreign-key fields are declared and
	 * required, so a mapping cannot be stored without the keys the aggregation
	 * service joins on. The FK-existence rule itself is enforced separately, by
	 * the `x-openregister-validation.glAccountForeignKey` rule that
	 * WaterschappenBbv03ValidationRulesIntegrationTest asserts — a different
	 * dialect, untouched by the ADR-062 retirement.
	 *
	 * @return void
	 */
	public function testForeignKeyFieldsDeclared(): void {
		$components = $this->loadMergedComponents();

		$programme = $components['schemas']['BBVProgramme'];
		self::assertArrayHasKey(
			key: 'administrationId',
			array: $programme['properties'],
			message: 'BBVProgramme MUST declare the administrationId foreign key.'
		);
		self::assertContains(needle: 'administrationId', haystack: $programme['required']);

		$mapping = $components['schemas']['BudgetBBVMapping'];
		foreach (['programmeCode', 'glAccountNumber', 'administrationId'] as $foreignKey) {
			self::assertArrayHasKey(
				key: $foreignKey,
				array: $mapping['properties'],
				message: 'BudgetBBVMapping MUST declare the ' . $foreignKey . ' foreign key.'
			);
			self::assertContains(
				needle: $foreignKey,
				haystack: $mapping['required'],
				message: 'BudgetBBVMapping.' . $foreignKey . ' MUST be required — the aggregation service joins on it.'
			);
		}

	}//end testForeignKeyFieldsDeclared()

	/**
	 * The 5 demo programmes the design specifies are present in the fixture.
	 *
	 * @return void
	 */
	public function testFixtureHasFiveDemoProgrammes(): void {
		$fixture = self::fixture();
		self::assertSame(2026, ($fixture['fiscalYear'] ?? null));
		self::assertSame('adm-waterschap-1', ($fixture['administrationId'] ?? null));
		self::assertCount(5, ($fixture['BBVProgramme'] ?? []));

		$codes = array_column($fixture['BBVProgramme'], 'programmeCode');
		foreach (['1.1.1', '1.2.1', '2.3.2', '2.4.1', '3.1.0'] as $expected) {
			self::assertContains(
				$expected,
				$codes,
				'Fixture MUST contain demo programme ' . $expected . ' per design.md.'
			);
		}

	}//end testFixtureHasFiveDemoProgrammes()

	/**
	 * Allocation rows sum to exactly 100 per GL account — the invariant the
	 * aggregation service in chain member 02 relies on.
	 *
	 * @return void
	 */
	public function testFixtureMappingsSumToHundred(): void {
		$fixture = self::fixture();
		$sums = [];
		foreach ($fixture['BudgetBBVMapping'] as $mapping) {
			$glAccount = (string)($mapping['glAccountNumber'] ?? '');
			if ($glAccount === '') {
				continue;
			}

			$sums[$glAccount] = (($sums[$glAccount] ?? 0) + (int)$mapping['allocationPercentage']);
		}

		self::assertNotEmpty($sums);
		foreach ($sums as $glAccount => $sum) {
			self::assertSame(
				100,
				$sum,
				'Allocation rows MUST sum to 100 for GL ' . $glAccount . ' so the aggregation service can compute rollups deterministically.'
			);
		}

	}//end testFixtureMappingsSumToHundred()

	/**
	 * Every mapping in the fixture references a programme that is also in
	 * the fixture (referential integrity for chain members 02/08/11).
	 *
	 * @return void
	 */
	public function testFixtureMappingsReferenceKnownProgrammes(): void {
		$fixture = self::fixture();
		$codes = array_column($fixture['BBVProgramme'], 'programmeCode');

		foreach ($fixture['BudgetBBVMapping'] as $mapping) {
			self::assertContains(
				$mapping['programmeCode'],
				$codes,
				'BudgetBBVMapping references unknown programmeCode ' . $mapping['programmeCode'] . '.'
			);
		}

	}//end testFixtureMappingsReferenceKnownProgrammes()

	/**
	 * The GLTransaction + GLLine fixture pair is balanced (sum of debits =
	 * sum of credits per transaction) and only references GL accounts that
	 * either appear in the BudgetBBVMapping fixture (4100, 5000) or are the
	 * canonical bank account (1000) used for offsetting entries.
	 *
	 * @return void
	 */
	public function testFixtureGlEntriesBalanced(): void {
		$fixture = self::fixture();
		$transactions = ($fixture['GLTransaction'] ?? []);
		$lines = ($fixture['GLLine'] ?? []);
		self::assertNotEmpty($transactions, 'Fixture MUST ship at least one GLTransaction for downstream aggregation tests.');
		self::assertNotEmpty($lines, 'Fixture MUST ship at least two GLLine rows so debits + credits balance.');

		$balances = [];
		foreach ($lines as $line) {
			$txn = (string)$line['transactionNumber'];
			$side = (string)$line['side'];
			$sign = -1;
			if ($side === 'debit') {
				$sign = 1;
			}

			$balances[$txn] = (($balances[$txn] ?? 0) + ($sign * (int)$line['amount']));
		}

		foreach ($balances as $txn => $balance) {
			self::assertSame(
				0,
				$balance,
				'GLTransaction ' . $txn . ' debit/credit lines MUST balance to zero (ADR-022 integer-cent ledger).'
			);
		}

		$mappingAccounts = array_unique(array_column($fixture['BudgetBBVMapping'], 'glAccountNumber'));
		$allowed = array_merge($mappingAccounts, ['1000']);
		foreach ($lines as $line) {
			self::assertContains(
				$line['accountNumber'],
				$allowed,
				'GLLine references unexpected account ' . $line['accountNumber'] . '; only mapping-covered + bank accounts allowed.'
			);
		}

	}//end testFixtureGlEntriesBalanced()
}//end class
