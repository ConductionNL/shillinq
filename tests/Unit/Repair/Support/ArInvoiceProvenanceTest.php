<?php

/**
 * Unit tests for ArInvoiceProvenance.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair\Support;

use OCA\Shillinq\Repair\Support\ArInvoiceProvenance;
use PHPUnit\Framework\TestCase;

/**
 * The pure provenance derivation, without the repair step around it.
 *
 * @covers \OCA\Shillinq\Repair\Support\ArInvoiceProvenance
 */
final class ArInvoiceProvenanceTest extends TestCase {

	/**
	 * The derivation under test.
	 *
	 * @var ArInvoiceProvenance
	 */
	private ArInvoiceProvenance $provenance;

	/**
	 * Build the derivation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->provenance = new ArInvoiceProvenance();
	}//end setUp()

	/**
	 * A monthly profile: invoiced on the 31st, 2 x 50 + 1 x 25.5 net.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function profile(array $overrides = []): array {
		return array_merge(
			[
				'customerReference' => 'cust-1',
				'administrationId' => 'adm-1',
				'startDate' => '2026-01-15',
				'lastBillingPeriod' => '2026-04',
				'invoiceDay' => 31,
				'lines' => [
					['quantity' => 2, 'unitPrice' => 50, 'revenueAccount' => '8000'],
					['unitPrice' => 25.5, 'revenueAccount' => ' 8010 '],
				],
			],
			$overrides
		);
	}//end profile()

	/**
	 * An invoice the profile above generated for February.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(array $overrides = []): array {
		return array_merge(
			[
				'invoiceNumber' => 'INV-2026-0002',
				'customerId' => 'cust-1',
				'administrationId' => 'adm-1',
				'invoiceDate' => '2026-02-28',
				'netAmount' => '125.50',
				'invoiceLines' => [['description' => 'a'], ['description' => 'b', 'glAccount' => '']],
			],
			$overrides
		);
	}//end invoice()

	/**
	 * Only the provisional DRAFT- number marks a quick draft.
	 *
	 * @return void
	 */
	public function testOnlyTheDraftPrefixMarksAQuickDraft(): void {
		self::assertTrue(condition: $this->provenance->isQuickDraft(invoice: ['invoiceNumber' => 'DRAFT-17']));
		self::assertFalse(condition: $this->provenance->isQuickDraft(invoice: ['invoiceNumber' => 'INV-DRAFT-17']));
		self::assertFalse(condition: $this->provenance->isQuickDraft(invoice: []));
	}//end testOnlyTheDraftPrefixMarksAQuickDraft()

	/**
	 * A missing profile id, period or line account is a recurring gap; a
	 * complete invoice has none.
	 *
	 * @return void
	 */
	public function testRecurringGapsAreTheProfilePeriodAndLineAccounts(): void {
		$complete = [
			'recurringProfileId' => 'p-1',
			'billingPeriod' => '2026-02',
			'invoiceLines' => [['glAccount' => '8000'], 'not-a-line'],
		];

		self::assertFalse(condition: $this->provenance->hasRecurringGap(invoice: $complete));
		self::assertTrue(condition: $this->provenance->hasRecurringGap(invoice: ['recurringProfileId' => ' '] + $complete));
		self::assertTrue(condition: $this->provenance->hasRecurringGap(invoice: ['billingPeriod' => null] + $complete));
		self::assertTrue(
			condition: $this->provenance->hasRecurringGap(invoice: ['invoiceLines' => [['glAccount' => '8000'], ['glAccount' => '']]] + $complete)
		);
	}//end testRecurringGapsAreTheProfilePeriodAndLineAccounts()

	/**
	 * A missing customer reference or line account is a quick-draft gap.
	 *
	 * @return void
	 */
	public function testQuickDraftGapsAreTheReferenceAndLineAccounts(): void {
		$complete = ['customerReference' => 'PO-1', 'invoiceLines' => [['glAccount' => '8000']]];

		self::assertFalse(condition: $this->provenance->hasQuickDraftGap(invoice: $complete));
		self::assertTrue(condition: $this->provenance->hasQuickDraftGap(invoice: ['customerReference' => ''] + $complete));
		self::assertTrue(condition: $this->provenance->hasQuickDraftGap(invoice: ['invoiceLines' => [[]]] + $complete));
	}//end testQuickDraftGapsAreTheReferenceAndLineAccounts()

	/**
	 * The generator's fingerprint matches, with the invoice day clamped to
	 * the month and the net summed as the generator does.
	 *
	 * @return void
	 */
	public function testTheGeneratorFingerprintMatchesOneProfile(): void {
		self::assertSame(
			expected: 'p-1',
			actual: $this->provenance->matchProfile(
				invoice: $this->invoice(),
				profiles: ['p-1' => $this->profile(), 'p-2' => $this->profile(overrides: ['customerReference' => 'cust-2'])]
			)
		);
	}//end testTheGeneratorFingerprintMatchesOneProfile()

	/**
	 * Each part of the fingerprint that does not fit rules the profile out.
	 *
	 * @return void
	 */
	public function testEachPartOfTheFingerprintMustFit(): void {
		$cases = [
			'date not a date' => [$this->invoice(overrides: ['invoiceDate' => '2026-02']), $this->profile()],
			'other customer' => [$this->invoice(), $this->profile(overrides: ['customerReference' => 'cust-2'])],
			'no customer' => [$this->invoice(overrides: ['customerId' => '']), $this->profile(overrides: ['customerReference' => ''])],
			'other administration' => [$this->invoice(), $this->profile(overrides: ['administrationId' => 'adm-2'])],
			'no start date' => [$this->invoice(), $this->profile(overrides: ['startDate' => ''])],
			'never generated' => [$this->invoice(), $this->profile(overrides: ['lastBillingPeriod' => ''])],
			'before the first period' => [$this->invoice(overrides: ['invoiceDate' => '2025-12-31']), $this->profile()],
			'after the last period' => [$this->invoice(overrides: ['invoiceDate' => '2026-05-31']), $this->profile()],
			'unclamped day' => [$this->invoice(overrides: ['invoiceDate' => '2026-02-27']), $this->profile()],
			'other net amount' => [$this->invoice(overrides: ['netAmount' => '125.49']), $this->profile()],
		];

		foreach ($cases as $label => [$invoice, $profile]) {
			self::assertNull(
				actual: $this->provenance->matchProfile(invoice: $invoice, profiles: ['p-1' => $profile]),
				message: $label
			);
		}
	}//end testEachPartOfTheFingerprintMustFit()

	/**
	 * Two fitting profiles make the invoice ambiguous.
	 *
	 * @return void
	 */
	public function testTwoFittingProfilesAreAmbiguous(): void {
		self::assertSame(
			expected: ArInvoiceProvenance::AMBIGUOUS,
			actual: $this->provenance->matchProfile(
				invoice: $this->invoice(),
				profiles: ['p-1' => $this->profile(), 'p-2' => $this->profile()]
			)
		);
	}//end testTwoFittingProfilesAreAmbiguous()

	/**
	 * A recorded profile id is kept only when that profile still fits.
	 *
	 * @return void
	 */
	public function testARecordedProfileIdMustStillFit(): void {
		$profiles = ['p-1' => $this->profile(), 'p-2' => $this->profile()];

		self::assertSame(
			expected: 'p-2',
			actual: $this->provenance->matchProfile(invoice: $this->invoice(overrides: ['recurringProfileId' => 'p-2']), profiles: $profiles)
		);
		self::assertNull(
			actual: $this->provenance->matchProfile(invoice: $this->invoice(overrides: ['recurringProfileId' => 'p-9']), profiles: $profiles)
		);
		self::assertNull(
			actual: $this->provenance->matchProfile(
				invoice: $this->invoice(overrides: ['recurringProfileId' => 'p-1', 'netAmount' => 1]),
				profiles: $profiles
			)
		);
	}//end testARecordedProfileIdMustStillFit()

	/**
	 * The profile fills only the gaps: id, period and line accounts by position.
	 *
	 * @return void
	 */
	public function testTheProfileFillsOnlyTheGaps(): void {
		$filled = $this->provenance->fillFromProfile(
			invoice: $this->invoice(overrides: ['invoiceLines' => [['glAccount' => '7000'], ['glAccount' => ''], ['description' => 'extra']]]),
			profileId: 'p-1',
			profile: $this->profile()
		);

		self::assertSame(expected: 'p-1', actual: $filled['recurringProfileId']);
		self::assertSame(expected: '2026-02', actual: $filled['billingPeriod']);
		self::assertSame(
			expected: [['glAccount' => '7000'], ['glAccount' => '8010'], ['description' => 'extra']],
			actual: $filled['invoiceLines']
		);

		$kept = $this->provenance->fillFromProfile(
			invoice: ['recurringProfileId' => 'p-7', 'billingPeriod' => '2026-01', 'invoiceDate' => '2026-02-28'],
			profileId: 'p-1',
			profile: $this->profile()
		);
		self::assertSame(
			expected: ['recurringProfileId' => 'p-7', 'billingPeriod' => '2026-01', 'invoiceDate' => '2026-02-28'],
			actual: $kept
		);
	}//end testTheProfileFillsOnlyTheGaps()

	/**
	 * The create audit entry restores the reference and the line accounts,
	 * reading the current `invoiceLines` shape first.
	 *
	 * @return void
	 */
	public function testTheCreateEntryRestoresReferenceAndAccounts(): void {
		$filled = $this->provenance->fillFromAudit(
			invoice: ['customerReference' => '', 'invoiceLines' => [[], ['glAccount' => '1300'], 'kept-as-is']],
			created: [
				'customerReference' => ['old' => null, 'new' => '  PO-42 '],
				'invoiceLines' => ['old' => null, 'new' => [['glAccount' => '8000'], ['glAccount' => '8010'], ['glAccount' => '8020']]],
				'lines' => ['old' => null, 'new' => [['glAccount' => 'never-read']]],
			]
		);

		self::assertSame(expected: 'PO-42', actual: $filled['customerReference']);
		self::assertSame(
			expected: [['glAccount' => '8000'], ['glAccount' => '1300'], 'kept-as-is'],
			actual: $filled['invoiceLines']
		);
	}//end testTheCreateEntryRestoresReferenceAndAccounts()

	/**
	 * The older quick draft's `lines` are read when `invoiceLines` is absent,
	 * and an existing reference is never replaced.
	 *
	 * @return void
	 */
	public function testTheOlderLinesShapeIsReadAndAReferenceIsKept(): void {
		$filled = $this->provenance->fillFromAudit(
			invoice: ['customerReference' => 'PO-1', 'invoiceLines' => [['description' => 'a']]],
			created: [
				'customerReference' => ['old' => null, 'new' => 'PO-2'],
				'lines' => ['old' => null, 'new' => [['lineNumber' => 1, 'glAccount' => '8000']]],
			]
		);

		self::assertSame(expected: 'PO-1', actual: $filled['customerReference']);
		self::assertSame(expected: [['description' => 'a', 'glAccount' => '8000']], actual: $filled['invoiceLines']);
	}//end testTheOlderLinesShapeIsReadAndAReferenceIsKept()

	/**
	 * An invoice without a line list is returned untouched.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithoutLinesIsLeftAlone(): void {
		$invoice = ['customerReference' => 'PO-1', 'invoiceLines' => 'garbage'];

		self::assertSame(
			expected: $invoice,
			actual: $this->provenance->fillFromAudit(invoice: $invoice, created: ['invoiceLines' => ['old' => null, 'new' => [['glAccount' => '8000']]]])
		);
	}//end testAnInvoiceWithoutLinesIsLeftAlone()
}//end class
