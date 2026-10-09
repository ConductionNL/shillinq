<?php

/**
 * Unit tests for PaymentBlockChecker.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BPR-003, REQ-BPR-004 and REQ-BPR-005: one check for blocked lines.
 */
class PaymentBlockCheckerTest extends TestCase {

	/**
	 * The checker over the fixture records.
	 *
	 * @param array<string, list<array<string, mixed>>> $records Records by schema.
	 *
	 * @return PaymentBlockChecker
	 */
	private function checker(array $records): PaymentBlockChecker {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new PaymentBlockChecker(new InMemoryObjectServiceStub($records), $settings);

	}//end checker()

	/**
	 * A run with one line per given invoice id.
	 *
	 * @param list<string> $refs The APTransaction ids.
	 *
	 * @return array<string, mixed>
	 */
	private function runOf(array $refs): array {
		$lines = [];
		foreach ($refs as $ref) {
			$lines[] = ['payeeId' => 'payee-drukkerij', 'creditorIban' => 'NL20INGB0001234567', 'amount' => 10.0, 'apTransactionRef' => $ref];
		}

		return ['id' => 'pr-new', 'administrationId' => PaymentRunFixture::ADMIN, 'paymentLines' => $lines];

	}//end runOf()

	/**
	 * The block fields are part of the real register fragment.
	 *
	 * @return void
	 */
	public function testTheBlockFieldsValidateAgainstTheRegister(): void {
		$records = PaymentRunFixture::records();
		$blockedInvoice = $records['APTransaction'][2];
		$blockedPayee = $records['Payee'][1];
		unset($blockedInvoice['id'], $blockedPayee['id']);

		$this->assertSame([], RegisterSchema::errors('APTransaction', $blockedInvoice));
		$this->assertSame([], RegisterSchema::errors('Payee', $blockedPayee));
		foreach (['APTransaction', 'Payee'] as $schema) {
			$properties = RegisterSchema::schema($schema)['properties'];
			$this->assertSame('boolean', $properties['paymentBlocked']['type'], $schema);
			$this->assertSame('string', $properties['paymentBlockReason']['type'], $schema);
		}

		$line = RegisterSchema::schema('PaymentRun')['properties']['paymentLines']['items']['properties'];
		$this->assertSame('date', $line['requestedExecutionDate']['format']);

	}//end testTheBlockFieldsValidateAgainstTheRegister()

	/**
	 * A blocked invoice is named with its reason.
	 *
	 * @return void
	 */
	public function testABlockedInvoiceIsNamed(): void {
		$blocked = $this->checker(PaymentRunFixture::records())->blockedLines($this->runOf(['ap-0412', 'ap-0419']));

		$this->assertCount(1, $blocked);
		$this->assertSame('2026-0419', $blocked[0]['invoiceNumber']);
		$this->assertSame('invoice-blocked', $blocked[0]['reason']);
		$this->assertSame('Wacht op creditnota', $blocked[0]['detail']);

	}//end testABlockedInvoiceIsNamed()

	/**
	 * An invoice of a blocked payee is named, with the payee's reason.
	 *
	 * @return void
	 */
	public function testAnInvoiceOfABlockedPayeeIsNamed(): void {
		$blocked = $this->checker(PaymentRunFixture::records())->blockedLines($this->runOf(['ap-dv7781']));

		$this->assertSame('DV-7781', $blocked[0]['invoiceNumber']);
		$this->assertSame('payee-blocked', $blocked[0]['reason']);
		$this->assertSame('IBAN change under verification', $blocked[0]['detail']);

	}//end testAnInvoiceOfABlockedPayeeIsNamed()

	/**
	 * A disputed invoice counts as blocked.
	 *
	 * @return void
	 */
	public function testADisputedInvoiceIsNamed(): void {
		$blocked = $this->checker(PaymentRunFixture::records())->blockedLines($this->runOf(['ap-0377']));

		$this->assertSame('2026-0377', $blocked[0]['invoiceNumber']);
		$this->assertSame('invoice-disputed', $blocked[0]['reason']);

	}//end testADisputedInvoiceIsNamed()

	/**
	 * A clean run has no blocked line.
	 *
	 * @return void
	 */
	public function testACleanRunHasNoBlockedLine(): void {
		$this->assertSame([], $this->checker(PaymentRunFixture::records())->blockedLines($this->runOf(['ap-0412', 'ap-0398'])));

	}//end testACleanRunHasNoBlockedLine()

	/**
	 * An invoice that cannot be found fails closed.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatCannotBeFoundFailsClosed(): void {
		$blocked = $this->checker(PaymentRunFixture::records())->blockedLines($this->runOf(['ap-gone']));

		$this->assertSame('not-found', $blocked[0]['reason']);
		$this->assertSame('ap-gone', $blocked[0]['invoiceNumber']);

	}//end testAnInvoiceThatCannotBeFoundFailsClosed()
}//end class
