<?php

/**
 * The register configuration a fresh install imports: every schema imports and
 * the seeds a fresh install skipped satisfy their schema.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reads the merged configuration the way SettingsService builds it.
 */
final class FreshInstallSeedsTest extends TestCase {
	/**
	 * Property keys OpenRegister's property vocabulary refuses, which made a whole schema fail to import.
	 */
	private const REFUSED_PROPERTY_KEYS = ['pii', 'guard', '_note'];

	/**
	 * Seeds a fresh install skipped for a missing required property or a non-uuid reference.
	 */
	private const SEEDS = [
		'vpl-sample-utrecht-schoonmaak', 'deposit-event-2026-05-30-failed', 'deposit-portrait-2026-06-15-authorized',
		'deposit-wedding-2026-08-02-pending', 'gr-ict-2026-sample-waste-service', 'bookings-d2i-deposit-dp-5001',
		'bcf-claim-amsterdam-2025q1', 'bcf-claim-amsterdam-2025q2', 'bcf-claim-utrecht-2025q2', 'bcf-claim-utrecht-2025q3',
		'cbs-line-2025-001-costs', 'cbs-line-2025-001-depreciation', 'cbs-line-2025-001-revenue',
		'cbs-sub-2025-org-001', 'cbs-sub-2025-org-002', 'cbs-sub-2026-org-003',
		'cc-admin-amsterdam', 'cc-logistics-rotterdam', 'cc-sales-utrecht', 'dim-productline', 'dim-region',
		'proj-grant-research', 'proj-internal-platform', 'bal-eur-2026-05-21', 'bal-gbp-2026-05-21', 'bal-usd-2026-05-21',
		'bbv-prov-glline-cultuur-materialen', 'bbv-prov-glline-economie-contracten', 'bbv-prov-glline-mobiliteit-personeel',
		'bbv-prov-glline-water-onmapped', 'bbv-prov-glline-water-personeel', 'qoi-sample-creditnote-return',
		'qoi-sample-invoice-industrial-advance', 'xbrl-map-2026-acct-1000-current-assets',
		'xbrl-map-2026-acct-4000-operating-revenue', 'balance-sheet-adm-001-2025', 'requisition-2026-0001-line-1',
		'requisition-2026-0002-line-1', 'requisition-2026-0003', 'requisition-2026-0003-line-1',
		'supplier-invoice-draft-0001', 'supplier-invoice-draft-0002', 'gl-line-bankrecon-1-debit', 'gl-line-bankrecon-2-credit',
		'gl-line-payroll-1-debit-salaries', 'gl-line-payroll-2-credit-net', 'gl-line-payroll-3-credit-tax',
		'sales-order-2026-0001', 'sales-order-line-2026-0001-a', 'sales-order-line-2026-0001-b', 'sales-order-line-2026-0001-c',
	];

	/**
	 * The merged configuration.
	 *
	 * @var array<string, mixed>
	 */
	private static array $merged = [];

	/**
	 * Every seed object by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $seeds = [];

	public static function setUpBeforeClass(): void {
		$settings = dirname(__DIR__, 3).'/lib/Settings';
		$merge    = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files    = glob($settings.'/register.d/*.json') ?: [];
		sort($files);

		$merged = [];
		foreach (array_merge([$settings.'/shillinq_register.json'], $files) as $file) {
			$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach ([($data['components']['objects'] ?? []), ($data['objects'] ?? [])] as $bucket) {
				foreach ($bucket as $object) {
					if (is_array($object) === true && isset($object['@self']['slug']) === true) {
						self::$seeds[$object['@self']['slug']] = $object;
					}
				}
			}

			$merged = $merge->invoke(null, $merged, $data);
		}

		self::$merged = $merged;
	}

	/**
	 * The merged definition of a schema.
	 *
	 * @param string $name The schema key.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(string $name): array {
		return (self::$merged['components']['schemas'][$name] ?? self::$merged['schemas'][$name] ?? []);
	}

	public function testNoSchemaPropertyCarriesAKeyTheVocabularyRefuses(): void {
		foreach (['payrollEmployee', 'ApprovalStep'] as $name) {
			foreach ($this->schema($name)['properties'] as $property => $definition) {
				$refused = array_intersect(self::REFUSED_PROPERTY_KEYS, array_keys($definition));
				$this->assertSame([], array_values($refused), $name.'/'.$property);
			}
		}

		$this->assertSame('bsn', $this->schema('payrollEmployee')['properties']['bsn']['format']);
	}

	public function testEverySkippedSeedCarriesItsRequiredPropertiesAndUuidReferences(): void {
		$uuids = [];
		foreach (self::$seeds as $seed) {
			if (isset($seed['@self']['id']) === true) {
				$uuids[$seed['@self']['id']] = true;
			}
		}

		foreach (self::SEEDS as $slug) {
			$this->assertArrayHasKey($slug, self::$seeds);
			$seed   = self::$seeds[$slug];
			$schema = $this->schema($seed['@self']['schema']);
			foreach (array_unique($schema['required'] ?? []) as $required) {
				$this->assertNotNull($seed[$required] ?? null, $slug.' lacks '.$required);
			}

			foreach ($schema['properties'] as $property => $definition) {
				$value = $seed[$property] ?? null;
				if (is_string($value) === true && ($definition['format'] ?? '') === 'uuid') {
					$this->assertArrayHasKey($value, $uuids, $slug.'.'.$property.' names no seed by uuid');
				}

				if (is_string($value) === true && isset($definition['pattern']) === true) {
					$this->assertMatchesRegularExpression('~'.str_replace('~', '\~', $definition['pattern']).'~u', $value, $slug.'.'.$property);
				}
			}
		}
	}

	public function testEveryDemoBarcodeNamesItsProduct(): void {
		$demo     = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/seeds/inventory-barcodes-demo.json'), true);
		$required = $this->schema('Barcode')['required'];

		foreach ($demo['barcodes'] as $barcode) {
			foreach ($required as $property) {
				$this->assertArrayHasKey($property, $barcode, $barcode['barcode'].' lacks '.$property);
			}
		}
	}
}
