<?php

/**
 * Unit tests for the bookkeeping-kor-kleine-ondernemersregeling register fragment.
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
 * @spec openspec/changes/bookkeeping-kor-kleine-ondernemersregeling/specs.md
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
 * Verifies the KOR fragment is valid JSON, declares the five KOR schemas with the
 * lifecycle on KORRegistration and the aggregation on KORAnnualTurnover (ADR-031 /
 * ADR-037), merges additively onto the monolith without disturbing the pre-existing
 * lightweight KorRegime schema, and ships consistent seed objects.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KorFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-kor-kleine-ondernemersregeling.json';

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
	 * All five KOR schemas are declared with administrationId scoping (REQ-KOR-001..008).
	 *
	 * @return void
	 */
	public function testDeclaresFiveKorSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['KORRegistration', 'KORAnnualTurnover', 'KORThresholdAlert', 'KORRevocation', 'KOREUTurnover'] as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must be declared");
			self::assertArrayHasKey('administrationId', $schemas[$name]['properties'], "$name must scope on administrationId");
		}

	}//end testDeclaresFiveKorSchemas()

	/**
	 * KORRegistration carries the ADR-031 lifecycle with the three exit transitions (REQ-KOR-007).
	 *
	 * @return void
	 */
	public function testRegistrationLifecycle(): void {
		$schema = $this->fragment()['components']['schemas']['KORRegistration'];
		self::assertArrayHasKey('x-openregister-lifecycle', $schema);
		$lc = $schema['x-openregister-lifecycle'];
		self::assertSame('status', $lc['field']);
		self::assertSame('draft', $lc['initialState']);
		foreach (['draft', 'ACTIEF', 'GEEINDIGD_OVERSCHRIJDING', 'GEEINDIGD_VRIJWILLIG'] as $state) {
			self::assertArrayHasKey($state, $lc['states'], "lifecycle must declare state $state");
		}

		foreach (['activate', 'revokeOverschrijding', 'optOut'] as $transition) {
			self::assertArrayHasKey($transition, $lc['transitions'], "lifecycle must declare transition $transition");
		}

	}//end testRegistrationLifecycle()

	/**
	 * KORAnnualTurnover declares the drempel-benutting aggregation (ADR-031, REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testTurnoverAggregation(): void {
		$schema = $this->fragment()['components']['schemas']['KORAnnualTurnover'];
		self::assertArrayHasKey('x-openregister-aggregations', $schema);
		self::assertArrayHasKey('korTurnoverByYear', $schema['x-openregister-aggregations']);

	}//end testTurnoverAggregation()

	/**
	 * Merging the fragment adds the KOR schemas without dropping the monolith's
	 * pre-existing lightweight KorRegime schema (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('KORRegistration', $schemas);
		self::assertArrayHasKey('KOREUTurnover', $schemas);
		// The pre-existing lightweight KorRegime schema survives the merge.
		self::assertArrayHasKey('KorRegime', $schemas);
		self::assertArrayHasKey('ytdRevenue', $schemas['KorRegime']['properties']);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register, and the
	 * worked overschrijding example uses revocatieDatum = leveringsDatum (design D4).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		$revocation = null;
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);

			if ($object['@self']['schema'] === 'KORRevocation' && $object['type'] === 'OVERRUN') {
				$revocation = $object;
			}
		}

		self::assertNotNull($revocation, 'Expect a worked OVERSCHRIJDING revocation seed');
		// Revocatie-datum equals the trigger invoice delivery date, not a year-end.
		self::assertSame('2026-09-04', $revocation['revocationDate']);
		// Blokkade = revocatieDatum + 3 years.
		self::assertSame('2029-09-04', $revocation['blockReRegistration']);

	}//end testSeedObjectsAreConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
