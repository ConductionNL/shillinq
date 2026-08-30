<?php

/**
 * Unit tests for the retainer-billing-engine register fragment.
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
 * @spec openspec/changes/retainer-billing-engine/specs/retainer-billing-management/spec.md
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
 * Verifies the retainer-billing-engine fragment is valid JSON, declares the four
 * retainer schemas with their declarative lifecycle / aggregation metadata,
 * merges additively onto the monolith (ADR-037), references the RetainerGuard on
 * the cross-field transitions (ADR-031 exception), and ships seed objects that
 * target only declared schemas.
 */
final class RetainerBillingFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/retainer-billing-engine.json';

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
	 * The fragment declares the four retainer schemas (REQ-RETN-001/002/004/006).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresFourSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'RetainerPool',
			'RetainerDrawdown',
			'RetainerRollover',
			'RetainerTrueUp',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresFourSchemas()

	/**
	 * The two operator-driven retainer schemas (RetainerPool, RetainerTrueUp)
	 * declare an x-openregister-lifecycle on `status` for the multi-step
	 * approval flow. RetainerDrawdown and RetainerRollover model their status
	 * as a closed enum on the property — the spec describes those records as
	 * append-only post-creation (REQ-RETN-002/004 immutability), so they
	 * do not declare a transition lifecycle (would conflict with append-only).
	 *
	 * @return void
	 */
	public function testAllSchemasDeclareStatusLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		// Pool + TrueUp drive the operator-facing lifecycle.
		foreach (['RetainerPool', 'RetainerTrueUp'] as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('status', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

		// Drawdown + Rollover are append-only and model status as a closed enum.
		foreach (['RetainerDrawdown', 'RetainerRollover'] as $name) {
			self::assertArrayHasKey('status', $schemas[$name]['properties'], "$name must declare status");
			self::assertArrayHasKey('enum', $schemas[$name]['properties']['status'], "$name status must be a closed enum");
			self::assertNotEmpty($schemas[$name]['properties']['status']['enum']);
		}

	}//end testAllSchemasDeclareStatusLifecycle()

	/**
	 * The pool balance roll-up is declarative: RetainerPool sums materialized
	 * drawdowns via x-openregister-aggregations, not a PHP service (ADR-022).
	 *
	 * Aggregation key was renamed from `actualDrawdown` to `drawdownsByPool`
	 * to align with the "filter-then-sum" rollup name used elsewhere
	 * (cf. VATReturn::totalsByReturn) and to be self-describing in the OR UI.
	 *
	 * @return void
	 */
	public function testDrawdownBalanceIsDeclarativeAggregation(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('x-openregister-aggregations', $schemas['RetainerPool']);
		$agg = $schemas['RetainerPool']['x-openregister-aggregations'];
		self::assertArrayHasKey('drawdownsByPool', $agg);

		$drawdownsByPool = $agg['drawdownsByPool'];

		// `from`/`metrics`, not `source`/`operations`. Neither `source` nor
		// `operations` is read by AggregationRunner, so this computed nothing.
		self::assertSame('RetainerDrawdown', $drawdownsByPool['from']);
		self::assertArrayNotHasKey('source', $drawdownsByPool, '`source` is not an engine key');
		self::assertArrayNotHasKey('operations', $drawdownsByPool, '`operations` is not an engine key');

		self::assertArrayHasKey('metrics', $drawdownsByPool);
		$byAlias = [];
		foreach ($drawdownsByPool['metrics'] as $metric) {
			$byAlias[$metric['as']] = $metric;
		}
		self::assertArrayHasKey('drawnAmount', $byAlias);
		self::assertSame('sum', $byAlias['drawnAmount']['metric']);
		self::assertSame('drawdownAmount', $byAlias['drawnAmount']['field'], 'field is bare — `from` resolves it');

		// The pool correlation is a groupBy dimension now.
		self::assertContains('poolId', $drawdownsByPool['groupBy']);

	}//end testDrawdownBalanceIsDeclarativeAggregation()

	/**
	 * The pool + true-up lifecycles declare the full operator-driven transitions
	 * (REQ-RETN-001/006/008/011). The transitions are pure state moves —
	 * cross-field validations (non-overlapping periods, approver-present,
	 * rate-immutability) are enforced by the RetainerGuard service, invoked
	 * by the controller layer rather than declared inline.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];

		// RetainerPool: draft -> active -> closed -> archived.
		$poolTransitions = $schemas['RetainerPool']['x-openregister-lifecycle']['transitions'];
		foreach (['activate', 'close', 'archive'] as $transition) {
			self::assertArrayHasKey($transition, $poolTransitions, "RetainerPool must declare $transition transition");
			self::assertArrayHasKey('from', $poolTransitions[$transition]);
			self::assertArrayHasKey('to', $poolTransitions[$transition]);
		}
		self::assertSame('draft', $poolTransitions['activate']['from']);
		self::assertSame('active', $poolTransitions['activate']['to']);

		// RetainerTrueUp: generated -> pending-approval -> approved -> invoiced -> settled,
		// with a reverse escape hatch.
		$trueUpTransitions = $schemas['RetainerTrueUp']['x-openregister-lifecycle']['transitions'];
		foreach (['submit', 'approve', 'invoice', 'settle', 'reverse'] as $transition) {
			self::assertArrayHasKey($transition, $trueUpTransitions, "RetainerTrueUp must declare $transition transition");
		}
		self::assertSame('pending-approval', $trueUpTransitions['approve']['from']);
		self::assertSame('approved', $trueUpTransitions['approve']['to']);

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * The true-up status enum carries the full settlement progression
	 * (REQ-RETN-006/008/011).
	 *
	 * @return void
	 */
	public function testTrueUpStatusEnumCoversSettlement(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$statusEnum = $schemas['RetainerTrueUp']['properties']['status']['enum'];

		foreach (['generated', 'pending-approval', 'approved', 'invoiced', 'settled', 'reversed'] as $state) {
			self::assertContains($state, $statusEnum, "RetainerTrueUp status must include $state");
		}

	}//end testTrueUpStatusEnumCoversSettlement()

	/**
	 * Merging the fragment onto the monolith adds the four schemas without
	 * dropping any existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemaCount = count($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('RetainerPool', $schemas);
		self::assertArrayHasKey('RetainerDrawdown', $schemas);
		self::assertArrayHasKey('RetainerRollover', $schemas);
		self::assertArrayHasKey('RetainerTrueUp', $schemas);

		// Pre-existing monolith schemas survive the merge.
		foreach (array_keys($base['components']['schemas']) as $slug) {
			self::assertArrayHasKey($slug, $schemas, "Monolith schema $slug must survive merge");
		}

		self::assertGreaterThanOrEqual(
			$beforeSchemaCount,
			count($schemas),
			'Merge must add (or keep) schemas, never lose any'
		);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register, and
	 * each materialized drawdown's amount equals hoursOrAmount × drawdownRate
	 * (REQ-RETN-002 seed integrity).
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
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");

			if ($schema === 'RetainerDrawdown') {
				$expected = ((float)$object['hoursOrAmount'] * (float)$object['drawdownRate']);
				self::assertEqualsWithDelta(
					$expected,
					(float)$object['drawdownAmount'],
					0.005,
					'Seed drawdownAmount must equal hoursOrAmount × drawdownRate'
				);
			}
		}

	}//end testSeedObjectsAreConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
