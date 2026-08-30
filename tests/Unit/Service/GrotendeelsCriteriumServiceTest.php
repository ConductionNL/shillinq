<?php

/**
 * Unit tests for GrotendeelsCriteriumService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use OCA\Shillinq\Service\GrotendeelsCriteriumService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-007: grotendeels-criterium daily evaluation.
 */
final class GrotendeelsCriteriumServiceTest extends TestCase {

	/**
	 * Build a service with a real guard.
	 *
	 * @return GrotendeelsCriteriumService
	 */
	private function build(): GrotendeelsCriteriumService {
		$logger = $this->createMock(LoggerInterface::class);
		$guard = new UrencriteriumYearGuard(logger: $logger);
		return new GrotendeelsCriteriumService(guard: $guard, logger: $logger);
	}//end build()

	/**
	 * telOndernemingsUren sums getoldeUren (preferring it over uren).
	 *
	 * @return void
	 */
	public function testTelOndernemingsUrenPrefersGetoldeUren(): void {
		$service = $this->build();
		$total = $service->telOndernemingsUren(
			dagregistraties: [
				['hours' => 8, 'countedHours' => 8],
				['hours' => 6, 'countedHours' => 4],
				['hours' => 2],
			]
		);

		self::assertSame(14.0, $total);

	}//end testTelOndernemingsUrenPrefersGetoldeUren()

	/**
	 * telOndernemingsUren tolerates non-array entries (graceful for streaming feeds).
	 *
	 * @return void
	 */
	public function testTelOndernemingsUrenIgnoresNonArrayEntries(): void {
		$service = $this->build();
		$total = $service->telOndernemingsUren(
			dagregistraties: [
				['hours' => 8],
				'garbage',
				123,
				['hours' => 4, 'countedHours' => 4],
			]
		);

		self::assertSame(12.0, $total);

	}//end testTelOndernemingsUrenIgnoresNonArrayEntries()

	/**
	 * No loondienst yields NIET_TOEPASSELIJK regardless of onderneming hours.
	 *
	 * @return void
	 */
	public function testNoLoondienstYieldsNietToepasselijk(): void {
		$patch = $this->build()->bouwPatch(
			dagregistraties: [['hours' => 800]],
			employmentHours: 0.0
		);

		self::assertSame('NON_APPLICABLE', $patch['largelyCriterium']);
		self::assertFalse($patch['blokkeertZelfstandigenaftrek']);

	}//end testNoLoondienstYieldsNietToepasselijk()

	/**
	 * Onderneming >50% yields GROTENDEELS_ONDERNEMING (does not block aftrek).
	 *
	 * @return void
	 */
	public function testGrotendeelsOndernemingDoesNotBlockAftrek(): void {
		$patch = $this->build()->bouwPatch(
			dagregistraties: [['hours' => 1200]],
			employmentHours: 800.0
		);

		self::assertSame('LARGELY_ENTERPRISE', $patch['largelyCriterium']);
		self::assertFalse($patch['blokkeertZelfstandigenaftrek']);

	}//end testGrotendeelsOndernemingDoesNotBlockAftrek()

	/**
	 * Loondienst-majority blocks the zelfstandigenaftrek (REQ-URC-007).
	 *
	 * @return void
	 */
	public function testLoondienstMajorityBlocksAftrek(): void {
		$patch = $this->build()->bouwPatch(
			dagregistraties: [['hours' => 400]],
			employmentHours: 1200.0
		);

		self::assertSame('NON_LARGELY_ENTERPRISE', $patch['largelyCriterium']);
		self::assertTrue($patch['blokkeertZelfstandigenaftrek']);

	}//end testLoondienstMajorityBlocksAftrek()

	/**
	 * Equal onderneming + loondienst hours (50/50) is NOT grotendeels (>50 required).
	 *
	 * @return void
	 */
	public function testFiftyFiftyIsNietGrotendeels(): void {
		$patch = $this->build()->bouwPatch(
			dagregistraties: [['hours' => 800]],
			employmentHours: 800.0
		);

		self::assertSame('NON_LARGELY_ENTERPRISE', $patch['largelyCriterium']);

	}//end testFiftyFiftyIsNietGrotendeels()

}//end class
