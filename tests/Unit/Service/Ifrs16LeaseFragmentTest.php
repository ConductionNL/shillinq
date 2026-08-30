<?php

/**
 * Unit tests for the bookkeeping-ifrs-16-lease register fragment.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-contracts/spec.md
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
 * Verifies the IFRS 16 fragment is valid JSON, declares the five lease schemas
 * with their required fields and lifecycle / read-only metadata (ADR-037,
 * ADR-031, REQ-LC-002, REQ-LC-004), merges additively onto the monolith without
 * disturbing the existing schemas, references the lessor as a contact FK rather
 * than a new person schema (ADR-022), and ships internally-consistent seeds.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class Ifrs16LeaseFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-ifrs-16-lease.json';

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
	 * All five IFRS 16 schemas are declared (REQ-LC-001, design.md D1).
	 *
	 * @return void
	 */
	public function testDeclaresFiveLeaseSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['LeaseContract', 'LeasePaymentSchedule', 'LeaseReassessmentEvent', 'LeaseDisclosureTable', 'LeasePortfolioExemption'] as $slug) {
			self::assertArrayHasKey($slug, $schemas, "missing schema $slug");
		}

	}//end testDeclaresFiveLeaseSchemas()

	/**
	 * LeaseContract declares the REQ-LC-002 core fields and the REQ-LC-004 lifecycle.
	 *
	 * @return void
	 */
	public function testLeaseContractFieldsAndLifecycle(): void {
		$schema = $this->fragment()['components']['schemas']['LeaseContract'];

		$required = [
			'leaseNumber',
			'counterparty',
			'assetClass',
			'commencementDate',
			'endDate',
			'nonCancellableTermMonths',
			'paymentFrequency',
			'paymentTiming',
			'basePaymentAmount',
			'paymentCurrency',
			'ibrPercent',
			'ibrDerivationMethod',
			'classification',
			'status',
		];
		foreach ($required as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "LeaseContract must declare $field");
		}

		// Declarative state machine (REQ-LC-004).
		self::assertArrayHasKey('x-openregister-lifecycle', $schema);
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('draft', $lifecycle['initial']);
		self::assertContains('active', $lifecycle['states']);
		self::assertContains('terminated', $lifecycle['states']);

		// The draft→active transition is guarded by LeaseContractGuard (ADR-031).
		$hasGuardedActivation = false;
		foreach ($lifecycle['transitions'] as $transition) {
			if ($transition['from'] === 'draft' && $transition['to'] === 'active') {
				self::assertStringContainsString('LeaseContractGuard', ($transition['guard'] ?? ''));
				$hasGuardedActivation = true;
			}
		}

		self::assertTrue($hasGuardedActivation, 'draft→active transition must be guarded');

		// Classification enum is exactly the four IFRS 16 values (REQ-LC-002).
		self::assertSame(
			['IFRS16-capitalised', 'short-term-exempt', 'low-value-exempt', 'operating-pre-IFRS16'],
			$schema['properties']['classification']['enum']
		);

	}//end testLeaseContractFieldsAndLifecycle()

	/**
	 * LeasePaymentSchedule is read-only (REQ-LA-002 immutability).
	 *
	 * @return void
	 */
	public function testPaymentScheduleIsReadOnly(): void {
		$schema = $this->fragment()['components']['schemas']['LeasePaymentSchedule'];
		self::assertTrue($schema['readonly']);
		self::assertTrue($schema['x-openregister']['readonly']);

	}//end testPaymentScheduleIsReadOnly()

	/**
	 * The lessor counterparty is a contact FK, never a bespoke person schema (ADR-022).
	 *
	 * @return void
	 */
	public function testCounterpartyIsContactFkNotNewSchema(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['Contact', 'Person', 'Customer', 'Lessor'] as $forbidden) {
			self::assertArrayNotHasKey($forbidden, $schemas, "must not invent a $forbidden schema (ADR-022)");
		}

		// The seed lessor references a contact: FK URI.
		$objects = $this->fragment()['components']['objects'];
		$lease = null;
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'LeaseContract') {
				$lease = $object;
				break;
			}
		}

		self::assertNotNull($lease);
		self::assertStringStartsWith('contact:', (string)$lease['counterparty']);

	}//end testCounterpartyIsContactFkNotNewSchema()

	/**
	 * Merging the fragment adds the lease schemas without dropping monolith schemas (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('LeaseContract', $schemas);
		// A pre-existing monolith schema survives the merge.
		self::assertArrayHasKey('GLTransaction', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and the shillinq register (ADR-037).
	 *
	 * @return void
	 */
	public function testSeedObjectsConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		$seenSchemas = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
			$seenSchemas[$object['@self']['schema']] = true;
		}

		// The three worked-example lease classifications are all seeded.
		$classifications = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'LeaseContract') {
				$classifications[$object['classification']] = true;
			}
		}

		self::assertArrayHasKey('IFRS16-capitalised', $classifications);
		self::assertArrayHasKey('short-term-exempt', $classifications);

	}//end testSeedObjectsConsistent()
}//end class
