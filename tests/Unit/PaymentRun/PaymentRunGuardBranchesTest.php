<?php

/**
 * Unit tests for the guard branches of the payment-run proposal and block check.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunProposalService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Malformed runs, unknown payees, foreign currency, inactive runs and an id-less save.
 *
 * @covers \OCA\Shillinq\PaymentRun\PaymentBlockChecker
 * @covers \OCA\Shillinq\PaymentRun\PaymentRunProposalService
 * @uses   \OCA\Shillinq\PaymentRun\PaymentProposalRequest
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class PaymentRunGuardBranchesTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The register location of the shillinq register.
	 *
	 * @return RegisterLocation
	 */
	private function location(): RegisterLocation {
		return $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);

	}//end location()

	/**
	 * The block checker over the given records.
	 *
	 * @param array<string, list<array<string, mixed>>> $records Records by schema.
	 *
	 * @return PaymentBlockChecker
	 */
	private function checker(array $records): PaymentBlockChecker {
		return new PaymentBlockChecker(new InMemoryObjectServiceStub($records), $this->location());

	}//end checker()

	/**
	 * The proposal service over a store.
	 *
	 * @param InMemoryObjectServiceStub $store The store.
	 *
	 * @return PaymentRunProposalService
	 */
	private function service(InMemoryObjectServiceStub $store): PaymentRunProposalService {
		return new PaymentRunProposalService($store, $this->location(), new PaymentBlockChecker($store, $this->location()));

	}//end service()

	/**
	 * Propose a run due on or before 2026-10-01.
	 *
	 * @param PaymentRunProposalService $service The service.
	 *
	 * @return array{paymentRun: array<string, mixed>|null, skipped: list<array<string, string>>}
	 */
	private function propose(PaymentRunProposalService $service): array {
		return $service->propose(
			administrationId: PaymentRunFixture::ADMIN,
			dueOnOrBefore: '2026-10-01',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-09-30'
		);

	}//end propose()

	/**
	 * A run whose lines are not a list has no blocked line.
	 *
	 * @return void
	 */
	public function testARunWithoutALineListHasNoBlockedLine(): void {
		$checker = $this->checker(PaymentRunFixture::records());

		$this->assertSame([], $checker->blockedLines(['id' => 'pr-x', 'paymentLines' => 'not-a-list']));
		$this->assertSame([], $checker->blockedLines(['id' => 'pr-x']));

	}//end testARunWithoutALineListHasNoBlockedLine()

	/**
	 * A line that is not an object is passed over; the others are still checked.
	 *
	 * @return void
	 */
	public function testANonObjectLineIsPassedOver(): void {
		$blocked = $this->checker(PaymentRunFixture::records())->blockedLines(
			['paymentLines' => ['garbage', 42, ['apTransactionRef' => 'ap-0419']]]
		);

		$this->assertCount(1, $blocked);
		$this->assertSame('ap-0419', $blocked[0]['apTransactionRef']);
		$this->assertSame(PaymentBlockChecker::REASON_INVOICE_BLOCKED, $blocked[0]['reason']);

	}//end testANonObjectLineIsPassedOver()

	/**
	 * A payee id that resolves to nothing reads as a blocked payee.
	 *
	 * @return void
	 */
	public function testAnUnknownPayeeFailsClosed(): void {
		$checker = $this->checker(PaymentRunFixture::records());

		$this->assertSame(
			['paymentBlocked' => true, 'paymentBlockReason' => 'payee payee-gone not found'],
			$checker->payee('payee-gone')
		);
		$this->assertNull($checker->payee(''));

	}//end testAnUnknownPayeeFailsClosed()

	/**
	 * An invoice of an unknown payee is named as payee-blocked, with why.
	 *
	 * @return void
	 */
	public function testAnInvoiceOfAnUnknownPayeeIsNamed(): void {
		$records = PaymentRunFixture::records();
		$records['APTransaction'][] = PaymentRunFixture::invoice(
			id: 'ap-orphan',
			number: 'OR-1',
			vendor: 'payee-gone',
			due: '2026-09-26',
			total: 12.1,
			state: 'issued'
		);

		$blocked = $this->checker($records)->blockedLines(['paymentLines' => [['apTransactionRef' => 'ap-orphan']]]);

		$this->assertSame(
			[
				[
					'apTransactionRef' => 'ap-orphan',
					'invoiceNumber' => 'OR-1',
					'reason' => PaymentBlockChecker::REASON_PAYEE_BLOCKED,
					'detail' => 'payee payee-gone not found',
				],
			],
			$blocked
		);

	}//end testAnInvoiceOfAnUnknownPayeeIsNamed()

	/**
	 * An invoice in another currency is left out of the SEPA run.
	 *
	 * @return void
	 */
	public function testAForeignCurrencyInvoiceIsLeftOut(): void {
		$records = PaymentRunFixture::records();
		$invoice = PaymentRunFixture::invoice(id: 'ap-usd', number: 'US-9', vendor: 'payee-drukkerij', due: '2026-09-27', total: 300.0, state: 'issued');
		$invoice['currency'] = 'usd';
		$records['APTransaction'][] = $invoice;

		$result = $this->propose($this->service(new InMemoryObjectServiceStub($records, $this->saved)));

		$skipped = array_column($result['skipped'], null, 'invoiceNumber');
		$this->assertSame(
			['reason' => PaymentRunProposalService::REASON_CURRENCY, 'apTransactionRef' => 'ap-usd', 'invoiceNumber' => 'US-9', 'detail' => ''],
			$skipped['US-9']
		);
		$this->assertNotContains('ap-usd', array_column($result['paymentRun']['paymentLines'], 'apTransactionRef'));

	}//end testAForeignCurrencyInvoiceIsLeftOut()

	/**
	 * A run in a state that does not hold its invoices leaves them payable.
	 *
	 * @return void
	 */
	public function testACancelledRunDoesNotHoldItsInvoices(): void {
		$records = PaymentRunFixture::records();
		$records['PaymentRun'][0]['status'] = 'cancelled';
		$records['PaymentRun'][0]['lifecycleState'] = 'cancelled';

		$result = $this->propose($this->service(new InMemoryObjectServiceStub($records, $this->saved)));

		$byRef = array_column($result['paymentRun']['paymentLines'], null, 'apTransactionRef');
		$this->assertArrayHasKey('ap-0398', $byRef);
		$this->assertSame(121.0, $byRef['ap-0398']['amount']);
		$this->assertNotContains('2026-0398', array_column($result['skipped'], 'invoiceNumber'));

	}//end testACancelledRunDoesNotHoldItsInvoices()

	/**
	 * A save that answers with no usable record returns the draft run itself.
	 *
	 * @return void
	 */
	public function testASaveWithoutARecordReturnsTheDraftRun(): void {
		$store = new class(PaymentRunFixture::records()) extends InMemoryObjectServiceStub {

			/**
			 * Answer every save with an empty entity.
			 *
			 * @param array           $object        Object payload.
			 * @param ?array          $extend        Ignored.
			 * @param string|int|null $register      Ignored.
			 * @param string|int|null $schema        Ignored.
			 * @param ?string         $uuid          Ignored.
			 * @param bool            $_rbac         Ignored.
			 * @param bool            $_multitenancy Ignored.
			 * @param bool            $silent        Ignored.
			 * @param bool            $_validation   Ignored.
			 * @param ?array          $uploadedFiles Ignored.
			 * @param ?IUser          $currentUser   Ignored.
			 * @param bool            $failIfExists  Ignored.
			 *
			 * @return ObjectEntityInterface
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?IUser $currentUser = null,
				bool $failIfExists = false
			): ObjectEntityInterface {
				return new ObjectEntityStub(payload: []);

			}//end saveObject()
		};

		$run = $this->propose($this->service($store))['paymentRun'];

		$this->assertArrayNotHasKey('id', $run);
		$this->assertSame('PR-2026-003', $run['runNumber']);
		$this->assertSame('draft', $run['lifecycleState']);
		$this->assertSame(['ap-part', 'ap-0412'], array_column($run['paymentLines'], 'apTransactionRef'));

	}//end testASaveWithoutARecordReturnsTheDraftRun()
}//end class
