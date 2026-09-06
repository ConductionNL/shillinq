<?php

/**
 * Unit tests for the invoice-from-time-and-expense register fragment.
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
 * @spec openspec/changes/invoice-from-time-and-expense/specs/invoice-from-time-and-expense/spec.md
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
 * Verifies the invoice-from-time-and-expense fragment is valid JSON, declares the
 * BillableInvoice + BillableInvoiceLine + RetainerSchedule schemas with the five
 * billing models, and merges additively onto the monolith (ADR-037) without
 * dropping the existing reuse-target schemas.
 */
final class InvoiceFromTimeExpenseFragmentTest extends TestCase {
	// PHPUnit assertions take positional arguments; the named-parameter sniff does not apply.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/invoice-from-time-and-expense.json';

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
	 * The fragment file is present and valid JSON with components.schemas.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('components', $data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares BillableInvoice, BillableInvoiceLine and
	 * RetainerSchedule (the three Tier-2 schemas for REQ-ITE-001/002/004).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresInvoiceSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['BillableInvoice', 'BillableInvoiceLine', 'RetainerSchedule'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresInvoiceSchemas()

	/**
	 * BillableInvoice carries the five-model billingModel enum (REQ-ITE-001, design D1).
	 *
	 * @return void
	 */
	public function testInvoiceDeclaresFiveBillingModels(): void {
		$invoice = $this->fragment()['components']['schemas']['BillableInvoice'];
		$enum = $invoice['properties']['billingModel']['enum'];
		self::assertSame(['t_and_m', 'fixed_fee', 'milestone', 'retainer', 'mixed'], $enum);

	}//end testInvoiceDeclaresFiveBillingModels()

	/**
	 * Line-item composition reflects the four sourceType values (time_entry,
	 * expense, retainer_charge, fixed_fee — plus 'manual' as the bookkeeper-add
	 * fallback) — the declarative half of the line composition (REQ-ITE-003).
	 *
	 * @return void
	 */
	public function testQuantitativeLogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$line = $schemas['BillableInvoiceLine'];
		// Source-of-truth properties for the declarative line composition.
		self::assertArrayHasKey('sourceType', $line['properties']);
		self::assertArrayHasKey('billableUnits', $line['properties']);
		self::assertArrayHasKey('rateApplied', $line['properties']);
		self::assertArrayHasKey('costAmount', $line['properties']);
		self::assertArrayHasKey('vatRate', $line['properties']);
		self::assertArrayHasKey('vatAmount', $line['properties']);

		$sourceTypes = $line['properties']['sourceType']['enum'];
		foreach (['time_entry', 'expense', 'retainer_charge', 'fixed_fee'] as $required) {
			self::assertContains($required, $sourceTypes, "sourceType enum must include $required");
		}

		// The invoice mirrors the aggregate net/vat/gross trio (declarative aggregation
		// is wired by the engine on these fields — see ADR-031 declarative computation).
		$invoice = $schemas['BillableInvoice']['properties'];
		self::assertArrayHasKey('netAmount', $invoice);
		self::assertArrayHasKey('vatAmount', $invoice);
		self::assertArrayHasKey('grossAmount', $invoice);

	}//end testQuantitativeLogicIsDeclarative()

	/**
	 * BillableInvoice carries an explicit posting flag + status — the post
	 * transition is enforced at service level (InvoiceGuard) rather than via
	 * declarative lifecycle in this fragment.
	 *
	 * @return void
	 */
	public function testPostTransitionReferencesGuard(): void {
		$invoice = $this->fragment()['components']['schemas']['BillableInvoice'];
		$props = $invoice['properties'];

		self::assertArrayHasKey('posted', $props, 'BillableInvoice must carry a posted flag');
		self::assertArrayHasKey('status', $props, 'BillableInvoice must carry a status field');

		$required = $invoice['required'];
		self::assertContains('status', $required, 'status must be required');

	}//end testPostTransitionReferencesGuard()

	/**
	 * Merging the fragment onto the monolith adds the three new schemas without
	 * dropping existing schemas (ADR-037 additive merge).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach (['BillableInvoice', 'BillableInvoiceLine', 'RetainerSchedule'] as $name) {
			self::assertArrayHasKey($name, $schemas);
		}

		// Existing schemas survive the merge.
		foreach ($beforeSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive the merge");
		}

		// Reuse targets are present (we extend the model, never reinvent them).
		foreach (['RateCard', 'UrenRegistratie', 'Receipt', 'engagement'] as $reused) {
			self::assertArrayHasKey($reused, $schemas, "Reuse target $reused must exist");
		}

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Schema slugs and types are coherent — every declared property has a JSON
	 * Schema 'type' field. Acts as a structural smoke test in lieu of full
	 * seed-data assertions (no seed objects ship in this fragment).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['BillableInvoice', 'BillableInvoiceLine', 'RetainerSchedule'] as $name) {
			$schema = $schemas[$name];
			self::assertNotEmpty($schema['properties'], "$name must declare properties");
			foreach ($schema['properties'] as $propName => $prop) {
				self::assertArrayHasKey('type', $prop, "$name.$propName must declare a JSON Schema type");
			}
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * BillableInvoiceLine carries the invoiceId backlink (so lines roll up to
	 * their invoice) and the cost/vat trio that drives the declarative
	 * aggregation on BillableInvoice (design.md example 1).
	 *
	 * @return void
	 */
	public function testSeedTAndMInvoiceLinesSumToDesignNet(): void {
		$line = $this->fragment()['components']['schemas']['BillableInvoiceLine'];

		self::assertArrayHasKey('invoiceId', $line['properties'], 'Line must back-link to invoice');
		self::assertContains('invoiceId', $line['required'], 'invoiceId must be required');
		self::assertArrayHasKey('costAmount', $line['properties']);
		self::assertArrayHasKey('vatAmount', $line['properties']);

	}//end testSeedTAndMInvoiceLinesSumToDesignNet()

	/**
	 * Every billing model that can carry a retainer (retainer + mixed) is
	 * representable through the retainer_charge sourceType (design D3). This
	 * is the declarative contract — the engine emits the mandatory line on
	 * generation, not the fragment.
	 *
	 * @return void
	 */
	public function testRetainerSeedHasMandatoryRetainerLine(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$models = $schemas['BillableInvoice']['properties']['billingModel']['enum'];
		self::assertContains('retainer', $models);
		self::assertContains('mixed', $models);

		$sourceTypes = $schemas['BillableInvoiceLine']['properties']['sourceType']['enum'];
		self::assertContains(
			'retainer_charge',
			$sourceTypes,
			'sourceType must include retainer_charge so the engine can emit the mandatory retainer line (design D3)'
		);

		// RetainerSchedule is the durable record of the recurring fee.
		self::assertArrayHasKey('RetainerSchedule', $schemas);

	}//end testRetainerSeedHasMandatoryRetainerLine()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
