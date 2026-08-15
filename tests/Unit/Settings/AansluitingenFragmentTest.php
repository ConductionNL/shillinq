<?php

/**
 * Unit tests for the bookkeeping-aansluitingen register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the bookkeeping-aansluitingen fragment is valid JSON, declares the
 * Aansluiting + AansluitingResult schemas with their lifecycle/aggregations/
 * RBAC blocks, ships internally-consistent seeds, and merges onto the
 * monolith additively (ADR-037 — no existing schema is dropped).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingenFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-aansluitingen.json';

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
	 * Load and decode the fragment JSON.
	 *
	 * @return array<mixed> The decoded fragment.
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with a components.schemas
	 * + components.objects block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * Aansluiting declares the framework's definition fields + RBAC.
	 *
	 * @return void
	 */
	public function testAansluitingSchemaShape(): void {
		$schema = $this->fragment()['components']['schemas']['Aansluiting'];
		foreach ([
			'name',
			'reconciliationType',
			'toleranceCents',
			'expectedRelationship',
			'controlAccountNumber',
			'subLedgerType',
			'active',
			'administrationId',
		] as $field
		) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing field $field");
		}

		self::assertContains('vat-ledger-return', $schema['properties']['reconciliationType']['enum']);
		self::assertContains('subledger-gl-control', $schema['properties']['reconciliationType']['enum']);
		self::assertArrayHasKey('x-openregister-rbac', $schema);
	}//end testAansluitingSchemaShape()

	/**
	 * AansluitingResult declares the open -> explained -> resolved lifecycle,
	 * the guard reference, aggregations, and audit trail.
	 *
	 * @return void
	 */
	public function testAansluitingResultSchemaShape(): void {
		$schema = $this->fragment()['components']['schemas']['AansluitingResult'];

		foreach ([
			'reconciliationId',
			'periodId',
			'sourceATotal',
			'sourceBTotal',
			'differenceCents',
			'withinTolerance',
			'status',
			'lineDeltas',
			'explainedBy',
			'explanationReasonCode',
			'explanationReasonText',
			'resolvedBy',
			'relatedVatCorrectionId',
		] as $field
		) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing field $field");
		}

		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('open', $lifecycle['initialState']);
		self::assertArrayHasKey('open', $lifecycle['states']);
		self::assertArrayHasKey('explained', $lifecycle['states']);
		self::assertArrayHasKey('resolved', $lifecycle['states']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\AansluitingResolutionGuard::canResolve',
			$lifecycle['transitions']['resolve']['guard']
		);

		self::assertTrue($schema['x-openregister-audit-trail']['enabled'] ?? false);
		self::assertArrayHasKey('openCountByAdministration', $schema['x-openregister-aggregations']);
	}//end testAansluitingResultSchemaShape()

	/**
	 * Seed objects reference the correct schemas with unique slugs, and the
	 * two subledger-gl-control seeds demonstrate both AR (equal) and AP
	 * (equal-with-sign-flip) relationships (design.md seed table).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$objects = $this->fragment()['components']['objects'];
		self::assertNotEmpty($objects);

		$slugs = [];
		$reconciliationTypes = [];
		$relationships = [];
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertContains($object['@self']['schema'], ['Aansluiting', 'AansluitingResult']);
			$slugs[] = $object['@self']['slug'];

			if ($object['@self']['schema'] === 'Aansluiting') {
				$reconciliationTypes[] = $object['reconciliationType'];
				$relationships[] = $object['expectedRelationship'];
			}
		}

		self::assertSame(count($slugs), count(array_unique($slugs)), 'Seed slugs must be unique');
		self::assertContains('vat-ledger-return', $reconciliationTypes);
		self::assertContains('subledger-gl-control', $reconciliationTypes);
		self::assertContains('equal', $relationships);
		self::assertContains('equal-with-sign-flip', $relationships);
	}//end testSeedObjectsAreConsistent()

	/**
	 * Merging the fragment onto the monolith adds exactly two new schemas
	 * (Aansluiting, AansluitingResult) without dropping any existing schema
	 * (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyWithoutCollision(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertSame(($schemaCountBefore + 2), count($schemas), 'Exactly two new schemas expected');
		foreach (array_keys($base['components']['schemas']) as $existing) {
			self::assertArrayHasKey($existing, $schemas, "Existing schema $existing must survive merge");
		}

		self::assertArrayHasKey('Aansluiting', $schemas);
		self::assertArrayHasKey('AansluitingResult', $schemas);
	}//end testFragmentMergesAdditivelyWithoutCollision()
}//end class
