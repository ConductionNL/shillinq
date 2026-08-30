<?php

/**
 * Unit tests for the bookkeeping-vpb-mkb register fragment.
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
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
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
 * Verifies the Vpb-aangifte fragment is valid JSON, declares the twelve Vpb
 * schemas with their declarative lifecycle / calculation / unique metadata,
 * merges additively onto the monolith without colliding (ADR-037), references
 * only the two ADR-031 guard classes that exist, and ships the seed objects.
 */
final class VpbMkbFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-vpb-mkb.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The twelve schemas the fragment must declare.
	 *
	 * @var array<string>
	 */
	private const SCHEMAS = [
		'Belastingplichtige',
		'VpbTariefcatalogus',
		'VpbAangifte',
		'FiscaleCorrectie',
		'Innovatiebox',
		'Deelneming',
		'FiscaleEenheid',
		'Voorvoegingsverlies',
		'InvesteringsAftrek',
		'VoorlopigeAanslag',
		'DefinitieveAanslag',
		'BezwaarBeroep',
	];

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
	 * The fragment file is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the twelve Vpb schemas, each carrying its slug.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresTwelveSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertCount(count(self::SCHEMAS), $schemas);

		foreach (self::SCHEMAS as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a matching slug");
		}

	}//end testFragmentDeclaresTwelveSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on `status`.
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['VpbAangifte', 'DefinitieveAanslag', 'BezwaarBeroep'] as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('status', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * The VpbAangifte lifecycle enforces one-aangifte-per-jaar via a unique constraint
	 * and the concept->ingediend transition is guarded by VpbAangifteGuard::canIndienen.
	 *
	 * @return void
	 */
	public function testAangifteUniqueAndIndienenGuard(): void {
		$taxReturn = $this->fragment()['components']['schemas']['VpbAangifte'];

		self::assertContains(
			['taxpayer', 'taxYear'],
			$taxReturn['x-openregister-unique'],
			'VpbAangifte must be unique per (belastingplichtige, belastingjaar) (REQ-VPB-001)'
		);

		$indienen = $taxReturn['x-openregister-lifecycle']['transitions']['indienen'];
		self::assertSame('draft', $indienen['from']);
		self::assertSame('submitted', $indienen['to']);
		self::assertSame('OCA\\Shillinq\\Lifecycle\\VpbAangifteGuard::canIndienen', $indienen['requires']);

	}//end testAangifteUniqueAndIndienenGuard()

	/**
	 * The schijftarief and te-betalen calculations are declared on VpbAangifte (REQ-VPB-003).
	 *
	 * @return void
	 */
	public function testAangifteCalculations(): void {
		$calc = $this->fragment()['components']['schemas']['VpbAangifte']['x-openregister-calculations'];
		self::assertArrayHasKey('dueVpb', $calc);
		self::assertArrayHasKey('tePay', $calc);
		self::assertArrayHasKey('fiscalProfitForLosses', $calc);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\VpbBerekeningGuard::berekenVerschuldigdeVpb',
			$calc['dueVpb']['guard']
		);

	}//end testAangifteCalculations()

	/**
	 * The voorvoegingsverlies regime and verjaring calculations are declared (REQ-VPB-006).
	 *
	 * @return void
	 */
	public function testVoorvoegingsverliesCalculations(): void {
		$calc = $this->fragment()['components']['schemas']['Voorvoegingsverlies']['x-openregister-calculations'];
		self::assertArrayHasKey('regime', $calc);
		self::assertArrayHasKey('expiresIn', $calc);
		self::assertArrayHasKey('remainder', $calc);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\VpbBerekeningGuard::bepaalVerliesRegime',
			$calc['regime']['guard']
		);

	}//end testVoorvoegingsverliesCalculations()

	/**
	 * The bezwaar/beroep lifecycle references the ObjectionPeriodGuard (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testObjectionPeriodGuardReferenced(): void {
		$taxReturn = $this->fragment()['components']['schemas']['VpbAangifte'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ObjectionPeriodGuard::canFileObjection',
			$taxReturn['x-openregister-lifecycle']['transitions']['bezwaarMaken']['requires']
		);

		$objection = $this->fragment()['components']['schemas']['BezwaarBeroep'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ObjectionPeriodGuard::canFileAppeal',
			$objection['x-openregister-lifecycle']['transitions']['beroepInstellen']['requires']
		);

	}//end testObjectionPeriodGuardReferenced()

	/**
	 * Every guard class referenced by the fragment exists as a PHP file.
	 *
	 * @return void
	 */
	public function testReferencedGuardClassesExist(): void {
		$json = (string)file_get_contents($this->fragmentPath);

		$guards = [
			'OCA\\Shillinq\\Lifecycle\\VpbAangifteGuard' => __DIR__ . '/../../../lib/Lifecycle/VpbAangifteGuard.php',
			'OCA\\Shillinq\\Lifecycle\\ObjectionPeriodGuard' => __DIR__ . '/../../../lib/Lifecycle/ObjectionPeriodGuard.php',
			'OCA\\Shillinq\\Lifecycle\\VpbBerekeningGuard' => __DIR__ . '/../../../lib/Lifecycle/VpbBerekeningGuard.php',
		];

		foreach ($guards as $fqcn => $path) {
			if (str_contains($json, str_replace('\\', '\\\\', $fqcn)) === true) {
				self::assertFileExists($path, "Referenced guard $fqcn must have a backing class file");
			}
		}

	}//end testReferencedGuardClassesExist()

	/**
	 * The fragment merges additively onto the monolith without dropping monolith schemas
	 * and without colliding on any Vpb schema name (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditively(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$baseKeys = array_keys($base['components']['schemas']);

		// No Vpb schema may already exist in the monolith.
		foreach (self::SCHEMAS as $name) {
			self::assertNotContains($name, $baseKeys, "$name must not pre-exist in the monolith");
		}

		$merged = $this->merge($base, $this->fragment());
		$mergedKeys = array_keys($merged['components']['schemas']);

		// All monolith schemas survive.
		foreach ($baseKeys as $name) {
			self::assertContains($name, $mergedKeys, "Monolith schema $name must survive the merge");
		}

		// All Vpb schemas are added.
		foreach (self::SCHEMAS as $name) {
			self::assertContains($name, $mergedKeys, "Fragment schema $name must be present after merge");
		}

	}//end testFragmentMergesAdditively()

	/**
	 * The fragment ships the 2026 tariff, the ACME BV belastingplichtige and a
	 * voorvoegingsverlies seed object, all targeting the shillinq register.
	 *
	 * @return void
	 */
	public function testSeedObjects(): void {
		$objects = $this->fragment()['components']['objects'];
		self::assertNotEmpty($objects);

		$bySchema = [];
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			$bySchema[$object['@self']['schema']] = $object;
		}

		self::assertArrayHasKey('VpbTariefcatalogus', $bySchema);
		self::assertSame(2026, $bySchema['VpbTariefcatalogus']['taxYear']);
		self::assertSame(0.19, $bySchema['VpbTariefcatalogus']['tarief1']);
		self::assertSame(0.258, $bySchema['VpbTariefcatalogus']['tarief2']);
		self::assertSame(245000, $bySchema['VpbTariefcatalogus']['taxableAmountThreshold']);

		self::assertArrayHasKey('Belastingplichtige', $bySchema);
		self::assertSame('EH3', $bySchema['Belastingplichtige']['eRecognitionLevel']);

	}//end testSeedObjects()
}//end class
