<?php

/**
 * ConsumerCostHoldTest
 *
 * Task 2.4 of receivables-automatic-dunning: the edges of the consumer's
 * collection-cost hold that the sending tests do not reach. A letter run
 * without a readable date does not start the period, and with two delivered
 * letters the first one counts (REQ-RAD-007, design D7).
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
use PHPUnit\Framework\TestCase;

/**
 * The consumer's collection-cost hold.
 *
 * @covers \OCA\Shillinq\Service\Dunning\ConsumerCostHold
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ConsumerCostHoldTest extends TestCase {

	/**
	 * The ladder: stage 3 is the 14-day letter, stage 4 charges the costs.
	 *
	 * @return array<int, mixed>
	 */
	private static function stages(): array {
		return [
			'not a stage',
			['nr' => 3, 'statutoryEffect' => ConsumerCostHold::LETTER_EFFECT],
			['nr' => 4, 'statutoryEffect' => null],
		];
	}//end stages()

	/**
	 * A delivered letter run.
	 *
	 * @param string $executedOn When it went out.
	 *
	 * @return array<string, mixed>
	 */
	private static function letter(string $executedOn): array {
		return ['stageNr' => 3, 'deliveryStatus' => 'DELIVERED', 'executedOn' => $executedOn];
	}//end letter()

	/**
	 * Apply the hold for a consumer on 1 June 2026.
	 *
	 * @param array<int, array<string, mixed>> $runs The invoice's runs.
	 *
	 * @return mixed The collection costs left on the stage.
	 */
	private static function costsAfter(array $runs): mixed {
		$params = (new ConsumerCostHold())->apply(
			params: ['collectionCostAmount' => 181.5],
			customer: ['legalName' => 'J. de Vries'],
			invoice: [],
			stages: self::stages(),
			runs: $runs,
			now: new DateTimeImmutable('2026-06-01T09:00:00+00:00')
		);

		return $params['collectionCostAmount'];
	}//end costsAfter()

	/**
	 * A letter run without a date, or with one nobody can read, does not
	 * start the consumer's period: the costs stay off.
	 *
	 * @return void
	 */
	public function testALetterWithoutAReadableDateDoesNotStartThePeriod(): void {
		self::assertNull(self::costsAfter([self::letter('')]));
		self::assertNull(self::costsAfter([self::letter('last spring')]));
	}//end testALetterWithoutAReadableDateDoesNotStartThePeriod()

	/**
	 * With two delivered letters the first one counts, so a later resend does
	 * not push the costs back.
	 *
	 * @return void
	 */
	public function testTheFirstDeliveredLetterCounts(): void {
		self::assertSame(181.5, self::costsAfter([self::letter('2026-05-25T09:00:00+00:00'), self::letter('2026-05-01T09:00:00+00:00')]));
		self::assertNull(self::costsAfter([self::letter('2026-05-25T09:00:00+00:00')]));
	}//end testTheFirstDeliveredLetterCounts()
}//end class
