<?php

/**
 * Characterisation tests for InvoiceGenerationRequest validation and decoding.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every refusal message, in check order, and every accepted body, of
 * the invoice-generation request.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceGenerationRequestValidationTest extends TestCase {

	/**
	 * A valid t_and_m body.
	 *
	 * @return array<string,mixed> The body.
	 */
	private static function body(): array {
		return [
			'billingModel' => 't_and_m',
			'customerId' => 'c-1',
			'fromDate' => '2026-01-01',
			'toDate' => '2026-01-31',
			'rateCardId' => 'rc-1',
		];

	}//end body()

	/**
	 * Refused bodies with the message they raise.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}> Cases.
	 */
	public static function refusals(): array {
		$base = self::body();
		return [
			'no administration'       => ['', $base, 'administrationId is required.'],
			'administration first'    => ['', ['billingModel' => 'x'], 'administrationId is required.'],
			'unknown model'           => ['a-1', array_merge($base, ['billingModel' => 'barter']), 'billingModel must be one of t_and_m, fixed_fee, milestone, retainer, mixed, usage'],
			'model before customer'   => ['a-1', ['billingModel' => 'barter'], 'billingModel must be one of t_and_m, fixed_fee, milestone, retainer, mixed, usage'],
			'no customer'             => ['a-1', array_merge($base, ['customerId' => '']), 'customerId is required.'],
			'no from date'            => ['a-1', array_merge($base, ['fromDate' => '']), 'fromDate and toDate are required.'],
			'no to date'              => ['a-1', array_merge($base, ['toDate' => '']), 'fromDate and toDate are required.'],
			'dates reversed'          => ['a-1', array_merge($base, ['fromDate' => '2026-02-01']), 'fromDate must be <= toDate.'],
			'dates before model rule' => ['a-1', ['billingModel' => 'usage', 'customerId' => 'c', 'fromDate' => '2', 'toDate' => '1'], 'fromDate must be <= toDate.'],
			't_and_m without card'    => ['a-1', array_merge($base, ['rateCardId' => null]), 'rateCardId is required for t_and_m model.'],
			'retainer no schedule'    => ['a-1', array_merge($base, ['billingModel' => 'retainer']), 'retainerScheduleId is required for retainer model.'],
			'mixed no schedule'       => ['a-1', array_merge($base, ['billingModel' => 'mixed']), 'retainerScheduleId is required for mixed model.'],
			'fixed fee missing'       => ['a-1', array_merge($base, ['billingModel' => 'fixed_fee']), 'fixedFeeCents > 0 is required for fixed_fee model.'],
			'fixed fee zero'          => ['a-1', array_merge($base, ['billingModel' => 'fixed_fee', 'fixedFeeCents' => '0']), 'fixedFeeCents > 0 is required for fixed_fee model.'],
			'milestone missing'       => ['a-1', array_merge($base, ['billingModel' => 'milestone']), 'milestoneId is required for milestone model.'],
			'usage without readings'  => ['a-1', array_merge($base, ['billingModel' => 'usage']), 'meterReadingIds is required for usage model.'],
		];

	}//end refusals()

	/**
	 * Each refused body raises its message.
	 *
	 * @param string              $administrationId The scope.
	 * @param array<string,mixed> $body             The body.
	 * @param string              $message          The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testRefusal(string $administrationId, array $body, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		InvoiceGenerationRequest::fromArray($administrationId, $body);

	}//end testRefusal()

	/**
	 * Accepted bodies per model.
	 *
	 * @return array<string,array{0:array<string,mixed>}> Cases.
	 */
	public static function acceptedBodies(): array {
		$base = self::body();
		return [
			't_and_m'           => [$base],
			'same day'          => [array_merge($base, ['toDate' => '2026-01-01'])],
			'retainer'          => [array_merge($base, ['billingModel' => 'retainer', 'rateCardId' => null, 'retainerScheduleId' => 'rs'])],
			'mixed'             => [array_merge($base, ['billingModel' => 'mixed', 'retainerScheduleId' => 'rs'])],
			'fixed fee'         => [array_merge($base, ['billingModel' => 'fixed_fee', 'rateCardId' => null, 'fixedFeeCents' => 1])],
			'milestone'         => [array_merge($base, ['billingModel' => 'milestone', 'rateCardId' => null, 'milestoneId' => 'm'])],
			'usage'             => [array_merge($base, ['billingModel' => 'usage', 'rateCardId' => null, 'meterReadingIds' => ['r']])],
		];

	}//end acceptedBodies()

	/**
	 * Each accepted body builds a request.
	 *
	 * @param array<string,mixed> $body The body.
	 *
	 * @return void
	 */
	#[DataProvider('acceptedBodies')]
	public function testAccepted(array $body): void {
		$request = InvoiceGenerationRequest::fromArray('a-1', $body);

		self::assertSame($body['billingModel'], $request->billingModel);

	}//end testAccepted()

	/**
	 * fromArray casts every field and maps absent optionals to null.
	 *
	 * @return void
	 */
	public function testFromArrayDecodesEveryField(): void {
		$full = InvoiceGenerationRequest::fromArray('a-1', [
			'billingModel' => 'mixed',
			'customerId' => 7,
			'fromDate' => '2026-01-01',
			'toDate' => '2026-01-31',
			'timeEntryIds' => ['x' => 1, 'y' => 2],
			'expenseIds' => 3,
			'rateCardId' => 11,
			'retainerScheduleId' => 12,
			'fixedFeeCents' => '1500',
			'milestoneId' => 13,
			'projectId' => 14,
			'notes' => 15,
			'meterReadingIds' => [16],
			'usageRatePlanId' => 17,
		]);

		self::assertSame(
			['a-1', 'mixed', '7', ['1', '2'], ['3'], '11', '12', 1500, '13', '14', '15', ['16'], '17'],
			[
				$full->administrationId, $full->billingModel, $full->customerId, $full->timeEntryIds,
				$full->expenseIds, $full->rateCardId, $full->retainerScheduleId, $full->fixedFeeCents,
				$full->milestoneId, $full->projectId, $full->notes, $full->meterReadingIds, $full->usageRatePlanId,
			]
		);

		$bare = InvoiceGenerationRequest::fromArray('a-1', ['billingModel' => 'usage', 'customerId' => 'c', 'fromDate' => '1', 'toDate' => '2', 'meterReadingIds' => ['r'], 'notes' => null]);
		self::assertSame(
			[[], [], null, null, null, null, null, null, null],
			[
				$bare->timeEntryIds, $bare->expenseIds, $bare->rateCardId, $bare->retainerScheduleId,
				$bare->fixedFeeCents, $bare->milestoneId, $bare->projectId, $bare->notes, $bare->usageRatePlanId,
			]
		);

	}//end testFromArrayDecodesEveryField()
}//end class
