<?php

/**
 * InvoiceGenerationRequest validation tests (issue #111).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Request
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Request;

use InvalidArgumentException;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use PHPUnit\Framework\TestCase;

/**
 * Request validation tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceGenerationRequestTest extends TestCase {

	/**
	 * t_and_m without a rateCardId is rejected.
	 *
	 * @return void
	 */
	public function testTAndMRequiresRateCard(): void {
		$this->expectException(InvalidArgumentException::class);
		new InvoiceGenerationRequest(
			administrationId: 'a1',
			billingModel: 't_and_m',
			customerId: 'c1',
			fromDate: '2026-05-01',
			toDate: '2026-05-31',
		);

	}//end testTAndMRequiresRateCard()

	/**
	 * retainer model requires retainerScheduleId.
	 *
	 * @return void
	 */
	public function testRetainerRequiresSchedule(): void {
		$this->expectException(InvalidArgumentException::class);
		new InvoiceGenerationRequest(
			administrationId: 'a1',
			billingModel: 'retainer',
			customerId: 'c1',
			fromDate: '2026-05-01',
			toDate: '2026-05-31',
		);

	}//end testRetainerRequiresSchedule()

	/**
	 * fixed_fee without a fee is rejected.
	 *
	 * @return void
	 */
	public function testFixedFeeRequiresAmount(): void {
		$this->expectException(InvalidArgumentException::class);
		new InvoiceGenerationRequest(
			administrationId: 'a1',
			billingModel: 'fixed_fee',
			customerId: 'c1',
			fromDate: '2026-05-01',
			toDate: '2026-05-31',
		);

	}//end testFixedFeeRequiresAmount()

	/**
	 * fromArray decodes a t_and_m body correctly.
	 *
	 * @return void
	 */
	public function testFromArrayHappyPath(): void {
		$req = InvoiceGenerationRequest::fromArray(
			'adm-1',
			[
				'billingModel' => 't_and_m',
				'customerId' => 'c1',
				'fromDate' => '2026-05-01',
				'toDate' => '2026-05-31',
				'rateCardId' => 'rate-consulting',
				'timeEntryIds' => ['t1', 't2'],
				'expenseIds' => ['e1'],
			]
		);

		$this->assertSame('adm-1', $req->administrationId);
		$this->assertSame('t_and_m', $req->billingModel);
		$this->assertSame(['t1', 't2'], $req->timeEntryIds);
		$this->assertSame(['e1'], $req->expenseIds);

	}//end testFromArrayHappyPath()

	/**
	 * Date inversion is rejected.
	 *
	 * @return void
	 */
	public function testDateOrderEnforced(): void {
		$this->expectException(InvalidArgumentException::class);
		new InvoiceGenerationRequest(
			administrationId: 'a1',
			billingModel: 'milestone',
			customerId: 'c1',
			fromDate: '2026-05-31',
			toDate: '2026-05-01',
			milestoneId: 'ms-1',
		);

	}//end testDateOrderEnforced()

}//end class
