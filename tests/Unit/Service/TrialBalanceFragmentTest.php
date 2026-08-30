<?php

/**
 * Unit tests for the bookkeeping-trial-balance register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-trial-balance/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the trial-balance fragment is valid JSON, declares the read-only
 * TrialBalanceLine schema with its per-account period fields and aggregation
 * metadata (ADR-037 / ADR-031), merges additively onto the monolith without
 * disturbing the existing snapshot TrialBalance schema, and ships seed objects
 * whose closing balances obey closing = opening + (debit - credit) (REQ-TB-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TrialBalanceFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-trial-balance.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the read-only TrialBalanceLine schema (REQ-TB-001, REQ-TB-007).
	 *
	 * @return void
	 */
	public function testDeclaresReadOnlyTrialBalanceLineSchema(): void {
		$schema = $this->fragment()['components']['schemas']['TrialBalanceLine'];
		self::assertSame('TrialBalanceLine', $schema['slug']);
		self::assertTrue($schema['readonly']);
		self::assertTrue($schema['x-openregister']['readonly']);

		$expected = [
			'periodId',
			'accountNumber',
			'accountName',
			'accountType',
			'openingBalance',
			'debitMovement',
			'creditMovement',
			'closingBalance',
			'currency',
			'parentAccountNumber',
			'administrationId',
		];
		foreach ($expected as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "TrialBalanceLine must declare $field");
		}

	}//end testDeclaresReadOnlyTrialBalanceLineSchema()

	/**
	 * The per-account roll-up is declared as an aggregation (ADR-031, REQ-TB-018).
	 *
	 * @return void
	 */
	public function testDeclaresAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['TrialBalanceLine'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('trialBalanceByAccountPeriod', $schema['x-openregister-aggregations']);

	}//end testDeclaresAggregation()

	/**
	 * Merging the fragment adds TrialBalanceLine without dropping the monolith's
	 * existing snapshot TrialBalance schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('TrialBalanceLine', $schemas);
		// The pre-existing snapshot TrialBalance schema survives the merge.
		self::assertArrayHasKey('TrialBalance', $schemas);
		self::assertArrayHasKey('reportDate', $schemas['TrialBalance']['properties']);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only the declared schema and balance per REQ-TB-003.
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);

			// Closing = opening + (debit - credit) in cents (REQ-TB-003).
			$opening = (int)round(((float)$object['openingBalance']) * 100);
			$debit = (int)round(((float)$object['debitMovement']) * 100);
			$credit = (int)round(((float)$object['creditMovement']) * 100);
			$closing = (int)round(((float)$object['closingBalance']) * 100);
			self::assertSame(
				($opening + ($debit - $credit)),
				$closing,
				'Seed ' . $object['@self']['slug'] . ' must satisfy closing = opening + (debit - credit)'
			);
		}

	}//end testSeedObjectsAreConsistent()

	/**
	 * At least five distinct example trial balances are seeded (REQ-TB-013).
	 *
	 * @return void
	 */
	public function testFiveExampleTrialBalancesSeeded(): void {
		$objects = $this->fragment()['components']['objects'];
		$groups = [];
		foreach ($objects as $object) {
			$groups[$object['administrationId'] . '|' . $object['periodId']] = true;
		}

		self::assertGreaterThanOrEqual(5, count($groups), 'Expect >= 5 distinct administration+period example trial balances');

	}//end testFiveExampleTrialBalancesSeeded()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
