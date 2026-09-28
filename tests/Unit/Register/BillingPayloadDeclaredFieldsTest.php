<?php

/**
 * Every key the billing writers send is a declared property.
 *
 * OpenRegister drops an undeclared property in silence, so a writer that sends
 * one loses it and a guard that filters on one matches nothing. These tests
 * join the writer to the effective register, which no writer's own test did.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Register;

use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCA\Shillinq\Tests\Unit\Fixtures\EffectiveRegisterFixture;
use PHPUnit\Framework\TestCase;

/**
 * The billing payloads against the effective ARInvoice and PaymentRequest.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BillingPayloadDeclaredFieldsTest extends TestCase {
	/**
	 * A recurring payload for one line, period 2026-10.
	 *
	 * @param int $sequence The invoice's number inside its profile and period.
	 *
	 * @return array<string,mixed>
	 */
	private function recurringPayload(int $sequence = 1): array {
		return RecurringInvoiceGenerator::buildArInvoicePayload(
			profile: [
				'customerReference' => '00000000-0000-0000-0000-000000000001',
				'administrationId' => 'adm-1',
				'issueMode' => 'draft-for-review',
				'lines' => [['description' => 'Retainer {month}', 'quantity' => 1, 'unitPrice' => 500, 'vatCode' => 21, 'revenueAccount' => '8000']],
			],
			profileId: 'abcdef12-3456-7890-abcd-ef1234567890',
			billingPeriod: '2026-10',
			periodStart: '2026-10-01',
			issueDate: '2026-10-01',
			dueDate: '2026-10-31',
			language: 'en',
			sequence: $sequence,
		);
	}//end recurringPayload()

	/**
	 * Every key and every line key of a generated invoice is declared, and the
	 * guard's two filter fields are among them (REQ-RIN-010, REQ-RIN-004).
	 *
	 * @return void
	 */
	public function testTheRecurringPayloadWritesOnlyDeclaredFields(): void {
		$payload = $this->recurringPayload();
		$declared = EffectiveRegisterFixture::properties(schema: 'ARInvoice');

		self::assertSame([], array_values(array_diff(array_keys($payload), $declared)), 'Undeclared ARInvoice keys');
		self::assertContains('recurringProfileId', $declared);
		self::assertContains('billingPeriod', $declared);

		$lineDeclared = EffectiveRegisterFixture::itemProperties(schema: 'ARInvoice', property: 'invoiceLines');
		foreach ($payload['invoiceLines'] as $line) {
			self::assertSame([], array_values(array_diff(array_keys($line), $lineDeclared)), 'Undeclared line keys');
		}
	}//end testTheRecurringPayloadWritesOnlyDeclaredFields()

	/**
	 * A generated invoice carries the two fields ARInvoice requires, and each
	 * line its revenue account (REQ-RIN-010).
	 *
	 * @return void
	 */
	public function testTheRecurringPayloadCarriesNumberPeriodAndLineAccount(): void {
		$payload = $this->recurringPayload();

		self::assertSame('2026-10', $payload['periodId']);
		self::assertSame('REC-202610-ABCDEF12-01', $payload['invoiceNumber']);
		self::assertSame('8000', $payload['invoiceLines'][0]['glAccount']);
		self::assertSame('REC-202610-ABCDEF12-02', $this->recurringPayload(sequence: 2)['invoiceNumber']);
	}//end testTheRecurringPayloadCarriesNumberPeriodAndLineAccount()

	/**
	 * The quick draft's reference and the leaf's requester are declared, and the
	 * schema versions moved with the declarations (REQ-IQD-007, REQ-SOPR-009).
	 *
	 * @return void
	 */
	public function testTheOtherWrittenFieldsAreDeclaredAndTheVersionsMoved(): void {
		self::assertContains('customerReference', EffectiveRegisterFixture::properties(schema: 'ARInvoice'));
		self::assertContains('requestedBy', EffectiveRegisterFixture::properties(schema: 'PaymentRequest'));
		self::assertSame('0.16.0', EffectiveRegisterFixture::schema(schema: 'ARInvoice')['version']);
		self::assertSame('0.6.0', EffectiveRegisterFixture::schema(schema: 'PaymentRequest')['version']);
	}//end testTheOtherWrittenFieldsAreDeclaredAndTheVersionsMoved()
}//end class
