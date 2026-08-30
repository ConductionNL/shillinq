<?php

/**
 * Unit tests for the btw-suppletie-detection register fragment.
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
 * @spec openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the btw-suppletie-detection fragment is valid JSON, additively
 * extends VatCorrection with the detection/compilation fields, does not
 * redeclare the REQ-VBTW-012 audit flag already landed in
 * add-shillinq-audit-trail.json, ships valid seeds, and merges onto the
 * monolith without dropping any existing schema (ADR-037).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BtwSuppletieDetectionFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/btw-suppletie-detection.json';

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
	 * VatCorrection gains the detection/compilation fields additively.
	 *
	 * @return void
	 */
	public function testVatCorrectionGainsDetectionFields(): void {
		$schema = $this->fragment()['components']['schemas']['VatCorrection'];
		foreach ([
			'filedSnapshot',
			'currentSnapshot',
			'categoryDeltas',
			'detectedAt',
			'preparedAt',
			'thresholdExceeded',
			'filingDeadline',
			'glCorrectionTransactionId',
		] as $field
		) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing additive field $field");
		}
	}//end testVatCorrectionGainsDetectionFields()

	/**
	 * REQ-VBTW-012 audit coverage for VatCorrection/VatReturn is already
	 * satisfied by add-shillinq-audit-trail.json's
	 * x-openregister-audit-trail.enabled flag (discovered during research —
	 * not something this change needs to add). This fragment therefore does
	 * NOT declare its own audit flag on either schema, avoiding a redundant/
	 * conflicting second declaration under a different key shape.
	 *
	 * @return void
	 */
	public function testFragmentDoesNotRedeclareAlreadyLandedAuditFlag(): void {
		$schema = $this->fragment()['components']['schemas']['VatCorrection'];
		self::assertArrayNotHasKey('x-openregister-audit-trail', $schema);
		self::assertArrayNotHasKey('VatReturn', $this->fragment()['components']['schemas']);

		$auditFragmentPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-audit-trail.json';
		$auditFragment = json_decode((string)file_get_contents($auditFragmentPath), true);
		$auditSchemas = $auditFragment['components']['schemas'];
		self::assertTrue($auditSchemas['VatCorrection']['x-openregister-audit-trail']['enabled'] ?? false);
		self::assertTrue($auditSchemas['VatReturn']['x-openregister-audit-trail']['enabled'] ?? false);
	}//end testFragmentDoesNotRedeclareAlreadyLandedAuditFlag()

	/**
	 * Seed objects reference the VatCorrection schema with unique slugs and
	 * an internally-consistent thresholdExceeded flag (one above, one below
	 * the €1.000 grens, per the design.md seed table).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$objects = $this->fragment()['components']['objects'];
		self::assertNotEmpty($objects);

		$slugs = [];
		$thresholds = [];
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertSame('VatCorrection', $object['@self']['schema']);
			$slugs[] = $object['@self']['slug'];
			$thresholds[] = $object['thresholdExceeded'];

			// Every seed's correctionAmount and adjustmentAmount agree (dual-field write, design.md Decision).
			self::assertSame($object['correctionAmount'], $object['adjustmentAmount']);
			self::assertSame($object['originalVatReturnId'], $object['originalReturnId']);
		}

		self::assertSame(count($slugs), count(array_unique($slugs)), 'Seed slugs must be unique');
		self::assertContains(true, $thresholds, 'At least one above-grens seed example expected');
		self::assertContains(false, $thresholds, 'At least one below-grens seed example expected');
	}//end testSeedObjectsAreConsistent()

	/**
	 * Merging the fragment onto the monolith adds the new VatCorrection
	 * fields without dropping any existing schema or field (ADR-037
	 * additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyWithoutCollision(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// No existing schema dropped, no new schema added (purely additive extension).
		self::assertSame($schemaCountBefore, count($schemas));
		foreach (array_keys($base['components']['schemas']) as $existing) {
			self::assertArrayHasKey($existing, $schemas, "Existing schema $existing must survive merge");
		}

		// The base VatCorrection's original fields survive alongside the new ones.
		$mergedCorrection = $schemas['VatCorrection']['properties'];
		self::assertArrayHasKey('originalVatReturnId', $mergedCorrection, 'Original field must survive merge');
		self::assertArrayHasKey('correctionAmount', $mergedCorrection, 'Original field must survive merge');
		self::assertArrayHasKey('filedSnapshot', $mergedCorrection, 'New field must be added');
	}//end testFragmentMergesAdditivelyWithoutCollision()
}//end class
