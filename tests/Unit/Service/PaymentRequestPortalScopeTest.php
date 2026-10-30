<?php

/**
 * Unit tests for PaymentRequestPortalScope.
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
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PaymentRequestPortalScope;
use PHPUnit\Framework\TestCase;

/**
 * The one rule that gives a request without an invoice its portal scope.
 */
final class PaymentRequestPortalScopeTest extends TestCase {
	private const CUSTOMER = '20000000-0000-4000-8000-000000000002';

	/**
	 * A leges request on a case, owed by a known customer.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function leges(array $overrides = []): array {
		return array_merge(
			[
				'subjectKind' => 'object',
				'requestType' => 'leges',
				'amount' => 125.0,
				'debtor' => ['customerMasterId' => self::CUSTOMER],
				'state' => 'pending',
			],
			$overrides
		);
	}//end leges()

	/**
	 * A request without an invoice and with a customer debtor gets that
	 * customer as its portal scope (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testARequestWithoutAnInvoiceGetsItsCustomer(): void {
		$scope = new PaymentRequestPortalScope();

		self::assertSame(self::CUSTOMER, $scope->stamp(request: $this->leges())['customerId']);
		self::assertTrue($scope->needsStamp(request: $this->leges()));
	}//end testARequestWithoutAnInvoiceGetsItsCustomer()

	/**
	 * An invoice-backed request reaches the portal through its invoice and is
	 * left alone; so is a name-and-email debtor and a request with no debtor
	 * (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testOtherRequestsAreLeftAlone(): void {
		$scope = new PaymentRequestPortalScope();
		$cases = [
			'invoice-backed' => $this->leges(['requestType' => 'contribution', 'invoiceReference' => '30000000-0000-4000-8000-000000000003']),
			'name and email' => $this->leges(['debtor' => ['name' => 'J. Jansen', 'email' => 'j.jansen@example.nl']]),
			'no debtor' => array_diff_key($this->leges(), ['debtor' => true]),
			'empty customer' => $this->leges(['debtor' => ['customerMasterId' => '']]),
			'malformed debtor' => $this->leges(['debtor' => 'cm-1']),
		];

		foreach ($cases as $label => $request) {
			self::assertSame($request, $scope->stamp(request: $request), $label);
			self::assertFalse($scope->needsStamp(request: $request), $label);
		}
	}//end testOtherRequestsAreLeftAlone()

	/**
	 * A request that already carries the scope is not rewritten, so the
	 * backfill saves nothing on a second run (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testAStampedRequestNeedsNoStamp(): void {
		$scope = new PaymentRequestPortalScope();
		$stamped = $scope->stamp(request: $this->leges());

		self::assertFalse($scope->needsStamp(request: $stamped));
		self::assertSame($stamped, $scope->stamp(request: $stamped));
	}//end testAStampedRequestNeedsNoStamp()
}//end class
