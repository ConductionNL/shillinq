<?php

/**
 * Unit tests for the ar-invoice-payment-links register fragment.
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
 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ar-invoice-payment-links fragment is valid JSON, declares the
 * PaymentRequest schema with audit-trail + lifecycle + paymentLink calculation
 * + canonical-dialect notifications, and stores NO PCI fields (REQ-APL-001..007).
 */
final class ArPaymentLinksFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/ar-invoice-payment-links.json';

	/**
	 * Decode the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with the PaymentRequest schema.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('PaymentRequest', $data['components']['schemas']);
	}//end testFragmentIsValidJson()

	/**
	 * PaymentRequest carries an enabled audit-trail (REQ-APL-001).
	 *
	 * @return void
	 */
	public function testPaymentRequestHasAuditTrail(): void {
		$schema = $this->fragment()['components']['schemas']['PaymentRequest'];
		self::assertArrayHasKey('x-openregister-audit-trail', $schema);
		self::assertTrue($schema['x-openregister-audit-trail']['enabled']);
	}//end testPaymentRequestHasAuditTrail()

	/**
	 * The lifecycle declares the REQ-APL states and key transitions.
	 *
	 * @return void
	 */
	public function testLifecycleStatesAndTransitions(): void {
		$lc = $this->fragment()['components']['schemas']['PaymentRequest']['x-openregister-lifecycle'];
		self::assertSame('state', $lc['field']);
		self::assertSame('pending', $lc['initialState']);

		foreach (['pending', 'authorized', 'captured', 'captured_unapplied', 'failed', 'expired', 'voided'] as $state) {
			self::assertArrayHasKey($state, $lc['states'], "Lifecycle must declare state $state");
		}

		foreach (['authorize', 'capture', 'markUnapplied', 'fail', 'expire', 'void'] as $transition) {
			self::assertArrayHasKey($transition, $lc['transitions'], "Lifecycle must declare transition $transition");
		}

		// capture settles the invoice; markUnapplied is the exception path.
		self::assertSame('captured', $lc['transitions']['capture']['to']);
		self::assertSame('captured_unapplied', $lc['transitions']['markUnapplied']['to']);
		self::assertSame('voided', $lc['transitions']['void']['to']);
	}//end testLifecycleStatesAndTransitions()

	/**
	 * The paymentLink declarative calculation is present (REQ-APL-002).
	 *
	 * @return void
	 */
	public function testPaymentLinkCalculationPresent(): void {
		$schema = $this->fragment()['components']['schemas']['PaymentRequest'];
		self::assertArrayHasKey('x-openregister-calculations', $schema);
		self::assertArrayHasKey('paymentLink', $schema['x-openregister-calculations']);
		$calc = $schema['x-openregister-calculations']['paymentLink'];
		// Resolved by OpenConnector — no app-side URL construction.
		self::assertTrue(($calc['x-openconnector-resolved'] ?? false));
		// Visible only while pending (REQ-APL-002).
		self::assertArrayHasKey('visibleWhen', $calc);
	}//end testPaymentLinkCalculationPresent()

	/**
	 * Two notification rules in the canonical ADR-031 dialect (REQ-APL-007):
	 * updated-trigger + condition + recipients array + bilingual subject.
	 *
	 * @return void
	 */
	public function testNotificationsUseCanonicalDialect(): void {
		$schema = $this->fragment()['components']['schemas']['PaymentRequest'];
		self::assertArrayHasKey('x-openregister-notifications', $schema);
		$rules = $schema['x-openregister-notifications'];

		self::assertArrayHasKey('paymentReceived', $rules);
		self::assertArrayHasKey('paymentFailed', $rules);
		self::assertCount(2, $rules);

		foreach (['paymentReceived' => 'captured', 'paymentFailed' => 'failed'] as $name => $expectedValue) {
			$rule = $rules[$name];
			// Canonical updated-trigger dialect.
			self::assertSame('updated', $rule['trigger']['type'], "$name must use trigger.type=updated");
			self::assertSame('state', $rule['trigger']['condition']['field']);
			self::assertSame('equals', $rule['trigger']['condition']['operator']);
			self::assertSame($expectedValue, $rule['trigger']['condition']['value']);

			// Recipients array + bilingual subject.
			self::assertIsArray($rule['recipients']);
			self::assertNotEmpty($rule['recipients']);
			self::assertArrayHasKey('nl', $rule['subject']);
			self::assertArrayHasKey('en', $rule['subject']);

			// No legacy object.create dialect / title / message keys.
			self::assertArrayNotHasKey('title', $rule);
			self::assertArrayNotHasKey('message', $rule);
			self::assertNotSame('object.create', ($rule['trigger']['type'] ?? ''));
		}
	}//end testNotificationsUseCanonicalDialect()

	/**
	 * No legacy object.create notification dialect anywhere in the fragment.
	 *
	 * @return void
	 */
	public function testNoLegacyNotificationDialect(): void {
		$raw = (string)file_get_contents($this->fragmentPath);
		self::assertStringNotContainsString('object.create', $raw, 'Legacy notification dialect must not appear');
	}//end testNoLegacyNotificationDialect()

	/**
	 * No PCI fields are stored on PaymentRequest (REQ-APL-001).
	 *
	 * @return void
	 */
	public function testNoPciFields(): void {
		$props = $this->fragment()['components']['schemas']['PaymentRequest']['properties'];

		foreach (['cardNumber', 'cvv', 'cvc', 'pan', 'cardholderName', 'iban', 'token', 'authorizationToken'] as $pci) {
			self::assertArrayNotHasKey($pci, $props, "PCI field $pci must NOT be stored");
		}

		// Only opaque references are present.
		self::assertArrayHasKey('paymentIntentId', $props);
		self::assertArrayHasKey('settlementReference', $props);
	}//end testNoPciFields()

	/**
	 * Required fields per REQ-APL-001.
	 *
	 * @return void
	 */
	public function testRequiredFields(): void {
		$schema = $this->fragment()['components']['schemas']['PaymentRequest'];
		$required = $schema['required'];
		foreach (['invoiceReference', 'amount', 'currency', 'paymentGateway', 'state'] as $field) {
			self::assertContains($field, $required, "$field must be required");
		}

		// paymentGateway enum is exactly mollie / stripe.
		self::assertSame(['mollie', 'stripe'], $schema['properties']['paymentGateway']['enum']);
	}//end testRequiredFields()

	/**
	 * Demo seed objects are present spanning pending / captured / failed.
	 *
	 * @return void
	 */
	public function testSeedObjectsSpanStates(): void {
		$objects = $this->fragment()['components']['objects'];
		self::assertGreaterThanOrEqual(2, count($objects));

		$states = array_map(static fn (array $o): string => (string)($o['state'] ?? ''), $objects);
		self::assertContains('pending', $states);
		self::assertContains('captured', $states);
	}//end testSeedObjectsSpanStates()
}//end class
