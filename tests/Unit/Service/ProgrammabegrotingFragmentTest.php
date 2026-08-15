<?php

/**
 * Unit tests for the bookkeeping-programmabegroting register fragment.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/specs/bookkeeping-programmabegroting/spec.md
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
 * Verifies the programmabegroting fragment is valid JSON, declares the ten BBV
 * registers with their required fields and lifecycle wiring (ADR-037 / ADR-031),
 * merges additively onto the monolith, and ships consistent seed objects.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProgrammabegrotingFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-programmabegroting.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

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
	 * The fragment is present and valid JSON with schemas + objects.
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
	 * All ten BBV registers are declared (REQ-001..REQ-009).
	 *
	 * @return void
	 */
	public function testDeclaresTenRegisters(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'Programmabegroting',
			'Programma',
			'Taakveld',
			'Indicator',
			'Investering',
			'Reserve',
			'Voorziening',
			'Paragraaf',
			'Meerjarenraming',
			'Begrotingswijziging',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug']);
		}

	}//end testDeclaresTenRegisters()

	/**
	 * The Programmabegroting declares the REQ-001 sluitend-flags and lifecycle.
	 *
	 * @return void
	 */
	public function testProgrammabegrotingHasFlagsAndLifecycle(): void {
		$schema = $this->fragment()['components']['schemas']['Programmabegroting'];
		foreach (['structurallyBalanced', 'sluitendReëel', 'supervisionRegime', 'organisationType', 'status'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "Programmabegroting must declare $field");
		}

		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);
		self::assertArrayHasKey('behandelen', $lifecycle['transitions']);
		self::assertArrayHasKey('vaststellen', $lifecycle['transitions']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ProgrammabegrotingGuard::canVaststellen',
			$lifecycle['transitions']['vaststellen']['requires']
		);

	}//end testProgrammabegrotingHasFlagsAndLifecycle()

	/**
	 * The Begrotingswijziging declares the raadsbesluit-gated vaststellen transition.
	 *
	 * @return void
	 */
	public function testBegrotingswijzigingLifecycleWiredToGuard(): void {
		$schema = $this->fragment()['components']['schemas']['Begrotingswijziging'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\BegrotingswijzigingGuard::canVaststellen',
			$lifecycle['transitions']['vaststellen']['requires']
		);

	}//end testBegrotingswijzigingLifecycleWiredToGuard()

	/**
	 * Merging the fragment adds the registers without dropping the monolith's
	 * existing schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$baseSchemaCount = count($base['components']['schemas']);

		$merged = $this->merge($base, $this->fragment());
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('Programmabegroting', $schemas);
		self::assertArrayHasKey('Taakveld', $schemas);

		// The merge is additive and non-destructive: every fragment schema is
		// present in the merged set, no base schema is dropped, and the count
		// grows by exactly the number of fragment schemas not already in the
		// base. Some BBV schemas the fragment declares (Taakveld, Reserve,
		// Voorziening, Programma, Paragraaf, Begrotingswijziging) are now also
		// shipped by the consolidated monolith, so the net delta is computed
		// from the fragment rather than hard-coded to ten.
		$fragSchemaKeys = array_keys($this->fragment()['components']['schemas']);
		$netNew = count(array_diff($fragSchemaKeys, array_keys($base['components']['schemas'])));
		self::assertSame($baseSchemaCount + $netNew, count($schemas));
		foreach ($fragSchemaKeys as $fragName) {
			self::assertArrayHasKey($fragName, $schemas, "$fragName must be present after the fragment merge");
		}
		foreach (array_keys($base['components']['schemas']) as $baseName) {
			self::assertArrayHasKey($baseName, $schemas, "$baseName must survive the fragment merge");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and use the shillinq register.
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * Reserve / Voorziening seed eindsaldo arithmetic is internally consistent
	 * (REQ-006), and the Programma seed equals the sum of its Taakvelden (REQ-002).
	 *
	 * @return void
	 */
	public function testSeedArithmeticIsConsistent(): void {
		$objects = $this->fragment()['components']['objects'];

		$taskFieldByProgramma = [];
		foreach ($objects as $object) {
			$schema = $object['@self']['schema'];
			if ($schema === 'Reserve') {
				$expected = (int)round((($object['openingBalance'] + ($object['toevoegingen'] ?? 0)) - ($object['onttrekkingen'] ?? 0)) * 100);
				self::assertSame($expected, (int)round($object['closingBalance'] * 100), 'Reserve eindsaldo must balance');
			}

			if ($schema === 'Voorziening') {
				$movements = (($object['additions'] ?? 0) - ($object['release'] ?? 0) - ($object['utilisations'] ?? 0));
				$expected = (int)round(($object['openingBalance'] + $movements) * 100);
				self::assertSame($expected, (int)round($object['closingBalance'] * 100), 'Voorziening eindsaldo must balance');
			}

			if ($schema === 'Taakveld') {
				$pid = $object['programmeId'];
				$taskFieldByProgramma[$pid][] = $object;
			}
		}//end foreach

		// Verify Programma roll-up equals the sum of its child Taakvelden.
		foreach ($objects as $object) {
			if ($object['@self']['schema'] !== 'Programma') {
				continue;
			}

			$pid = $object['@self']['slug'];
			if (isset($taskFieldByProgramma[$pid]) === false) {
				continue;
			}

			$revenueCents = 0;
			$expensesCents = 0;
			foreach ($taskFieldByProgramma[$pid] as $tv) {
				$revenueCents += (int)round($tv['revenue'] * 100);
				$expensesCents += (int)round($tv['expenses'] * 100);
			}

			self::assertSame($revenueCents, (int)round($object['revenueTotal'] * 100), 'Programma batenTotaal must equal Σ Taakveld.baten');
			self::assertSame($expensesCents, (int)round($object['expensesTotal'] * 100), 'Programma lastenTotaal must equal Σ Taakveld.lasten');
		}//end foreach

	}//end testSeedArithmeticIsConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
