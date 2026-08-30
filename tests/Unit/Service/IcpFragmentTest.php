<?php

/**
 * Unit tests for the bookkeeping-icp-opgaaf register fragment.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/specs/bookkeeping-icp-opgaaf/spec.md
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
 * Verifies the ICP fragment is valid JSON, declares the four ICP schemas with
 * their fields, lifecycle, calculation and aggregation metadata (ADR-037 /
 * ADR-031 / ADR-022), merges additively onto the monolith without disturbing the
 * pre-existing IcpStatement / VatReturn schemas, ships the three zero-rated
 * chart-of-accounts objects, and seeds consistent example objects whose totals
 * obey total = totalGoods + totalServices (with triangulation folded into goods).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-icp-opgaaf.json';

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
	 * The fragment declares the four new ICP schemas (REQ-ICP-001..REQ-ICP-010).
	 *
	 * @return void
	 */
	public function testDeclaresFourIcpSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['ViesValidation', 'IcpSupply', 'IcpOpgaaf', 'PeriodicitySwitch'] as $name) {
			self::assertArrayHasKey($name, $schemas, "fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug']);
		}

	}//end testDeclaresFourIcpSchemas()

	/**
	 * IcpSupply declares the buyer / supplyType / amount fields and the ledger aggregation (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testIcpSupplyFieldsAndAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['IcpSupply'];
		foreach (['supplyDate', 'buyerVatId', 'buyerCountry', 'supplyType', 'amountExclVat', 'administrationId'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "IcpSupply must declare $field");
		}

		self::assertSame(['L', 'S', 'T'], $schema['properties']['supplyType']['enum']);
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('icpLedgerByBuyerSupplyType', $schema['x-openregister-aggregations']);

	}//end testIcpSupplyFieldsAndAggregation()

	/**
	 * IcpOpgaaf declares the draft → finalized → submitted lifecycle and XBRL calculation (REQ-ICP-004, REQ-ICP-005, ADR-022).
	 *
	 * @return void
	 */
	public function testIcpOpgaafLifecycleAndCalculation(): void {
		$schema = $this->fragment()['components']['schemas']['IcpOpgaaf'];
		self::assertArrayHasKey('x-openregister-lifecycle', $schema);
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('draft', $lifecycle['initialState']);
		foreach (['draft', 'finalized', 'submitted', 'accepted', 'rejected', 'corrected'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "IcpOpgaaf lifecycle must define $state");
		}

		// Finalize is guarded (reconciliation gate); submit consumes approval-workflow (ADR-022).
		self::assertArrayHasKey('guard', $lifecycle['states']['draft']['transitions']['finalize']);
		self::assertSame('approval-workflow', $lifecycle['states']['finalized']['transitions']['submit']['requires']);

		self::assertArrayHasKey('x-openregister-calculations', $schema);
		self::assertArrayHasKey('xmlPayload', $schema['x-openregister-calculations']);

	}//end testIcpOpgaafLifecycleAndCalculation()

	/**
	 * ViesValidation is immutable evidence carrying the requestId (REQ-ICP-001, REQ-ICP-009).
	 *
	 * @return void
	 */
	public function testViesValidationIsImmutableEvidence(): void {
		$schema = $this->fragment()['components']['schemas']['ViesValidation'];
		self::assertTrue($schema['x-openregister']['immutable']);
		foreach (['vatId', 'validationTimestamp', 'valid', 'requestId', 'outage'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "ViesValidation must declare $field");
		}

	}//end testViesValidationIsImmutableEvidence()

	/**
	 * Merging the fragment adds the ICP schemas without dropping the pre-existing
	 * IcpStatement / VatReturn schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$merged = $this->merge($base, $this->fragment());

		// Simulate the operations fragment having declared IcpStatement / VatReturn.
		$opsPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-operations.json';
		$ops = json_decode((string)file_get_contents($opsPath), true);
		$merged = $this->merge($merged, $ops);
		$merged = $this->merge($merged, $this->fragment());

		$schemas = $merged['components']['schemas'];
		self::assertArrayHasKey('IcpSupply', $schemas);
		self::assertArrayHasKey('IcpOpgaaf', $schemas);
		self::assertArrayHasKey('ViesValidation', $schemas);
		self::assertArrayHasKey('PeriodicitySwitch', $schemas);
		// The pre-existing minimal IcpStatement survives the merge untouched.
		self::assertArrayHasKey('IcpStatement', $schemas);
		self::assertArrayHasKey('VatReturn', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The three zero-rated chart-of-accounts entries are seeded (Task 22).
	 *
	 * @return void
	 */
	public function testZeroRatedAccountsSeeded(): void {
		$objects = $this->fragment()['components']['objects'];
		$accounts = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'Account') {
				$accounts[(string)($object['accountNumber'] ?? '')] = $object;
			}
		}

		foreach (['8190', '8195', '8196'] as $number) {
			self::assertArrayHasKey($number, $accounts, "Account $number must be seeded");
			self::assertSame(0, $accounts[$number]['vatRate'], "Account $number must be zero-rated");
			self::assertSame('revenue', $accounts[$number]['accountType']);
		}

	}//end testZeroRatedAccountsSeeded()

	/**
	 * Seed objects target only declared schemas, and the IcpOpgaaf totals are consistent (REQ-ICP-003).
	 *
	 * @return void
	 */
	public function testSeedObjectsConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = (string)$object['@self']['schema'];
			// Account is declared in another fragment; the four ICP schemas are local.
			if ($schema !== 'Account') {
				self::assertArrayHasKey($schema, $schemas, "$schema must be declared in this fragment");
			}

			if ($schema === 'IcpOpgaaf') {
				$cents = static fn (float $v): int => (int)round($v * 100);
				$total = $cents((float)$object['total']);
				$goods = $cents((float)$object['totalGoods']);
				$serv = $cents((float)$object['totalServices']);
				self::assertSame(
					($goods + $serv),
					$total,
					'IcpOpgaaf ' . $object['@self']['slug'] . ' must satisfy total = totalGoods + totalServices'
				);
			}
		}//end foreach

	}//end testSeedObjectsConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
