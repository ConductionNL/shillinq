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
}//end class
