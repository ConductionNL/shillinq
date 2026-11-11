<?php

/**
 * Golden tests for InvesteringsaftrekEligibilityGuard: full classifications and log context.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-investeringsaftrek/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AssetEligibilityCheck;
use OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the complete classification (flags and every rationale), the debug log
 * context and every cumulation answer before the guard takes one value object.
 */
final class InvesteringsaftrekEligibilityGoldenTest extends TestCase {
	/**
	 * Classify one asset with a logger that records its debug context.
	 *
	 * @param array{0:int,1:bool,2:bool,3:bool,4:bool} $facts   Value, energy hit, environment hit, vamil permitted, KIA excluded.
	 * @param array<int, mixed>                        $context Receives the debug log context.
	 *
	 * @return array<string, mixed>
	 */
	private function classified(array $facts, array &$context): array {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->willReturnCallback(
			function (string $message, array $logged) use (&$context): void {
				$context = [$message, $logged];
			}
		);
		$guard = new InvesteringsaftrekEligibilityGuard(logger: $logger);

		return $guard->classify(
			new AssetEligibilityCheck(
				acquisitionValue: $facts[0],
				energyListHit: $facts[1],
				environmentListHit: $facts[2],
				vamilPermitted: $facts[3],
				kiaExcluded: $facts[4]
			)
		);
	}//end classified()

	/**
	 * Every flag and rationale of a classification, and its debug context.
	 *
	 * @param array{0:int,1:bool,2:bool,3:bool,4:bool} $facts     The asset facts.
	 * @param array<string, bool>                      $flags     Expected kia/eia/mia/vamil.
	 * @param array<string, string>                    $rationale Expected rationale.
	 *
	 * @return void
	 *
	 * @dataProvider assets
	 */
	public function testClassification(array $facts, array $flags, array $rationale): void {
		$context = [];
		$result = $this->classified(facts: $facts, context: $context);

		$this->assertSame($flags + ['rationale' => $rationale], $result);
		$this->assertSame(['InvesteringsaftrekEligibilityGuard: classify', ['acquisitionValue' => $facts[0]] + $flags], $context);
	}//end testClassification()

	/**
	 * Assets across every threshold and list combination.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function assets(): array {
		$kiaIn = 'KIA: in aanmerking (binnen drempel en plafond)';
		return [
			'everything' => [
				[5000000, true, true, true, false],
				['kia' => true, 'eia' => true, 'mia' => true, 'vamil' => true],
				[
					'kia' => $kiaIn,
					'eia' => 'EIA: in aanmerking (Energielijst-code match, bedrag >= EUR 2.500)',
					'mia' => 'MIA: in aanmerking (Milieulijst-code match, bedrag >= EUR 2.500)',
					'vamil' => 'Vamil: in aanmerking (Milieulijst + vamilToegestaan)',
				],
			],
			'below the KIA minimum, no list' => [
				[44999, false, false, false, false],
				['kia' => false, 'eia' => false, 'mia' => false, 'vamil' => false],
				[
					'kia' => 'KIA: onder per-asset minimum van EUR 450',
					'eia' => 'EIA: geen Energielijst-code match',
					'mia' => 'MIA: geen Milieulijst-code match',
					'vamil' => 'Vamil: geen Milieulijst-code match',
				],
			],
			'above the KIA ceiling, vamil not permitted' => [
				[39223001, true, true, false, false],
				['kia' => false, 'eia' => true, 'mia' => true, 'vamil' => false],
				[
					'kia' => 'KIA: boven plafond van EUR 392.230',
					'eia' => 'EIA: in aanmerking (Energielijst-code match, bedrag >= EUR 2.500)',
					'mia' => 'MIA: in aanmerking (Milieulijst-code match, bedrag >= EUR 2.500)',
					'vamil' => 'Vamil: code staat geen willekeurige afschrijving toe',
				],
			],
			'excluded and below the list minimum' => [
				[249999, true, true, true, true],
				['kia' => false, 'eia' => false, 'mia' => false, 'vamil' => false],
				[
					'kia' => 'KIA: uitgesloten bedrijfsmiddel (art. 3.45 Wet IB 2001)',
					'eia' => 'EIA: onder minimum van EUR 2.500',
					'mia' => 'MIA: onder minimum van EUR 2.500',
					'vamil' => 'Vamil: onder minimum van EUR 2.500',
				],
			],
			'the exact boundaries' => [
				[250000, true, false, true, false],
				['kia' => true, 'eia' => true, 'mia' => false, 'vamil' => false],
				[
					'kia' => $kiaIn,
					'eia' => 'EIA: in aanmerking (Energielijst-code match, bedrag >= EUR 2.500)',
					'mia' => 'MIA: geen Milieulijst-code match',
					'vamil' => 'Vamil: geen Milieulijst-code match',
				],
			],
			'the KIA minimum and ceiling' => [
				[45000, false, false, false, false],
				['kia' => true, 'eia' => false, 'mia' => false, 'vamil' => false],
				[
					'kia' => $kiaIn,
					'eia' => 'EIA: geen Energielijst-code match',
					'mia' => 'MIA: geen Milieulijst-code match',
					'vamil' => 'Vamil: geen Milieulijst-code match',
				],
			],
			'the KIA ceiling itself' => [
				[39223000, false, false, false, false],
				['kia' => true, 'eia' => false, 'mia' => false, 'vamil' => false],
				[
					'kia' => $kiaIn,
					'eia' => 'EIA: geen Energielijst-code match',
					'mia' => 'MIA: geen Milieulijst-code match',
					'vamil' => 'Vamil: geen Milieulijst-code match',
				],
			],
		];
	}//end assets()

	/**
	 * Every cumulation answer, in rule order.
	 *
	 * @return void
	 */
	public function testCumulation(): void {
		$guard = new InvesteringsaftrekEligibilityGuard(logger: $this->createMock(LoggerInterface::class));
		$eiaMia = [
			'allowed' => false,
			'violation' => 'Art. 3.42 lid 7: EIA en MIA kunnen niet op hetzelfde bedrijfsmiddel worden gecombineerd. Kies een van beide.',
		];

		$this->assertSame($eiaMia, $guard->validateCumulation(['EIA', 'MIA', 'Vamil']));
		$this->assertSame(
			['allowed' => false, 'violation' => 'Vamil is alleen toegestaan op Milieulijst-bedrijfsmiddelen, niet in combinatie met EIA.'],
			$guard->validateCumulation(['Vamil', 'EIA', 'KIA'])
		);
		$this->assertSame(['allowed' => true, 'violation' => null], $guard->validateCumulation(['MIA', 'Vamil', 'KIA']));
		$this->assertSame(['allowed' => true, 'violation' => null], $guard->validateCumulation([]));
	}//end testCumulation()
}//end class
