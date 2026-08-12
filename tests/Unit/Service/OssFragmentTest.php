<?php

/**
 * Unit tests for the bookkeeping-btw-oss-eu register fragment.
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
 * @spec openspec/changes/bookkeeping-btw-oss-eu/specs/bookkeeping-btw-oss-eu/spec.md
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
 * Verifies the OSS fragment is valid JSON, declares the five OSS schemas, extends
 * the Invoice schema additively with ossContext without disturbing the existing
 * Invoice properties (ADR-037 key-union merge), seeds the EuVatRate table and
 * example registrations/returns, and that the seeded OssReturn totals equal the
 * sum of its line VAT amounts (REQ-OSS-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OssFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-btw-oss-eu.json';

	/**
	 * Absolute path to the sibling fragment that owns the Invoice schema.
	 *
	 * @var string
	 */
	private string $invoiceFragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookings-deposit-to-invoice.json';

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
	 * The fragment declares the five OSS schemas (REQ-OSS-002, REQ-OSS-004, REQ-OSS-012).
	 *
	 * @return void
	 */
	public function testDeclaresFiveOssSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['OssRegistration', 'EuVatRate', 'OssThresholdCounter', 'OssReturn', 'OssPayment'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

		// EuVatRate and OssThresholdCounter are read-only derived data.
		self::assertTrue($schemas['EuVatRate']['readonly']);
		self::assertTrue($schemas['OssThresholdCounter']['readonly']);

	}//end testDeclaresFiveOssSchemas()

	/**
	 * EuVatRate and OssReturn carry the documented declarative aggregations (ADR-031).
	 *
	 * @return void
	 */
	public function testDeclaresAggregations(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('rateByCountryCategoryDate', $schemas['EuVatRate']['x-openregister-aggregations']);
		self::assertArrayHasKey('linesByCountryRate', $schemas['OssReturn']['x-openregister-aggregations']);
		self::assertArrayHasKey('b2cEuTurnoverByYear', $schemas['OssThresholdCounter']['x-openregister-aggregations']);

	}//end testDeclaresAggregations()

	/**
	 * OssReturn and OssRegistration declare lifecycles with the expected states (REQ-OSS-013).
	 *
	 * @return void
	 */
	public function testDeclaresLifecycles(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$returnStates = $schemas['OssReturn']['x-openregister-lifecycle']['states'];
		foreach (['draft', 'submitted', 'accepted', 'rejected', 'paid', 'corrected'] as $state) {
			self::assertArrayHasKey($state, $returnStates, "OssReturn must declare state $state");
		}

		$regStates = $schemas['OssRegistration']['x-openregister-lifecycle']['states'];
		self::assertArrayHasKey('voluntaryBelowThreshold', $regStates);

	}//end testDeclaresLifecycles()

	/**
	 * The fragment extends Invoice with ossContext additively, preserving the
	 * Invoice schema owned by bookings-deposit-to-invoice (ADR-037, REQ-OSS-014).
	 *
	 * @return void
	 */
	public function testInvoiceOssContextMergesAdditively(): void {
		// Build the same ordered merge the loader performs: monolith, then the
		// Invoice-owning fragment, then this OSS fragment.
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$invoice = json_decode((string)file_get_contents($this->invoiceFragmentPath), true);

		$merged = $this->merge($base, $invoice);
		$merged = $this->merge($merged, $this->fragment());

		$invoiceSchema = $merged['components']['schemas']['Invoice'];
		// Pre-existing Invoice properties survive the merge.
		self::assertArrayHasKey('invoiceNumber', $invoiceSchema['properties']);
		self::assertArrayHasKey('netAmount', $invoiceSchema['properties']);
		// The ossContext property is added.
		self::assertArrayHasKey('ossContext', $invoiceSchema['properties']);
		$oss = $invoiceSchema['properties']['ossContext']['properties'];
		foreach (['destinationCountry', 'appliedVatRate', 'appliedRateCategory', 'tedbRateVersion', 'ossEligible', 'ossReportingPeriod'] as $field) {
			self::assertArrayHasKey($field, $oss, "ossContext must declare $field");
		}

	}//end testInvoiceOssContextMergesAdditively()

	/**
	 * Seed objects target only declared schemas and the seeded OssReturn balances
	 * (REQ-OSS-004: total VAT equals the sum of line VAT amounts).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		$hasRate = false;
		$hasReturn = false;
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			// Invoice is extended (not declared) by this fragment; OSS seeds target OSS schemas.
			self::assertArrayHasKey($schema, $schemas, "Seed targets declared schema $schema");

			if ($schema === 'EuVatRate') {
				$hasRate = true;
			}

			if ($schema === 'OssReturn') {
				$hasReturn = true;
				$sumVat = 0;
				foreach ($object['lineItems'] as $line) {
					$sumVat += (int)round(((float)$line['vatAmount']) * 100);
				}

				self::assertSame(
					(int)round(((float)$object['totalVatAmount']) * 100),
					$sumVat,
					'Seeded OssReturn totalVatAmount must equal the sum of line VAT amounts'
				);
			}
		}//end foreach

		self::assertTrue($hasRate, 'Fragment must seed EuVatRate TEDB data');
		self::assertTrue($hasReturn, 'Fragment must seed an example OssReturn');

	}//end testSeedObjectsAreConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
