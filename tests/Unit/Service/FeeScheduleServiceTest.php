<?php

/**
 * Unit tests for FeeScheduleService.
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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006, REQ-SOPR-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the validity window, the channel fall-through, the product amount and
 * the overlap refusal (REQ-SOPR-006).
 */
final class FeeScheduleServiceTest extends TestCase {
	/**
	 * Build the service over rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return FeeScheduleService The service.
	 */
	private function service(array $rows): FeeScheduleService {
		$double = new class($rows) {
			/**
			 * The schema the fluent chain last selected.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->rows[$this->schema] ?? []);
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new FeeScheduleService(
			objectService: new DuckObjectServiceAdapter(inner: $double),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The tuple a lookup asks with.
	 *
	 * @return array<string, string> The tuple.
	 */
	private function tuple(): array {
		return [
			'targetApp' => 'dossiq',
			'register' => 'dossiq',
			'schema' => 'Zaak',
			'typeProperty' => 'caseType',
			'typeValue' => 'bouwvergunning',
		];
	}//end tuple()

	/**
	 * One schedule row.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function schedule(array $overrides = []): array {
		return array_merge(
			$this->tuple(),
			[
				'id' => 'fs-1',
				'legalBasis' => [
					'regulation' => 'Legesverordening 2026',
					'article' => '2.3.1',
					'effectiveDate' => '2026-01-01',
				],
				'amount' => 245.0,
				'currency' => 'EUR',
				'payAtIntake' => 'required',
				'validFrom' => '2026-01-01',
				'validTo' => '2026-12-31',
				'intakeChannel' => '',
			],
			$overrides
		);
	}//end schedule()

	/**
	 * A schedule inside its window resolves with its own amount.
	 *
	 * @return void
	 */
	public function testAScheduleInsideItsWindowResolves(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$resolved = $service->resolve($this->tuple(), '', '2026-06-01');

		self::assertNotNull($resolved);
		self::assertSame(245.0, $resolved['amount']);
		self::assertSame('schedule', $resolved['amountSource']);
	}//end testAScheduleInsideItsWindowResolves()

	/**
	 * A schedule that has expired resolves to nothing. A fee that quietly went on
	 * applying after its validTo is money charged without a basis.
	 *
	 * @return void
	 */
	public function testAnExpiredScheduleDoesNotResolve(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		self::assertNull($service->resolve($this->tuple(), '', '2027-01-01'));
	}//end testAnExpiredScheduleDoesNotResolve()

	/**
	 * A schedule that has not started yet resolves to nothing either.
	 *
	 * @return void
	 */
	public function testAFutureScheduleDoesNotResolve(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		self::assertNull($service->resolve($this->tuple(), '', '2025-12-31'));
	}//end testAFutureScheduleDoesNotResolve()

	/**
	 * A type with no schedule at all resolves to null, not to zero.
	 *
	 * @return void
	 */
	public function testATypeWithNoScheduleResolvesToNull(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['typeValue' => 'melding'])]]);

		self::assertNull($service->resolve($this->tuple(), '', '2026-06-01'));
	}//end testATypeWithNoScheduleResolvesToNull()

	/**
	 * The channel-specific schedule wins over the default for that channel, which
	 * is the point of having a channel at all: a counter may charge differently
	 * from a website.
	 *
	 * @return void
	 */
	public function testTheChannelSpecificScheduleWins(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [
					$this->schedule(['id' => 'fs-default']),
					$this->schedule(['id' => 'fs-desk', 'intakeChannel' => 'desk', 'amount' => 290.0]),
				],
			]
		);

		$resolved = $service->resolve($this->tuple(), 'desk', '2026-06-01');

		self::assertSame(290.0, $resolved['amount']);
	}//end testTheChannelSpecificScheduleWins()

	/**
	 * A channel with no schedule of its own falls through to the default, so
	 * adding a channel never silently makes a type free.
	 *
	 * @return void
	 */
	public function testAnUnpricedChannelFallsThroughToTheDefault(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [
					$this->schedule(['id' => 'fs-default']),
					$this->schedule(['id' => 'fs-desk', 'intakeChannel' => 'desk', 'amount' => 290.0]),
				],
			]
		);

		$resolved = $service->resolve($this->tuple(), 'post', '2026-06-01');

		self::assertSame(245.0, $resolved['amount']);
	}//end testAnUnpricedChannelFallsThroughToTheDefault()

	/**
	 * A schedule referencing a pipelinq product reads the amount FROM the product,
	 * so the price lives in one place (ADR-107 decision 3).
	 *
	 * @return void
	 */
	public function testAProductBackedScheduleReadsTheProductPrice(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [$this->schedule(['productRef' => 'prod-1', 'amount' => 1.0])],
				'Product' => [['id' => 'prod-1', 'price' => 312.5, 'currency' => 'EUR']],
			]
		);

		$resolved = $service->resolve($this->tuple(), '', '2026-06-01');

		self::assertSame(312.5, $resolved['amount']);
		self::assertSame('product', $resolved['amountSource']);
	}//end testAProductBackedScheduleReadsTheProductPrice()

	/**
	 * A product that cannot be read leaves the schedule WITHOUT an amount, rather
	 * than falling back to its own stale one. A stale fee charged confidently is
	 * worse than a fee the caller can see it cannot price.
	 *
	 * @return void
	 */
	public function testAMissingProductLeavesTheScheduleUnpriced(): void {
		$service = $this->service(
			['FeeSchedule' => [$this->schedule(['productRef' => 'prod-gone', 'amount' => 999.0])]]
		);

		$resolved = $service->resolve($this->tuple(), '', '2026-06-01');

		self::assertArrayNotHasKey('amount', $resolved);
		self::assertSame('product-missing', $resolved['amountSource']);
	}//end testAMissingProductLeavesTheScheduleUnpriced()

	/**
	 * A second schedule overlapping the first is refused, and the refusal names
	 * the window it collides with (REQ-SOPR-006).
	 *
	 * @return void
	 */
	public function testAnOverlappingScheduleIsRefused(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('2026-01-01');

		$service->assertNoOverlap($this->schedule(['id' => 'fs-2', 'validFrom' => '2026-06-01', 'validTo' => '']));
	}//end testAnOverlappingScheduleIsRefused()

	/**
	 * A schedule that starts the day after the first one ends is accepted: that is
	 * how a tariff change is administered.
	 *
	 * @return void
	 */
	public function testASucceedingScheduleIsAccepted(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$service->assertNoOverlap($this->schedule(['id' => 'fs-2', 'validFrom' => '2027-01-01', 'validTo' => '']));

		self::assertTrue(true);
	}//end testASucceedingScheduleIsAccepted()

	/**
	 * A schedule for another channel may share the window: the two do not compete.
	 *
	 * @return void
	 */
	public function testAnotherChannelMayShareTheWindow(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$service->assertNoOverlap($this->schedule(['id' => 'fs-2', 'intakeChannel' => 'desk']));

		self::assertTrue(true);
	}//end testAnotherChannelMayShareTheWindow()

	/**
	 * A schedule with no validFrom is refused: a fee without a start date cannot
	 * be applied to a day, so it would silently never resolve.
	 *
	 * @return void
	 */
	public function testAScheduleWithoutAStartIsRefused(): void {
		$service = $this->service(['FeeSchedule' => []]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('validFrom');

		$service->assertNoOverlap($this->schedule(['validFrom' => '']));
	}//end testAScheduleWithoutAStartIsRefused()

	/**
	 * The leaf's path: given an object, find which property the schedules look at
	 * and resolve from the object's own value (REQ-SOPR-008).
	 *
	 * @return void
	 */
	public function testResolveForObjectReadsTheTypeFromTheObject(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$resolved = $service->resolveForObject(
			'dossiq',
			'Zaak',
			['id' => 'zaak-7', 'caseType' => 'bouwvergunning'],
			'',
			'2026-06-01'
		);

		self::assertNotNull($resolved);
		self::assertSame(245.0, $resolved['amount']);
	}//end testResolveForObjectReadsTheTypeFromTheObject()

	/**
	 * An object whose type carries no schedule resolves to null through the same
	 * path, rather than falling back to any schedule for the register.
	 *
	 * @return void
	 */
	public function testResolveForObjectReturnsNullForAnUnpricedType(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		self::assertNull(
			$service->resolveForObject('dossiq', 'Zaak', ['id' => 'zaak-8', 'caseType' => 'melding'], '', '2026-06-01')
		);
	}//end testResolveForObjectReturnsNullForAnUnpricedType()

	/**
	 * A percentage fee is taken of the object's base value and raised to its minimum.
	 *
	 * @return void
	 */
	public function testAPercentageFeeIsWorkedOutWithItsMinimum(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['calculation' => 'percentage', 'percentage' => 2.35, 'minimumAmount' => 125, 'baseProperty' => 'bouwkosten'])]]);

		$large = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'bouwkosten' => 100000], '', '2026-06-01');
		$small = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'bouwkosten' => 1000], '', '2026-06-01');
		$none  = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning'], '', '2026-06-01');

		self::assertSame(2350.0, $large['amount']);
		self::assertSame(125.0, $small['amount']);
		self::assertArrayNotHasKey('amount', $none);
		self::assertSame('needs-base', $none['amountSource']);
	}//end testAPercentageFeeIsWorkedOutWithItsMinimum()

	/**
	 * The fee for the object's own variant wins over the type's; another variant falls back to the type's.
	 *
	 * @return void
	 */
	public function testTheVariantsFeeWinsOverTheTypes(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [
					$this->schedule(['id' => 'fs-type', 'amount' => 245.0]),
					$this->schedule(['id' => 'fs-kap', 'variantValue' => 'Kappen', 'calculation' => 'per_unit', 'unitPrice' => 82.4, 'baseProperty' => 'aantalBomen']),
				],
			]
		);

		$kap  = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'variant' => 'Kappen', 'aantalBomen' => 2], '', '2026-06-01');
		$bouw = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning', 'variant' => 'Regulier'], '', '2026-06-01');

		self::assertSame(['fs-kap', 164.8], [$kap['id'], $kap['amount']]);
		self::assertSame(['fs-type', 245.0], [$bouw['id'], $bouw['amount']]);
	}//end testTheVariantsFeeWinsOverTheTypes()

	/**
	 * A calculation that lacks a field it needs is refused at save.
	 *
	 * @return void
	 */
	public function testACalculationWithoutItsNumbersIsRefused(): void {
		$service = $this->service(['FeeSchedule' => []]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A percentage fee needs percentage and baseProperty.');
		$service->assertNoOverlap($this->schedule(['calculation' => 'percentage']), []);
	}//end testACalculationWithoutItsNumbersIsRefused()

	/**
	 * A refund rule beside the type's charge is not an overlap, and is never charged at intake.
	 *
	 * @return void
	 */
	public function testARefundRuleSitsBesideTheCharge(): void {
		$refund  = $this->schedule(['id' => 'fs-refund', 'calculation' => 'refund_percentage', 'percentage' => 75]);
		$service = $this->service(['FeeSchedule' => [$this->schedule(), $refund]]);

		$service->assertNoOverlap($refund, [$this->schedule()]);
		$resolved = $service->resolveForObject('dossiq', 'Zaak', ['caseType' => 'bouwvergunning'], '', '2026-06-01');

		self::assertSame('fs-1', $resolved['id']);
	}//end testARefundRuleSitsBesideTheCharge()
}//end class
