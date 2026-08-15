<?php

/**
 * Unit tests for the bookkeeping-innovatiebox-administratie register fragment.
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
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/specs/bookkeeping-innovatiebox-administratie/spec.md
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
 * Verifies the innovatiebox fragment is valid JSON, declares the five registers
 * with their required fields and aggregation metadata (ADR-037 / ADR-031),
 * merges additively onto the monolith, hard-codes the 0.09 tariff (REQ-IBA-010),
 * and ships internally-consistent seed objects (the worked nexus example).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json';

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
	 * The fragment declares all five innovatiebox registers (REQ-IBA-001..005).
	 *
	 * @return void
	 */
	public function testDeclaresFiveRegisters(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['QualifyingAsset', 'NexusCalculation', 'IBProfitAttribution', 'IBExpenseAllocation', 'CarryForwardLoss'] as $name) {
			self::assertArrayHasKey($name, $schemas, "fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug']);
		}

	}//end testDeclaresFiveRegisters()

	/**
	 * NexusCalculation and CarryForwardLoss are immutable (REQ-IBA-002, REQ-IBA-005).
	 *
	 * @return void
	 */
	public function testImmutableSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertTrue($schemas['NexusCalculation']['x-openregister']['immutable']);
		self::assertTrue($schemas['CarryForwardLoss']['x-openregister']['immutable']);

	}//end testImmutableSchemas()

	/**
	 * The innovatieboxAdministratie aggregation is declared (ADR-031, REQ-IBA-006).
	 *
	 * @return void
	 */
	public function testDeclaresAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['IBProfitAttribution'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('innovatieboxAdministratie', $schema['x-openregister-aggregations']);

	}//end testDeclaresAggregation()

	/**
	 * The statutory tariff 0.09 is hard-coded as the IBProfitAttribution default (REQ-IBA-010).
	 *
	 * @return void
	 */
	public function testTariffIsHardCoded(): void {
		$props = $this->fragment()['components']['schemas']['IBProfitAttribution']['properties'];
		self::assertSame(0.09, $props['effective_rate']['default']);

	}//end testTariffIsHardCoded()

	/**
	 * Merging the fragment adds the five registers without dropping monolith schemas (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$before = count($base['components']['schemas']);
		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('QualifyingAsset', $schemas);
		self::assertGreaterThanOrEqual(($before + 5), count($schemas));

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only the declared schemas (ADR-037 disjoint union).
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
	 * The seeded nexus example matches the OECD BEPS Action 5 formula (REQ-IBA-002).
	 *
	 * Eigen 480k + derden 120k + verbonden 80k -> teller_na = min(1.3*600k, 680k)
	 * = 680k, nexusbreuk = 680k/680k = 1.0.
	 *
	 * @return void
	 */
	public function testSeededNexusExampleObeysFormula(): void {
		$objects = $this->fragment()['components']['objects'];
		$nexus = null;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'NexusCalculation' && $object['@self']['slug'] === 'nx-2024-001') {
				$nexus = $object;
				break;
			}
		}

		self::assertNotNull($nexus);
		$tellerFor = ($nexus['own_rd_cost'] + $nexus['rd_cost_outsourced_third_parties']);
		$tellerAfter = min((1.3 * $tellerFor), $nexus['total_rd_cost']);
		$ratio = min(($tellerAfter / $nexus['total_rd_cost']), 1.0);
		self::assertSame($nexus['nexus_fraction_applied'], round($ratio, 4));

	}//end testSeededNexusExampleObeysFormula()
}//end class
