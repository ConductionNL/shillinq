<?php

/**
 * Characterisation tests for every branch of the consumer collection-cost hold.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\Service\Dunning\ConsumerCostHold;
use OCA\Shillinq\Service\Dunning\CostHoldRequest;
use PHPUnit\Framework\TestCase;

/**
 * Pins who counts as a consumer, which runs count as the delivered letter
 * and the 15-day boundary.
 */
final class ConsumerCostHoldCasesTest extends TestCase {

	/**
	 * The collection costs left after the hold, on 1 June 2026 09:00 UTC.
	 *
	 * @param array<string, mixed>             $customer The customer.
	 * @param array<int, array<string, mixed>> $runs     The invoice's runs.
	 *
	 * @return mixed The collection costs left.
	 */
	private function costsAfter(array $customer, array $runs): mixed {
		$stages = [
			'junk',
			['nr' => 3, 'statutoryEffect' => ConsumerCostHold::LETTER_EFFECT],
			['nr' => 5, 'statutoryEffect' => ConsumerCostHold::LETTER_EFFECT],
			['nr' => 4],
		];

		$request = new CostHoldRequest();
		$request->params   = ['collectionCostAmount' => 40.0, 'other' => 'kept'];
		$request->customer = $customer;
		$request->invoice  = ['buyerVatId' => ($customer['buyerVatId'] ?? '')];
		$request->stages   = $stages;
		$request->runs     = $runs;
		$request->now      = new DateTimeImmutable('2026-06-01T09:00:00+00:00');

		$params = (new ConsumerCostHold())->apply($request);

		self::assertSame('kept', $params['other']);
		return $params['collectionCostAmount'];
	}//end costsAfter()

	/**
	 * A business debtor keeps its costs; a consumer without a counting letter
	 * loses them.
	 *
	 * @return void
	 */
	public function testOnlyConsumersAreHeld(): void {
		self::assertSame(40.0, $this->costsAfter(['kvkNumber' => '123'], []));
		self::assertSame(40.0, $this->costsAfter(['vatId' => 'NL1'], []));
		self::assertSame(40.0, $this->costsAfter(['buyerVatId' => 'BE2'], []));
		self::assertNull($this->costsAfter(['kvkNumber' => '  ', 'vatId' => ''], []));

		$request         = new CostHoldRequest();
		$request->params = ['collectionCostAmount' => 0];
		$request->now    = new DateTimeImmutable('2026-06-01');
		$zero            = (new ConsumerCostHold())->apply($request);
		self::assertSame(['collectionCostAmount' => 0], $zero);
	}//end testOnlyConsumersAreHeld()

	/**
	 * Only a delivered run of a letter stage with a readable date counts,
	 * the earliest one wins, and it must be at least 15 days old.
	 *
	 * @return void
	 */
	public function testWhichRunStartsThePeriod(): void {
		$old = '2026-05-17T09:00:00+00:00';
		self::assertSame(40.0, $this->costsAfter([], [['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => $old]]));
		self::assertSame(40.0, $this->costsAfter([], [['stageNr' => '5', 'deliveryStatus' => 'DELIVERED', 'executedOn' => $old]]));
		self::assertNull($this->costsAfter([], [['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => '2026-05-17T09:00:01+00:00']]));
		self::assertNull($this->costsAfter([], [['stageNr' => 4, 'deliveryStatus' => 'DELIVERED', 'executedOn' => $old]]));
		self::assertNull($this->costsAfter([], [['stageNr' => 3, 'deliveryStatus' => 'SENT', 'executedOn' => $old]]));
		self::assertNull($this->costsAfter([], [['deliveryStatus' => 'DELIVERED', 'executedOn' => $old]]));
		self::assertSame(
			40.0,
			$this->costsAfter(
				[],
				[
					['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => '2026-05-30'],
					['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => 'garbage'],
					['stageNr' => 5, 'deliveryStatus' => 'DELIVERED', 'executedOn' => '2026-05-01'],
					['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => '2026-05-20'],
				]
			)
		);
	}//end testWhichRunStartsThePeriod()
}//end class
