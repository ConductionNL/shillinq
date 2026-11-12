<?php

/**
 * Unit tests for PaymentRunProposalService.
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
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/specs/payment-run-sepa-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunProposalService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BPR-002 and REQ-BPR-006: a draft run from the invoices due.
 */
class PaymentRunProposalServiceTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The service over the fixture.
	 *
	 * @return PaymentRunProposalService
	 */
	private function service(): PaymentRunProposalService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(PaymentRunFixture::records(), $this->saved);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new PaymentRunProposalService($store, $settings, new PaymentBlockChecker($store, $this->createConfiguredMock(SettingsService::class, ['getRegisterSlug' => 'shillinq'])));

	}//end service()

	/**
	 * The skipped entries keyed by invoice number.
	 *
	 * @param array<string, mixed> $result The proposal result.
	 *
	 * @return array<string, string>
	 */
	private function skippedReasons(array $result): array {
		$reasons = [];
		foreach ($result['skipped'] as $skip) {
			$reasons[$skip['invoiceNumber']] = $skip['reason'];
		}

		ksort($reasons);
		return $reasons;

	}//end skippedReasons()

	/**
	 * One draft run with a line per payable invoice due, the rest named.
	 *
	 * @return void
	 */
	public function testAProposalPaysWhatIsDueAndNamesWhatItLeftOut(): void {
		$result = $this->service()->propose(
			administrationId: PaymentRunFixture::ADMIN,
			dueOnOrBefore: '2026-10-01',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-09-30',
			lineDates: PaymentRunProposalService::DATES_RUN
		);

		$run = $result['paymentRun'];
		$this->assertSame('draft', $run['lifecycleState']);
		$this->assertSame('draft', $run['status']);
		$this->assertSame('NL91ABNA0417164300', $run['debtorAccountIban']);
		$byRef = array_column($run['paymentLines'], null, 'apTransactionRef');
		// Oldest due first: 2026-0450 is due 2026-09-28, 2026-0412 on 2026-10-01.
		$this->assertSame(['ap-part', 'ap-0412'], array_keys($byRef));
		$this->assertSame('NL20INGB0001234567', $byRef['ap-0412']['creditorIban']);
		$this->assertSame(1815.0, $byRef['ap-0412']['amount']);
		$this->assertSame('2026-0412', $byRef['ap-0412']['remittanceInfo']);
		$this->assertSame('Drukkerij Van der Meer B.V.', $byRef['ap-0412']['payeeName']);
		// 400 of 1,000 already left in an exported run.
		$this->assertSame(600.0, $byRef['ap-part']['amount']);
		$this->assertSame(2415.0, $run['totalAmount']);
		$this->assertArrayNotHasKey('requestedExecutionDate', $byRef['ap-0412']);

		$this->assertSame(
			[
				'2026-0377' => 'invoice-disputed',
				'2026-0398' => 'already-on-run',
				'2026-0419' => 'invoice-blocked',
				'DV-7781' => 'payee-blocked',
				'GL-12' => 'no-iban',
			],
			$this->skippedReasons($result)
		);

	}//end testAProposalPaysWhatIsDueAndNamesWhatItLeftOut()

	/**
	 * The written run is what the register accepts.
	 *
	 * @return void
	 */
	public function testTheProposedRunValidatesAgainstTheRegister(): void {
		$result = $this->service()->propose(
			administrationId: PaymentRunFixture::ADMIN,
			dueOnOrBefore: '2026-10-01',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-09-30',
			lineDates: PaymentRunProposalService::DATES_DUE
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('PaymentRun', $this->saved[0]['schema']);
		$written = $this->saved[0]['object'];
		unset($written['id']);
		$this->assertSame([], RegisterSchema::errors('PaymentRun', $written));
		$this->assertSame('PR-2026-003', $result['paymentRun']['runNumber']);

	}//end testTheProposedRunValidatesAgainstTheRegister()

	/**
	 * Pay on the due date sets each line's date to the later of due date and run date.
	 *
	 * @return void
	 */
	public function testPayOnTheDueDateSetsEachLinesDate(): void {
		$result = $this->service()->propose(
			administrationId: PaymentRunFixture::ADMIN,
			dueOnOrBefore: '2026-10-01',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-09-30',
			lineDates: PaymentRunProposalService::DATES_DUE
		);

		$byRef = array_column($result['paymentRun']['paymentLines'], null, 'apTransactionRef');
		$this->assertSame('2026-10-01', $byRef['ap-0412']['requestedExecutionDate']);
		$this->assertSame('2026-09-30', $byRef['ap-part']['requestedExecutionDate']);

	}//end testPayOnTheDueDateSetsEachLinesDate()

	/**
	 * Nothing due writes nothing.
	 *
	 * @return void
	 */
	public function testNothingDueWritesNoRun(): void {
		$result = $this->service()->propose(
			administrationId: PaymentRunFixture::ADMIN,
			dueOnOrBefore: '2026-08-01',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-08-01',
			lineDates: PaymentRunProposalService::DATES_RUN
		);

		$this->assertNull($result['paymentRun']);
		$this->assertSame([], $this->saved);

	}//end testNothingDueWritesNoRun()

	/**
	 * Golden: the full result and everything written, for run-dated and
	 * due-dated proposals and an empty one. Regenerate with
	 * PAYMENT_PROPOSAL_GOLDEN_WRITE=1.
	 *
	 * @return void
	 */
	public function testProposalsMatchGolden(): void {
		$golden = __DIR__.'/fixtures/payment-run-proposal.golden.json';
		$actual = [];
		$cases = [
			['2026-10-01', '2026-09-30', PaymentRunProposalService::DATES_RUN],
			['2026-10-01', '2026-09-29', PaymentRunProposalService::DATES_DUE],
			['2026-08-01', '2026-08-01', PaymentRunProposalService::DATES_DUE],
		];
		foreach ($cases as [$due, $execution, $dates]) {
			$result = $this->service()->propose(
				administrationId: PaymentRunFixture::ADMIN,
				dueOnOrBefore: $due,
				debtorAccountIban: 'NL91ABNA0417164300',
				executionDate: $execution,
				lineDates: $dates
			);
			$actual[$due.'|'.$execution.'|'.$dates] = ['result' => $result, 'saved' => $this->saved];
		}

		if (getenv('PAYMENT_PROPOSAL_GOLDEN_WRITE') === '1') {
			file_put_contents($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$expected = json_decode((string) file_get_contents($golden), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(
			expected: json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
			actual: json_encode($actual, JSON_PRESERVE_ZERO_FRACTION)
		);

	}//end testProposalsMatchGolden()
}//end class
