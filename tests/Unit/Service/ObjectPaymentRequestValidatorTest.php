<?php

/**
 * Unit tests for ObjectPaymentRequestValidator.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Covers the conditional required set and the one-open-request invariant
 * (REQ-SOPR-001).
 */
final class ObjectPaymentRequestValidatorTest extends TestCase {
	/**
	 * The validator under test.
	 *
	 * @var ObjectPaymentRequestValidator
	 */
	private ObjectPaymentRequestValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new ObjectPaymentRequestValidator();
	}//end setUp()

	/**
	 * A well-formed object request.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function objectRequest(array $overrides = []): array {
		return array_merge(
			[
				'subjectKind' => 'object',
				'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-1'],
				'requestType' => 'dwangsom',
				'amount' => 250.0,
				'state' => 'pending',
			],
			$overrides
		);
	}//end objectRequest()

	/**
	 * A request on a case validates with no invoice behind it (REQ-SOPR-001).
	 *
	 * @return void
	 */
	public function testObjectRequestValidatesWithoutAnInvoice(): void {
		$this->validator->validate($this->objectRequest());

		// Reaching here is the assertion: validate() throws on refusal.
		self::assertTrue(true);
	}//end testObjectRequestValidatesWithoutAnInvoice()

	/**
	 * An invoice request still needs its invoiceReference (REQ-SOPR-001).
	 *
	 * @return void
	 */
	public function testInvoiceRequestStillNeedsItsInvoiceReference(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('invoiceReference');

		$this->validator->validate(['subjectKind' => 'invoice', 'amount' => 100.0]);
	}//end testInvoiceRequestStillNeedsItsInvoiceReference()

	/**
	 * A request with no subjectKind is still read as an invoice request, so the
	 * change does not loosen the shape of everything already stored.
	 *
	 * @return void
	 */
	public function testMissingSubjectKindDefaultsToInvoice(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('invoiceReference');

		$this->validator->validate(['amount' => 100.0]);
	}//end testMissingSubjectKindDefaultsToInvoice()

	/**
	 * An object request needs all four parts of the semantic reference, and the
	 * refusal names the ones that are missing (REQ-SOPR-001, ADR-048).
	 *
	 * @return void
	 */
	public function testSubjectMustNameAllFourParts(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('schema, id');

		$this->validator->validate(
			$this->objectRequest(['subject' => ['type' => 'case', 'register' => 'dossiq']])
		);
	}//end testSubjectMustNameAllFourParts()

	/**
	 * An object request needs a requestType from the enum, because the type is
	 * what resolves the revenue account later (REQ-SOPR-001, REQ-SOPR-002).
	 *
	 * @return void
	 */
	public function testObjectRequestNeedsAKnownRequestType(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('requestType');

		$this->validator->validate($this->objectRequest(['requestType' => 'boete']));
	}//end testObjectRequestNeedsAKnownRequestType()

	/**
	 * An object request carries its own amount; nothing recalculates it.
	 *
	 * @return void
	 */
	public function testObjectRequestNeedsAnAmountAboveZero(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('amount above zero');

		$this->validator->validate($this->objectRequest(['amount' => 0]));
	}//end testObjectRequestNeedsAnAmountAboveZero()

	/**
	 * A second pending request of the same type on the same subject is refused,
	 * and the refusal names the request that already stands (REQ-SOPR-001).
	 *
	 * @return void
	 */
	public function testSecondPendingRequestOfTheSameTypeIsRefused(): void {
		$existing = [$this->objectRequest(['id' => 'pr-1'])];

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('pr-1');

		$this->validator->validate($this->objectRequest(), $existing);
	}//end testSecondPendingRequestOfTheSameTypeIsRefused()

	/**
	 * A second request of a DIFFERENT type is allowed: a case can owe leges and
	 * a dwangsom at once.
	 *
	 * @return void
	 */
	public function testASecondRequestOfAnotherTypeIsAllowed(): void {
		$existing = [$this->objectRequest(['id' => 'pr-1', 'requestType' => 'leges'])];

		$this->validator->validate($this->objectRequest(['requestType' => 'dwangsom']), $existing);

		self::assertTrue(true);
	}//end testASecondRequestOfAnotherTypeIsAllowed()

	/**
	 * A settled request of the same type does not block a new one: the invariant
	 * is one OPEN request, not one request ever.
	 *
	 * @return void
	 */
	public function testACapturedRequestDoesNotBlockANewOne(): void {
		$existing = [$this->objectRequest(['id' => 'pr-1', 'state' => 'captured'])];

		$this->validator->validate($this->objectRequest(), $existing);

		self::assertTrue(true);
	}//end testACapturedRequestDoesNotBlockANewOne()

	/**
	 * A pending request on ANOTHER case does not block this one — the invariant
	 * is per subject, and a subject key that ignored the id would silently make
	 * the first case in a register the only one that can ask for money.
	 *
	 * @return void
	 */
	public function testAPendingRequestOnAnotherObjectDoesNotBlock(): void {
		$existing = [
			$this->objectRequest(
				[
					'id' => 'pr-1',
					'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-2'],
				]
			),
		];

		$this->validator->validate($this->objectRequest(), $existing);

		self::assertTrue(true);
	}//end testAPendingRequestOnAnotherObjectDoesNotBlock()

	/**
	 * Revalidating the SAME request does not refuse itself.
	 *
	 * @return void
	 */
	public function testARequestDoesNotBlockItself(): void {
		$existing = [$this->objectRequest(['id' => 'pr-1'])];

		$this->validator->validate($this->objectRequest(['id' => 'pr-1']), $existing);

		self::assertTrue(true);
	}//end testARequestDoesNotBlockItself()

	/**
	 * An event fee is its own request type, and it is a valid object request
	 * that the real PaymentRequest schema accepts (REQ-ORS-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-001)
	 */
	public function testAnEventFeeIsAnObjectRequestType(): void {
		$request = $this->objectRequest(
			[
				'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
				'requestType' => 'event-fee',
				'amount' => 45.0,
				'currency' => 'EUR',
				'description' => 'WC26-0042 Winter Camp 2026',
				'requestedBy' => 'app:larpinq',
				'paymentGateway' => 'mollie',
			]
		);

		$this->validator->validate($request);

		self::assertContains('event-fee', ObjectPaymentRequestValidator::REQUEST_TYPES);
		self::assertSame([], RegisterSchema::errors('PaymentRequest', $request));
	}//end testAnEventFeeIsAnObjectRequestType()

	/**
	 * The one-open-request rule holds for event fees too (REQ-ORS-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-001)
	 */
	public function testASecondPendingEventFeeIsRefused(): void {
		$fee = ['subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'], 'requestType' => 'event-fee'];
		$existing = [$this->objectRequest(array_merge($fee, ['id' => 'pr-1']))];

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A pending event-fee request already stands on this object');
		$this->validator->validate($this->objectRequest($fee), $existing);
	}//end testASecondPendingEventFeeIsRefused()

	/**
	 * A contribution request for its child.
	 *
	 * @param string $child The child's id.
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function contributionFor(string $child, array $overrides = []): array {
		return $this->objectRequest(
			array_merge(
				[
					'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
					'requestType' => 'contribution',
					'amount' => 60.0,
					'beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'LearnerProfile', 'id' => $child],
				],
				$overrides
			)
		);
	}//end contributionFor()

	/**
	 * The beneficiary joins the uniqueness key: one fee item carries a pending
	 * request per child, and a second for the same child is refused by name
	 * (REQ-SCON-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testTheBeneficiaryJoinsTheUniquenessKey(): void {
		$existing = [$this->contributionFor('child-a', ['id' => 'pr-a'])];

		// Child B on the same fee item is a different pending request.
		$this->validator->validate($this->contributionFor('child-b'), $existing);

		// Child A again is the duplicate the key exists to refuse.
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('pr-a');
		$this->validator->validate($this->contributionFor('child-a'), $existing);
	}//end testTheBeneficiaryJoinsTheUniquenessKey()

	/**
	 * A request without a beneficiary still collides with another without one,
	 * so the leges and dwangsom rule is unchanged, and it does not collide with
	 * a request that names a child.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testAMissingBeneficiaryOnlyCollidesWithAnotherMissingOne(): void {
		$existing = [$this->contributionFor('child-a', ['id' => 'pr-a'])];
		$household = $this->contributionFor('child-a');
		unset($household['beneficiary']);

		$this->validator->validate($household, $existing);

		self::assertSame('', $this->validator->beneficiaryKey(null));
		self::assertSame('learner|learniq|LearnerProfile|child-a', $this->validator->beneficiaryKey($existing[0]['beneficiary']));
	}//end testAMissingBeneficiaryOnlyCollidesWithAnotherMissingOne()

	/**
	 * The beneficiary type is part of who it is: a learner and a customer with
	 * the same id are two people.
	 *
	 * @return void
	 */
	public function testTheBeneficiaryTypeSeparatesTwoPeopleWithOneId(): void {
		$existing = [$this->contributionFor('alice', ['id' => 'pr-a', 'beneficiary' => ['type' => 'learner', 'id' => 'alice']])];

		$this->validator->validate(
			$this->contributionFor('alice', ['beneficiary' => ['type' => 'customer', 'id' => 'alice']]),
			$existing
		);

		self::assertTrue(true);
	}//end testTheBeneficiaryTypeSeparatesTwoPeopleWithOneId()

	/**
	 * A beneficiary that does not say who it is, is refused.
	 *
	 * @return void
	 */
	public function testABeneficiaryWithoutAnIdIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('beneficiary');

		$this->validator->validate($this->contributionFor('x', ['beneficiary' => ['type' => 'learner']]));
	}//end testABeneficiaryWithoutAnIdIsRefused()
	/**
	 * A transfer reference shorter than six characters is refused, so a stray
	 * number in a remittance text cannot match a request (REQ-ORS-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 */
	public function testShortReferenceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least 6 characters');
		$this->validator->validate($this->objectRequest(['paymentReference' => 'WC26']));
	}//end testShortReferenceIsRefused()

	/**
	 * A reference another open request already carries is refused, whatever
	 * its case; a closed request does not hold its reference (REQ-ORS-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 */
	public function testDuplicateOpenReferenceIsRefused(): void {
		$other = ['subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-7']];
		$captured = $this->objectRequest(array_merge($other, ['id' => 'pr-1', 'state' => 'captured', 'paymentReference' => 'WC26-0042']));
		$authorized = $this->objectRequest(array_merge($other, ['id' => 'pr-2', 'state' => 'authorized', 'paymentReference' => 'wc26-0042']));

		$this->validator->validate($this->objectRequest(['paymentReference' => 'WC26-0042']), [], [$captured]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The payment reference WC26-0042 is already on an open request');
		$this->validator->validate($this->objectRequest(['paymentReference' => 'WC26-0042']), [], [$captured, $authorized]);
	}//end testDuplicateOpenReferenceIsRefused()

	/**
	 * A request with a reference and the invoice flag is one the real
	 * PaymentRequest schema accepts (REQ-ORS-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 */
	public function testARequestWithAReferenceFitsTheSchema(): void {
		$request = $this->objectRequest(
			[
				'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
				'requestType' => 'event-fee',
				'amount' => 85.0,
				'currency' => 'EUR',
				'paymentGateway' => 'mollie',
				'description' => 'WC26-0042 Winter Camp 2026',
				'paymentReference' => 'WC26-0042',
				'invoiceRequested' => false,
				'receiptSentAt' => '2026-10-03T09:12:00Z',
			]
		);

		$this->validator->validate($request);

		self::assertSame([], RegisterSchema::errors('PaymentRequest', $request));
	}//end testARequestWithAReferenceFitsTheSchema()
}//end class
