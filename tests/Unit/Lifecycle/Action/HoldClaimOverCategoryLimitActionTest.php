<?php

/**
 * Unit tests for HoldClaimOverCategoryLimitAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\Action\HoldClaimOverCategoryLimitAction;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-ECM-004: a submitted claim with a line over its category's limit waits
 * for approval and shows the limit; a claim within every limit goes on.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(HoldClaimOverCategoryLimitAction::class)]
#[UsesClass(ObjectIdentifier::class)]
final class HoldClaimOverCategoryLimitActionTest extends TestCase {
	/**
	 * A EUR 210 hotel night over the EUR 150 limit holds the claim and shows
	 * the limit.
	 *
	 * @return void
	 */
	public function testAHotelNightOverTheLimitHoldsTheClaim(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'HoldClaimOverCategoryLimitAction: a line is over its category limit; the claim waits for approval',
			['claimId' => 'claim-1', 'lines' => ['R-10']]
		);

		$result = $this->action(hotelAmount: 210.0, logger: $logger)->execute($this->claim(), [], [], HoldClaimOverCategoryLimitAction::class);

		$this->assertSame(HoldClaimOverCategoryLimitAction::PENDING, $result['approvalState']);
		$this->assertSame(
			[['receiptId' => 'r-hotel', 'line' => 'R-10', 'category' => 'hotel', 'amount' => 210.0, 'limit' => 150.0, 'per' => 'night']],
			$result[HoldClaimOverCategoryLimitAction::EXCEEDANCES]
		);
	}//end testAHotelNightOverTheLimitHoldsTheClaim()

	/**
	 * A EUR 120 hotel night stays within the limit: the claim goes on.
	 *
	 * @return void
	 */
	public function testAClaimWithinTheLimitsGoesOn(): void {
		$result = $this->action(hotelAmount: 120.0)->execute($this->claim(), [], [], HoldClaimOverCategoryLimitAction::class);

		$this->assertSame('not-required', $result['approvalState']);
		$this->assertSame([], $result[HoldClaimOverCategoryLimitAction::EXCEEDANCES]);
	}//end testAClaimWithinTheLimitsGoesOn()

	/**
	 * A per-night limit counts the receipt's nights; the claim's own policy
	 * is read by its policyId.
	 *
	 * @return void
	 */
	public function testAPerNightLimitCountsTheNights(): void {
		$claim = $this->claim() + ['reimbursementPolicyId' => 'POL-NL-01'];

		$this->assertSame([], $this->action(hotelAmount: 280.0, nights: 2)->exceedances(claim: $claim));
		$this->assertSame(160.0, $this->action(hotelAmount: 320.0, nights: 2)->exceedances(claim: $claim)[0]['amount']);
	}//end testAPerNightLimitCountsTheNights()

	/**
	 * Without a policy there are no limits to pass.
	 *
	 * @return void
	 */
	public function testWithoutAPolicyNothingIsHeld(): void {
		$claim = ['id' => 'claim-2', 'administrationId' => 'adm-9', 'receiptIds' => ['r-hotel'], 'approvalState' => 'not-required'];

		$this->assertSame([], $this->action(hotelAmount: 900.0)->exceedances(claim: $claim));
	}//end testWithoutAPolicyNothingIsHeld()

	/**
	 * A check that cannot run holds the claim rather than letting it skip
	 * approval.
	 *
	 * @return void
	 */
	public function testAFailedCheckHoldsTheClaim(): void {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnSelf();
		$store->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'HoldClaimOverCategoryLimitAction: the category limits could not be checked; the claim waits for approval',
			['claimId' => 'claim-1', 'exception' => 'database gone']
		);

		$action = new HoldClaimOverCategoryLimitAction(objectService: $store, registerLocation: $this->location(), logger: $logger);
		$result = $action->execute($this->claim(), [], [], HoldClaimOverCategoryLimitAction::class);

		$this->assertSame(HoldClaimOverCategoryLimitAction::PENDING, $result['approvalState']);
	}//end testAFailedCheckHoldsTheClaim()

	/**
	 * A submitted claim with a hotel night and a lunch.
	 *
	 * @return array<string, mixed>
	 */
	private function claim(): array {
		return [
			'id' => 'claim-1',
			'administrationId' => 'adm-1',
			'receiptIds' => ['r-hotel', 'r-lunch'],
			'approvalState' => 'not-required',
			'state' => 'submitted',
		];
	}//end claim()

	/**
	 * The action over a policy with a EUR 150 per night hotel limit.
	 *
	 * @param float                $hotelAmount The hotel receipt's amount.
	 * @param int                  $nights      The nights it covers.
	 * @param LoggerInterface|null $logger      The logger, a mock when null.
	 *
	 * @return HoldClaimOverCategoryLimitAction
	 */
	private function action(float $hotelAmount, int $nights = 1, ?LoggerInterface $logger = null): HoldClaimOverCategoryLimitAction {
		$store = new InMemoryObjectServiceStub(
			data: [
				'ReimbursementPolicy' => [
					[
						'id' => 'p-1',
						'policyId' => 'POL-NL-01',
						'administrationId' => 'adm-1',
						'categoryLimits' => [
							['category' => 'hotel', 'limit' => 150, 'per' => 'night'],
							['category' => 'meals', 'limit' => 75],
							['category' => '', 'limit' => 1],
							'not a limit',
						],
					],
				],
				'Receipt' => [
					['id' => 'r-hotel', 'receiptNumber' => 'R-10', 'category' => 'hotel', 'amountInBaseCurrency' => $hotelAmount, 'nights' => $nights],
					['id' => 'r-lunch', 'receiptNumber' => 'R-11', 'category' => 'meals', 'amount' => 37.5],
				],
			]
		);

		return new HoldClaimOverCategoryLimitAction(
			objectService: $store,
			registerLocation: $this->location(),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end action()

	/**
	 * The register location.
	 *
	 * @return RegisterLocation
	 */
	private function location(): RegisterLocation {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return $location;
	}//end location()
}//end class
