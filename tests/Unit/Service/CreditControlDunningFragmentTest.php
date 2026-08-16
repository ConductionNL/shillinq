<?php

/**
 * Unit tests for the bookkeeping-credit-control-dunning register fragment.
 *
 * Verifies the fragment declares the seven CCD schemas (REQ-CCD-001..010), uses
 * the correct enum values for kanaal / partyType / rente type, places money
 * fields with multipleOf: 0.01, declares the BIK staffel and rente accrual as
 * x-openregister-aggregations (ADR-031), and merges additively onto the
 * monolith (ADR-037).
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-001
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
 * Fragment tests for the credit-control & dunning ladder change.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CreditControlDunningFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-credit-control-dunning.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The seven schema slugs the change declares.
	 *
	 * @var array<int,string>
	 */
	private const SCHEMAS = [
		'DunningLadder',
		'KlantLadderOverride',
		'DunningRun',
		'IncassoKostenBerekening',
		'DunningPauseDispute',
		'CreditScore',
		'OninbaarAfschrijving',
	];

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed>
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
	 * The fragment is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * All seven schemas are declared.
	 *
	 * @return void
	 */
	public function testDeclaresSevenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (self::SCHEMAS as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Schema $slug must be declared");
			self::assertSame($slug, $schemas[$slug]['slug']);
		}

	}//end testDeclaresSevenSchemas()

	/**
	 * DunningLadder declares the kanaal enum + the wettelijkEffect markers per REQ-CCD-001.
	 *
	 * @return void
	 */
	public function testDunningLadderDeclaresKanaalAndWettelijkEffect(): void {
		$schema = $this->fragment()['components']['schemas']['DunningLadder'];
		$stage = $schema['properties']['stages']['items']['properties'];
		self::assertSame(
			['EMAIL', 'eMAILPostRegistration', 'REGISTERED_POST', 'COLLECTION_AGENCY_API'],
			$stage['channel']['enum']
		);
		self::assertContains('14_DAYS_BRIEF_BIK', $stage['statutoryEffect']['enum']);
		self::assertContains('DEFAULT_ENTRY', $stage['statutoryEffect']['enum']);

	}//end testDunningLadderDeclaresKanaalAndWettelijkEffect()

	/**
	 * DunningRun is immutable-by-lifecycle: states draft / executed / locked (REQ-CCD-002).
	 *
	 * @return void
	 */
	public function testDunningRunHasImmutableLifecycle(): void {
		$schema = $this->fragment()['components']['schemas']['DunningRun'];
		$states = array_keys($schema['x-openregister-lifecycle']['states']);
		self::assertSame(['draft', 'executed', 'locked'], $states);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\DunningRunExecuteGuard::canExecute',
			$schema['x-openregister-lifecycle']['transitions']['execute']['requires']
		);

	}//end testDunningRunHasImmutableLifecycle()

	/**
	 * IncassoKostenBerekening declares the BIK staffel + rente accrual aggregations.
	 *
	 * @return void
	 */
	public function testIncassoKostenDeclaresAggregations(): void {
		$schema = $this->fragment()['components']['schemas']['IncassoKostenBerekening'];
		$aggs = $schema['x-openregister-aggregations'];
		self::assertArrayHasKey('bikStaffel', $aggs);
		self::assertArrayHasKey('renteAccrual', $aggs);

		// The rente type enum must cite the two BW articles per REQ-CCD-003.
		$type = $schema['properties']['statutoryRente']['properties']['type']['enum'];
		self::assertSame(
			['COMMERCIAL_INTEREST_B2_B_6_119_A_BW', 'STATUTORY_INTEREST_B2_C_6_119_BW'],
			$type
		);

		// Money fields use multipleOf 0.01 (REQ-CCD-003 + global money rule).
		self::assertSame(0.01, $schema['properties']['principal']['multipleOf']);
		self::assertSame(0.01, $schema['properties']['totalDue']['multipleOf']);

	}//end testIncassoKostenDeclaresAggregations()

	/**
	 * DunningPauseDispute declares the active / resolved / hardDeadlineExpired states (REQ-CCD-004).
	 *
	 * @return void
	 */
	public function testDunningPauseDeclaresThreeStates(): void {
		$schema = $this->fragment()['components']['schemas']['DunningPauseDispute'];
		$states = array_keys($schema['x-openregister-lifecycle']['states']);
		self::assertSame(['active', 'resolved', 'hardDeadlineExpired'], $states);

	}//end testDunningPauseDeclaresThreeStates()

	/**
	 * OninbaarAfschrijving carries the art29OBVerklaring + btwAangiftePeriode fields (REQ-CCD-010).
	 *
	 * @return void
	 */
	public function testOninbaarAfschrijvingFieldShape(): void {
		$schema = $this->fragment()['components']['schemas']['OninbaarAfschrijving'];
		$props = $schema['properties'];
		self::assertArrayHasKey('art29OBDeclaration', $props);
		self::assertArrayHasKey('vatTaxReturnPeriod', $props);
		self::assertArrayHasKey('entryId', $props);
		self::assertSame(0.01, $props['principalDepreciated']['multipleOf']);

	}//end testOninbaarAfschrijvingFieldShape()

	/**
	 * The fragment merges additively onto the monolith without disturbing
	 * existing schemas (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditively(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$merged = $this->merge($base, $this->fragment());
		$schemas = $merged['components']['schemas'];

		foreach (self::SCHEMAS as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Merged config must include $slug");
		}
		// Pre-existing schemas survive.
		self::assertArrayHasKey('BankConnection', $schemas);
		self::assertArrayHasKey('GLTransaction', $schemas);

	}//end testFragmentMergesAdditively()

	/**
	 * Seed objects target only the declared schemas and carry administrationId.
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
			self::assertArrayHasKey('administrationId', $object);
		}

	}//end testSeedObjectsAreConsistent()

	/**
	 * The example €8.400 IncassoKostenBerekening seed yields €795 toegepast (REQ-CCD-003).
	 *
	 * @return void
	 */
	public function testExampleIncassoKostenSeedComputes795Eur(): void {
		$objects = $this->fragment()['objects'];
		$sample = null;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'IncassoKostenBerekening'
				&& (string)$object['@self']['slug'] === 'ik-inv-2026-0247'
			) {
				$sample = $object;
				break;
			}
		}
		self::assertNotNull($sample, 'Sample IncassoKostenBerekening seed must be present');
		self::assertSame(8400.0, (float)$sample['principal']);
		self::assertSame(795.0, (float)$sample['calculation']['applied']);
		self::assertSame('COMMERCIAL_INTEREST_B2_B_6_119_A_BW', $sample['statutoryRente']['type']);

	}//end testExampleIncassoKostenSeedComputes795Eur()

	/**
	 * The example overheid override is sourced from the OVERHEID rationale (REQ-CCD-001 + design D6).
	 *
	 * @return void
	 */
	public function testOverheidOverrideSeedCarriesRationale(): void {
		$objects = $this->fragment()['objects'];
		$sample = null;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'KlantLadderOverride'
				&& (string)$object['@self']['slug'] === 'override-gemeente-amsterdam'
			) {
				$sample = $object;
				break;
			}
		}
		self::assertNotNull($sample, 'Sample overheid override seed must be present');
		self::assertStringContainsString('Wet betalingstermijnen overheid', (string)$sample['reason']);
		self::assertCount(4, $sample['overrides']['stages']);
		// Second stage is the 30-day reminder per design D11.
		self::assertSame(30, (int)$sample['overrides']['stages'][1]['daysAfterExpiryDate']);

	}//end testOverheidOverrideSeedCarriesRationale()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
