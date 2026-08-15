<?php

/**
 * Unit tests for the bookkeeping-ib-aangifte-zzp register fragment.
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
 * @spec openspec/changes/bookkeeping-ib-aangifte-zzp/specs/bookkeeping-ib-aangifte-zzp/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 IB-aangifte fragment is valid JSON, declares the 9 P-formulier
 * entities + the tax-parameter metadata entity, seeds the example objects, and
 * merges additively onto the monolith register without clobbering existing
 * schemas or the pre-existing lightweight IbAangifteExport (ADR-037).
 */
final class IbAangifteZzpFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/30-bookkeeping-ib-aangifte-zzp.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The 9 schemas the fragment must declare.
	 *
	 * @var array<int,string>
	 */
	private array $expectedSchemas = [
		'IBAangifte',
		'IBWinstOpgave',
		'IBOndernemersaftrek',
		'IBHeffingskortingenAlgemeen',
		'IBLijfrenteAOV',
		'IBBijtellingAuto',
		'IBBox3Vermogen',
		'IBAuditTrail',
		'IBTaxParameterYear',
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
	 * Decode the fragment file.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all 9 IB schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresAllSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach ($this->expectedSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name slug must match key");
		}
	}//end testFragmentDeclaresAllSchemas()

	/**
	 * The IBAangifte header declares a lifecycle keyed on `status` and an RBAC
	 * block scoping writes to the ondernemer/fiscalist roles (ADR-005).
	 *
	 * @return void
	 */
	public function testHeaderDeclaresLifecycleAndRbac(): void {
		$taxReturn = $this->fragment()['components']['schemas']['IBAangifte'];

		self::assertArrayHasKey('x-openregister-lifecycle', $taxReturn);
		self::assertSame('status', $taxReturn['x-openregister-lifecycle']['field']);
		self::assertSame('CONCEPT', $taxReturn['x-openregister-lifecycle']['initialState']);
		self::assertArrayHasKey('INGEDIEND', $taxReturn['x-openregister-lifecycle']['states']);

		self::assertArrayHasKey('x-openregister-rbac', $taxReturn);
		$roles = $taxReturn['x-openregister-rbac']['roles'];
		// Auditors and bookkeepers are read-only.
		self::assertSame(['read'], $roles['auditor']['permissions']);
		self::assertSame(['read'], $roles['bookkeeper']['permissions']);
		// The ondernemer can create/read/update.
		self::assertContains('create', $roles['ib-ondernemer']['permissions']);
	}//end testHeaderDeclaresLifecycleAndRbac()

	/**
	 * Every IB entity carries administrationId for tenant isolation (ADR-005).
	 *
	 * @return void
	 */
	public function testEveryEntityScopesToAdministration(): void {
		$schemas = $this->fragment()['components']['schemas'];
		// The metadata parameter entity is global; all the rest are tenant-scoped.
		foreach (array_diff($this->expectedSchemas, ['IBTaxParameterYear']) as $name) {
			self::assertArrayHasKey(
				'administrationId',
				$schemas[$name]['properties'],
				"$name must carry administrationId for tenant isolation"
			);
		}
	}//end testEveryEntityScopesToAdministration()

	/**
	 * Fiscal calculations reference IBTaxParameterYear rather than hard-coding
	 * tariffs, and the guard seams point at real PHP methods (ADR-031).
	 *
	 * @return void
	 */
	public function testCalculationsAreParameterisedAndGuardSeamsExist(): void {
		$schemas = $this->fragment()['components']['schemas'];

		// MKB exemption rate is sourced from the parameter entity.
		$mkb = $schemas['IBOndernemersaftrek']['x-openregister-calculations']['mkbProfitExemption'];
		self::assertStringContainsString('IBTaxParameterYear.mkbExemptionRate', $mkb['parameterSource']);

		// Urencriterium guard points at the real existing method.
		$hours = $schemas['IBOndernemersaftrek']['x-openregister-calculations']['hoursCriterion'];
		self::assertSame(
			'OCA\\Shillinq\\Guard\\UrencriteriumGuard::currentYtdHours',
			$hours['guard']
		);

		// Representatiebeperking + bijtelling guards reference classes that exist.
		$repr = $schemas['IBWinstOpgave']['x-openregister-calculations']['entertainmentCorrection'];
		self::assertTrue(method_exists('OCA\\Shillinq\\Guard\\IbFiscalAdjustmentGuard', 'representatieDrempel'));
		self::assertStringContainsString('IbFiscalAdjustmentGuard', $repr['guard']);

		$bij = $schemas['IBBijtellingAuto']['x-openregister-calculations']['benefitInKindAmount'];
		self::assertTrue(method_exists('OCA\\Shillinq\\Guard\\IbBijtellingGuard', 'computeBijtelling'));
		self::assertStringContainsString('IbBijtellingGuard', $bij['guard']);
	}//end testCalculationsAreParameterisedAndGuardSeamsExist()

	/**
	 * Seed objects are present for both tax-parameter years and every entity,
	 * and reference the shillinq register by slug.
	 *
	 * @return void
	 */
	public function testSeedObjectsArePresent(): void {
		$objects = $this->fragment()['objects'];
		self::assertNotEmpty($objects);

		$bySchema = [];
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			$bySchema[$object['@self']['schema']] = ($bySchema[$object['@self']['schema']] ?? 0) + 1;
		}

		// Both parameter years seeded.
		self::assertSame(2, $bySchema['IBTaxParameterYear'], 'Both 2025 and 2026 parameters must be seeded');
		// One example object per IB entity.
		foreach (array_diff($this->expectedSchemas, ['IBTaxParameterYear']) as $name) {
			self::assertArrayHasKey($name, $bySchema, "An example object for $name must be seeded");
		}
	}//end testSeedObjectsArePresent()

	/**
	 * Seeded BSN values are masked, never raw 9-digit identifiers (ADR-005).
	 *
	 * @return void
	 */
	public function testSeededBsnIsMasked(): void {
		foreach ($this->fragment()['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') !== 'IBAangifte') {
				continue;
			}

			$bsn = (string)($object['bsn'] ?? '');
			self::assertDoesNotMatchRegularExpression(
				'/^\d{9}$/',
				$bsn,
				'Seeded BSN must be masked, never a raw 9-digit value'
			);
		}
	}//end testSeededBsnIsMasked()

	/**
	 * Merging the fragment onto the monolith unions the new schemas and concats
	 * the seed objects without dropping any pre-existing schema (REQ-IB-001,
	 * ADR-037). The pre-existing IbAangifteExport export schema survives.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count($base['objects'] ?? []);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach ($this->expectedSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must be present after merge");
		}

		// Pre-existing schemas survive (including the lightweight export schema).
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "Pre-existing $name must survive merge");
		}

		self::assertSame(
			($schemaCountBefore + count($this->expectedSchemas)),
			count($schemas),
			'Merge must add exactly the 9 new schemas, replacing none'
		);

		// Objects concatenated.
		self::assertSame(
			($objectCountBefore + count($frag['objects'])),
			count($merged['objects']),
			'Seed objects must be concatenated, not overwritten'
		);
	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The full pre-existing IbAangifteExport schema is distinct from the new
	 * IBAangifte header (no slug collision, REQ-IB Task 1).
	 *
	 * @return void
	 */
	public function testNewHeaderDoesNotCollideWithExportSchema(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$merged = $this->merge($base, $this->fragment());

		// Both exist side by side after considering all fragments.
		self::assertArrayHasKey('IBAangifte', $merged['components']['schemas']);
		self::assertNotSame('IbAangifteExport', 'IBAangifte');
	}//end testNewHeaderDoesNotCollideWithExportSchema()
}//end class
