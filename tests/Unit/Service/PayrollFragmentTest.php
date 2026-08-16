<?php

/**
 * Unit tests for the bookkeeping-payroll-engine-nl register fragment.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-000-werkgever-setup.md
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
 * Verifies the payroll fragment is valid JSON, declares the seven payroll schemas
 * (ADR-037), keeps LoonheffingTabel2026 read-only/immutable (design.md D2), scopes
 * every operational schema by administrationId, merges additively onto the
 * monolith without disturbing existing schemas, and ships internally consistent
 * seed objects whose journaalpost balances (REQ-PAY-012) and whose LHAfdracht
 * total equals the sum of its components (REQ-PAY-011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-payroll-engine-nl.json';

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
	 * The fragment declares all seven payroll schemas (REQ-PAY-000..REQ-PAY-012).
	 *
	 * @return void
	 */
	public function testDeclaresAllSevenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'Werkgever',
			'Werknemer',
			'LoonheffingTabel2026',
			'LoonPeriode',
			'LoonStrook',
			'LHAfdracht',
			'Loonjournaalpost',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

	}//end testDeclaresAllSevenSchemas()

	/**
	 * The wage-tax table is read-only / immutable (design.md D2, REQ-PAY-002).
	 *
	 * @return void
	 */
	public function testLoonheffingTabelIsReadOnly(): void {
		$schema = $this->fragment()['components']['schemas']['LoonheffingTabel2026'];
		self::assertTrue($schema['readonly']);
		self::assertTrue($schema['x-openregister']['readonly']);

	}//end testLoonheffingTabelIsReadOnly()

	/**
	 * Werknemer reuses an existing person (personId) and never invents a person
	 * schema; BSN is declared nullable special-category (ADR — a person is a NC
	 * entity; AVG).
	 *
	 * @return void
	 */
	public function testWerknemerReferencesPersonAndCarriesBsn(): void {
		$props = $this->fragment()['components']['schemas']['Werknemer']['properties'];
		self::assertArrayHasKey('personId', $props);
		self::assertArrayHasKey('bsn', $props);
		self::assertTrue(($props['bsn']['nullable'] ?? false));

	}//end testWerknemerReferencesPersonAndCarriesBsn()

	/**
	 * Every operational schema scopes by administrationId (IDOR / multitenancy).
	 *
	 * @return void
	 */
	public function testOperationalSchemasScopeByAdministration(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$scoped = ['Werkgever', 'Werknemer', 'LoonPeriode', 'LoonStrook', 'LHAfdracht', 'Loonjournaalpost'];
		foreach ($scoped as $name) {
			self::assertArrayHasKey(
				'administrationId',
				$schemas[$name]['properties'],
				"$name must carry administrationId for tenant scoping"
			);
		}

	}//end testOperationalSchemasScopeByAdministration()

	/**
	 * Merging the fragment adds the payroll schemas without dropping monolith
	 * schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('Werknemer', $schemas);
		self::assertArrayHasKey('LoonStrook', $schemas);
		// A pre-existing monolith schema survives the merge.
		self::assertArrayHasKey('Account', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register.
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * Every seeded Loonjournaalpost balances: sum(debet) == sum(credit) and
	 * balanced == true (REQ-PAY-012).
	 *
	 * @return void
	 */
	public function testSeededJournaalpostBalances(): void {
		$seen = false;
		foreach ($this->fragment()['components']['objects'] as $object) {
			if ($object['@self']['schema'] !== 'Loonjournaalpost') {
				continue;
			}

			$seen = true;
			$debet = 0;
			$credit = 0;
			foreach ($object['rules'] as $rule) {
				$debet += (int)round(((float)($rule['debet'] ?? 0)) * 100);
				$credit += (int)round(((float)($rule['credit'] ?? 0)) * 100);
			}

			self::assertSame($debet, $credit, 'Seeded loonjournaalpost must balance');
			self::assertTrue($object['balanced']);
		}

		self::assertTrue($seen, 'Expected at least one seeded Loonjournaalpost');

	}//end testSeededJournaalpostBalances()

	/**
	 * Every seeded LHAfdracht total equals the sum of its components (REQ-PAY-011).
	 *
	 * @return void
	 */
	public function testSeededLhAfdrachtTotalsAddUp(): void {
		$seen = false;
		foreach ($this->fragment()['components']['objects'] as $object) {
			if ($object['@self']['schema'] !== 'LHAfdracht') {
				continue;
			}

			$seen = true;
			$sum = (int)round(((float)$object['totalPayrollTax']) * 100);
			$sum += (int)round(((float)($object['totalFinalLeviesWorkRelatedCosts'] ?? 0)) * 100);
			$sum += (int)round(((float)($object['totalSocialInsuranceContributions'] ?? 0)) * 100);
			$sum += (int)round(((float)($object['totalHealthInsurance'] ?? 0)) * 100);
			$tot = (int)round(((float)$object['totalRemittance']) * 100);
			self::assertSame($sum, $tot, 'LHAfdracht totaalAfdracht must equal the sum of components');
		}

		self::assertTrue($seen, 'Expected at least one seeded LHAfdracht');

	}//end testSeededLhAfdrachtTotalsAddUp()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
