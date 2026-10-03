<?php

/**
 * Unit tests for ContributionInvoiceBuilder.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\ContributionInvoiceBuilder;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the charge check and the invoice and request a recipient becomes.
 */
final class ContributionInvoiceBuilderTest extends TestCase {
	/**
	 * The Dutch values of the two invoice strings, as l10n/nl.json holds them.
	 *
	 * @var array<string, string>
	 */
	private const DUTCH = [
		'This contribution is voluntary. Your child takes part whether you pay or not.' => 'Deze bijdrage is vrijwillig. Uw kind doet mee, of u nu betaalt of niet.',
		'(voluntary)' => '(vrijwillig)',
	];

	/**
	 * The builder under test.
	 *
	 * @var ContributionInvoiceBuilder
	 */
	private ContributionInvoiceBuilder $builder;

	/**
	 * Build the builder over a translation factory that answers Dutch for `nl`
	 * and the source string otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $language = null): IL10N {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text): string => ($language === 'nl' ? (self::DUTCH[$text] ?? $text) : $text)
				);

				return $l10n;
			}
		);

		$this->builder = new ContributionInvoiceBuilder(l10nFactory: $factory);
	}//end setUp()

	/**
	 * A well-formed raise call.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The call.
	 */
	private function call(array $overrides = []): array {
		return array_merge(
			[
				'chargeable' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
				'kind' => 'parental-contribution',
				'description' => 'Ouderbijdrage 2026-2027',
				'amount' => 60,
				'voluntary' => true,
				'administrationId' => 'adm-school-1',
				'invoiceDate' => '2026-10-01',
				'recipients' => [
					['debtor' => ['customerMasterId' => 'cm-1'], 'beneficiary' => ['type' => 'learner', 'id' => 'child-a']],
				],
			],
			$overrides
		);
	}//end call()

	/**
	 * A voluntary charge in Dutch carries the notice on the invoice and the
	 * mark on the line and the checkout text (REQ-SCON-007).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-007)
	 */
	public function testAVoluntaryChargeIsMarkedOnTheInvoice(): void {
		$charge = $this->builder->normaliseCharge($this->call(), '2026-09-27');
		$beneficiary = ['type' => 'learner', 'id' => 'child-a'];

		$invoice = $this->builder->buildInvoice($charge, 60.0, 'cm-1', $beneficiary, 'ctb-20261001-1a2b3c4d', 1);
		$request = $this->builder->buildRequest($charge, 60.0, 'cm-1', $beneficiary, 'inv-1', 'ctb-20261001-1a2b3c4d');

		self::assertSame('Deze bijdrage is vrijwillig. Uw kind doet mee, of u nu betaalt of niet.', $invoice['invoiceNote']);
		self::assertSame('Ouderbijdrage 2026-2027 (vrijwillig)', $invoice['invoiceLines'][0]['itemName']);
		self::assertSame('Ouderbijdrage 2026-2027 (vrijwillig)', $request['description']);
		self::assertTrue($invoice['contribution']['voluntary']);
		self::assertTrue($request['voluntary']);
	}//end testAVoluntaryChargeIsMarkedOnTheInvoice()

	/**
	 * The invoice records the language its text was written in, so the one
	 * voluntary reminder follows it; a call without one records Dutch
	 * (REQ-SCON-011).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function testTheInvoiceRecordsItsLanguage(): void {
		$beneficiary = ['type' => 'learner', 'id' => 'child-a'];

		$english = $this->builder->normaliseCharge($this->call(['language' => 'en']), '2026-09-27');
		$invoice = $this->builder->buildInvoice($english, 60.0, 'cm-1', $beneficiary, 'ctb-20261001-1a2b3c4d', 1);
		self::assertSame('en', $invoice['contribution']['language']);

		$default = $this->builder->normaliseCharge($this->call(), '2026-09-27');
		$invoice = $this->builder->buildInvoice($default, 60.0, 'cm-1', $beneficiary, 'ctb-20261001-1a2b3c4d', 1);
		self::assertSame('nl', $invoice['contribution']['language']);
	}//end testTheInvoiceRecordsItsLanguage()

	/**
	 * A compulsory charge carries neither the notice nor the mark, and English
	 * is used when asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-007)
	 */
	public function testACompulsoryChargeCarriesNoNotice(): void {
		$charge = $this->builder->normaliseCharge($this->call(['voluntary' => false, 'kind' => 'lunch-supervision']), '2026-09-27');

		$invoice = $this->builder->buildInvoice($charge, 60.0, 'cm-1', ['type' => 'learner', 'id' => 'child-a'], 'ctb-20261001-1a2b3c4d', 1);

		self::assertArrayNotHasKey('invoiceNote', $invoice);
		self::assertSame('Ouderbijdrage 2026-2027', $invoice['invoiceLines'][0]['itemName']);
		self::assertSame(
			'This contribution is voluntary. Your child takes part whether you pay or not.',
			$this->builder->voluntaryNotice('en')
		);
	}//end testACompulsoryChargeCarriesNoNotice()

	/**
	 * An issued invoice with no order behind it, one line, no VAT, and the
	 * contribution group naming the chargeable (REQ-SCON-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testTheInvoiceIsIssuedWithOneLineAndNoOrder(): void {
		$charge = $this->builder->normaliseCharge($this->call(['revenueAccount' => '8400']), '2026-09-27');

		$invoice = $this->builder->buildInvoice($charge, 60.0, 'cm-1', ['type' => 'learner', 'id' => 'child-a'], 'ctb-20261001-1a2b3c4d', 7);

		self::assertSame('issued', $invoice['lifecycleState']);
		self::assertSame('cm-1', $invoice['customerId']);
		self::assertSame('2026-10', $invoice['periodId']);
		self::assertSame('2026-10-31', $invoice['dueDate']);
		self::assertSame(60.0, $invoice['grossAmount']);
		self::assertSame(0.0, $invoice['vatAmount']);
		self::assertCount(1, $invoice['invoiceLines']);
		self::assertArrayNotHasKey('lines', $invoice);
		self::assertSame('E', $invoice['invoiceLines'][0]['vatCategory']);
		self::assertSame('8400', $invoice['contribution']['revenueAccount']);
		self::assertSame('CTB-2026-1A2B3C4D-0007', $invoice['invoiceNumber']);
		self::assertSame('parental-contribution', $invoice['contribution']['kind']);
		self::assertSame('ctb-20261001-1a2b3c4d', $invoice['contribution']['raiseBatchId']);
		foreach (['orderId', 'salesOrderId', 'orderReference'] as $orderField) {
			self::assertArrayNotHasKey($orderField, $invoice);
		}
	}//end testTheInvoiceIsIssuedWithOneLineAndNoOrder()

	/**
	 * A recipient's own amount overrides the charge (a reduction).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testRecipientAmountOverridesTheCharge(): void {
		$charge = $this->builder->normaliseCharge($this->call(), '2026-09-27');

		self::assertSame(30.0, $this->builder->amountFor($charge, ['amount' => '30.00']));
		self::assertSame(60.0, $this->builder->amountFor($charge, []));

		$this->expectException(InvalidArgumentException::class);
		$this->builder->amountFor($charge, ['amount' => 0]);
	}//end testRecipientAmountOverridesTheCharge()

	/**
	 * The request stands on the chargeable, names the owning app and the
	 * child, and is backed by the invoice (REQ-SCON-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	public function testTheRequestReferencesTheChargeableAndTheChild(): void {
		$charge = $this->builder->normaliseCharge(
			$this->call(
				[
					'chargeable' => ['app' => 'portaliq', 'type' => 'activity-offer', 'register' => 'portaliq', 'schema' => 'activityOffer', 'id' => 'act-9'],
					'kind' => 'activity',
				]
			),
			'2026-09-27'
		);
		$beneficiary = $this->builder->beneficiaryFor(
			['beneficiary' => ['type' => 'child', 'id' => 'child-b']],
			'cm-2',
			'shillinq'
		);

		$request = $this->builder->buildRequest($charge, 25.0, 'cm-2', $beneficiary, 'inv-9', 'ctb-20261001-1a2b3c4d');
		$invoice = $this->builder->buildInvoice($charge, 25.0, 'cm-2', $beneficiary, 'ctb-20261001-1a2b3c4d', 1);

		$expected = ['app' => 'portaliq', 'type' => 'activity-offer', 'register' => 'portaliq', 'schema' => 'activityOffer', 'id' => 'act-9'];
		self::assertSame($expected, $request['subject']);
		self::assertSame($expected, $invoice['contribution']['chargeable']);
		self::assertSame('object', $request['subjectKind']);
		self::assertSame('contribution', $request['requestType']);
		self::assertSame('inv-9', $request['invoiceReference']);
		self::assertSame(['type' => 'child', 'id' => 'child-b'], $request['beneficiary']);
		self::assertSame(['customerMasterId' => 'cm-2'], $request['debtor']);
		self::assertSame('pending', $request['state']);
	}//end testTheRequestReferencesTheChargeableAndTheChild()

	/**
	 * Without a child the debtor's customer is the beneficiary, so a school
	 * that bills per household still gets one request per household.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testTheBeneficiaryFallsBackToTheCustomer(): void {
		self::assertSame(
			['type' => 'customer', 'register' => 'shillinq', 'schema' => 'CustomerMaster', 'id' => 'cm-3'],
			$this->builder->beneficiaryFor(['debtor' => ['customerMasterId' => 'cm-3']], 'cm-3', 'shillinq')
		);

		$this->expectException(InvalidArgumentException::class);
		$this->builder->beneficiaryFor(['beneficiary' => ['type' => 'learner']], 'cm-3', 'shillinq');
	}//end testTheBeneficiaryFallsBackToTheCustomer()

	/**
	 * A malformed call is refused whole, naming the problem.
	 *
	 * @param array<string, mixed> $overrides What to break.
	 * @param string $message A part of the expected message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	#[DataProvider('malformedCalls')]
	public function testNormaliseRefusesAMalformedCall(array $overrides, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->builder->normaliseCharge($this->call($overrides), '2026-09-27');
	}//end testNormaliseRefusesAMalformedCall()

	/**
	 * Calls that must be refused before anything is written.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function malformedCalls(): array {
		$tooMany = array_fill(0, 201, ['debtor' => ['customerMasterId' => 'cm-1']]);

		return [
			'chargeable without id' => [['chargeable' => ['app' => 'learniq', 'register' => 'learniq', 'schema' => 'FeeItem']], 'id'],
			'unknown kind' => [['kind' => 'donation'], 'Unknown kind'],
			'amount of zero' => [['amount' => 0], 'amount above zero'],
			'amount not a number' => [['amount' => 'sixty'], 'amount above zero'],
			'voluntary missing' => [['voluntary' => null], 'voluntary'],
			'no administration' => [['administrationId' => ''], 'administrationId'],
			'no recipients' => [['recipients' => []], 'at least one recipient'],
			'too many recipients' => [['recipients' => $tooMany], 'at most 200'],
			'recipient without debtor' => [['recipients' => [['beneficiary' => ['type' => 'learner', 'id' => 'x']]]], 'debtor'],
			'bad invoice date' => [['invoiceDate' => '2026-13-01'], 'invoiceDate'],
			'due before invoice' => [['dueDate' => '2026-09-01'], 'before the invoice date'],
			'no description' => [['description' => '  '], 'description'],
		];
	}//end malformedCalls()
}//end class
