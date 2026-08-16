<?php

/**
 * Unit tests asserting the DepositPayment register fragment is well-formed (ADR-037).
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
 * @spec openspec/changes/bookings-deposits/specs/bookings-deposits/spec.md (REQ-DP-001..REQ-DP-011)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the DepositPayment schema fragment declares the full lifecycle,
 * calculations and seed data the spec requires, and stores no card data.
 */
final class DepositPaymentFragmentTest extends TestCase {
	/**
	 * Decoded fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Decoded DepositPayment schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the fragment from disk.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/50-bookings-deposits.json';
		$content = file_get_contents($path);
		self::assertNotFalse($content, 'fragment file must be readable');
		$this->fragment = json_decode($content, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'fragment must be valid JSON');
		$this->schema = $this->fragment['components']['schemas']['DepositPayment'];
	}//end setUp()

	/**
	 * The schema declares the required deposit fields and the EUR-cents amount.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresRequiredFields(): void {
		$required = $this->schema['required'];
		foreach (['orderId', 'bookingTypeId', 'amount', 'currencyCode', 'state', 'refundPolicy'] as $field) {
			self::assertContains($field, $required, $field . ' must be required');
		}

		self::assertSame('integer', $this->schema['properties']['amount']['type']);
		self::assertSame('EUR', $this->schema['properties']['currencyCode']['default']);
	}//end testSchemaDeclaresRequiredFields()

	/**
	 * The lifecycle covers every state and the key transitions the spec mandates.
	 *
	 * @return void
	 */
	public function testLifecycleStatesAndTransitions(): void {
		$lifecycle = $this->schema['x-openregister-lifecycle'];
		self::assertSame('state', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'pending', 'authorized', 'captured', 'failed', 'voided'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], $state . ' state must exist');
		}

		// authorize transition materialises the AR invoice (REQ-DP-003).
		$authorize = $lifecycle['transitions']['authorize'];
		self::assertSame('pending', $authorize['from']);
		self::assertSame('authorized', $authorize['to']);
		self::assertTrue($authorize['idempotent']);
		self::assertSame('materialize-ar-invoice', $authorize['action']['type']);
		self::assertSame('arInvoiceId', $authorize['action']['writesBack']);

		// void transition materialises the credit note (REQ-DP-008).
		$void = $lifecycle['transitions']['voidFromAuthorized'];
		self::assertSame('materialize-ar-credit-note', $void['action']['type']);
	}//end testLifecycleStatesAndTransitions()

	/**
	 * Calculations declare dueDate, VAT split and a payment link (REQ-DP-002/005).
	 *
	 * @return void
	 */
	public function testCalculationsDeclared(): void {
		$calc = $this->schema['x-openregister-calculations'];
		foreach (['dueDate', 'vatAmount', 'netAmount', 'paymentLink', 'isOverdue'] as $key) {
			self::assertArrayHasKey($key, $calc, $key . ' calculation must exist');
			self::assertArrayHasKey('expression', $calc[$key]);
		}
	}//end testCalculationsDeclared()

	/**
	 * A polling-fallback scheduled workflow is declared, not an app-local job (ADR-031, REQ-DP-007).
	 *
	 * @return void
	 */
	public function testPollingFallbackWorkflowDeclared(): void {
		$workflows = $this->schema['x-openregister-scheduled-workflows'];
		self::assertArrayHasKey('shillinq-deposit-polling-fallback', $workflows);
		$wf = $workflows['shillinq-deposit-polling-fallback'];
		self::assertSame('*/5 * * * *', $wf['cron']);
		self::assertSame(['state' => 'pending'], $wf['filter']);
	}//end testPollingFallbackWorkflowDeclared()

	/**
	 * The schema stores no card data — only an opaque paymentIntentId (REQ-DP-001).
	 *
	 * @return void
	 */
	public function testNoCardDataInSchema(): void {
		$props = array_keys($this->schema['properties']);
		foreach (['cardNumber', 'pan', 'cvv', 'cvc', 'expiry', 'cardToken'] as $forbidden) {
			self::assertNotContains($forbidden, $props, $forbidden . ' must NOT be a stored field');
		}

		self::assertArrayHasKey('paymentIntentId', $this->schema['properties']);
	}//end testNoCardDataInSchema()

	/**
	 * Seed objects are present and reference the shillinq register / DepositPayment schema.
	 *
	 * @return void
	 */
	public function testSeedObjectsPresentAndScoped(): void {
		$objects = $this->fragment['objects'];
		self::assertGreaterThanOrEqual(3, count($objects));
		foreach ($objects as $obj) {
			self::assertSame('shillinq', $obj['@self']['register']);
			self::assertSame('DepositPayment', $obj['@self']['schema']);
			// Seed data must not carry card data either.
			self::assertArrayNotHasKey('cardNumber', $obj);
			self::assertArrayNotHasKey('cvv', $obj);
		}
	}//end testSeedObjectsPresentAndScoped()
}//end class
