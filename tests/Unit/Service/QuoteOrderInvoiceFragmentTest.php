<?php

/**
 * Unit tests for the bookkeeping-quote-order-invoice register fragment.
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
 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
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
 * Verifies the Sales Funnel (Q2C) fragment is valid JSON, declares the twelve
 * Q2C schemas with their declarative lifecycle / calculation metadata, references
 * the lifecycle guard for cross-field preconditions, merges additively onto the
 * monolith (ADR-037), and ships seed objects that target only declared schemas.
 */
final class QuoteOrderInvoiceFragmentTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected, $message) arguments;
	// the custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-quote-order-invoice.json';

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
	 * The fragment declares the twelve Q2C schemas (REQ-QOI-001..010).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresTwelveSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = [
			'Quote',
			'QuoteLine',
			'SalesOrder',
			'SalesOrderLine',
			'Delivery',
			'Invoice',
			'InvoiceLine',
			'CreditNote',
			'PricingTier',
			'VolumeDiscount',
			'BlanketOrder',
			'CreditHold',
		];
		self::assertCount(count($expected), $schemas);
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
		}

	}//end testFragmentDeclaresTwelveSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on `status`
	 * (REQ-QOI-001/004/005/010).
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$expected = ['Quote', 'SalesOrder', 'Delivery', 'Invoice', 'CreditNote', 'CreditHold'];
		foreach ($expected as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('status', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

		// Line schemas carry no lifecycle (they are children of a stateful parent).
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schemas['QuoteLine']);
		self::assertArrayNotHasKey('x-openregister-lifecycle', $schemas['InvoiceLine']);

	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * Quantitative line / quantity logic is declarative (ADR-031): backorder and
	 * line-net are calculations, not PHP services.
	 *
	 * @return void
	 */
	public function testQuantitativeLogicIsDeclarative(): void {
		$schemas = $this->fragment()['components']['schemas'];

		self::assertArrayHasKey('x-openregister-calculations', $schemas['SalesOrderLine']);
		self::assertArrayHasKey('backorderQuantity', $schemas['SalesOrderLine']['x-openregister-calculations']);
		self::assertArrayHasKey('uninvoicedQuantity', $schemas['SalesOrderLine']['x-openregister-calculations']);

		self::assertArrayHasKey('x-openregister-calculations', $schemas['InvoiceLine']);
		self::assertArrayHasKey('lineNet', $schemas['InvoiceLine']['x-openregister-calculations']);
		self::assertArrayHasKey('lineVat', $schemas['InvoiceLine']['x-openregister-calculations']);

		self::assertArrayHasKey('remainingQuantity', $schemas['BlanketOrder']['x-openregister-calculations']);

	}//end testQuantitativeLogicIsDeclarative()

	/**
	 * Lifecycle transitions reference the QuoteOrderInvoiceGuard where a
	 * cross-field / cross-schema precondition is required (ADR-031 exception path).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionsReferenceGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$prefix = 'OCA\\Shillinq\\Lifecycle\\QuoteOrderInvoiceGuard::';

		$expected = [
			['Quote', 'send', $prefix . 'canSendQuote'],
			['Quote', 'accept', $prefix . 'canAcceptQuote'],
			['SalesOrder', 'confirm', $prefix . 'canConfirmOrder'],
			['Delivery', 'confirm', $prefix . 'canConfirmDelivery'],
			['Invoice', 'issue', $prefix . 'canIssueInvoice'],
			['CreditNote', 'issue', $prefix . 'canIssueCreditNote'],
			['CreditHold', 'release', $prefix . 'canReleaseCreditHold'],
		];
		foreach ($expected as [$schema, $transition, $requires]) {
			$tr = $schemas[$schema]['x-openregister-lifecycle']['transitions'][$transition];
			self::assertSame($requires, $tr['requires'], "$schema.$transition must guard via $requires");
		}

	}//end testLifecycleTransitionsReferenceGuard()

	/**
	 * A customer/contact is a Nextcloud entity referenced by FK — the fragment
	 * MUST NOT re-declare a Customer / Contact / Person schema (guardrail).
	 *
	 * @return void
	 */
	public function testNoCustomerSchemaIsReDeclared(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['Customer', 'Contact', 'Person', 'Klant', 'Betrokkene'] as $forbidden) {
			self::assertArrayNotHasKey($forbidden, $schemas, "Must not re-declare $forbidden — it is a Nextcloud entity");
		}

		// Quote / SalesOrder / Invoice reference the customer by FK.
		foreach (['Quote', 'SalesOrder', 'Invoice'] as $name) {
			self::assertArrayHasKey('customerReference', $schemas[$name]['properties']);
		}

	}//end testNoCustomerSchemaIsReDeclared()

	/**
	 * Merging the fragment onto the monolith adds the schemas without dropping
	 * any existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$beforeSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('Quote', $schemas);
		self::assertArrayHasKey('Invoice', $schemas);
		self::assertArrayHasKey('CreditHold', $schemas);

		// Every pre-existing schema survives the merge.
		foreach ($beforeSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive the merge");
		}

		// Seed objects are concatenated, not overwritten.
		self::assertGreaterThanOrEqual(
			count($frag['components']['objects']),
			count($merged['components']['objects'])
		);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas in the shillinq register
	 * (REQ-QOI seed-data pattern) and cover the quote -> order -> delivery ->
	 * invoice happy path plus edge cases (credit hold, credit note, blanket order).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);

		$coveredSchemas = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			$schema = $object['@self']['schema'];
			self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
			$coveredSchemas[$schema] = true;
		}

		foreach (['Quote', 'SalesOrder', 'Delivery', 'Invoice', 'CreditNote', 'CreditHold', 'PricingTier', 'BlanketOrder'] as $name) {
			self::assertArrayHasKey($name, $coveredSchemas, "Seed data must cover $name");
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * The Invoice lifecycle does not delete issued invoices: `cancel` is only
	 * reachable from `draft` and `credit` only from `sent` (REQ-QOI-007).
	 *
	 * @return void
	 */
	public function testIssuedInvoiceCannotBeCancelled(): void {
		$transitions = $this->fragment()['components']['schemas']['Invoice']['x-openregister-lifecycle']['transitions'];

		self::assertSame('draft', $transitions['cancel']['from']);
		self::assertSame('sent', $transitions['credit']['from']);

	}//end testIssuedInvoiceCannotBeCancelled()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
