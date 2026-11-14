<?php

/**
 * Unit tests for DunningStageDefaultTexts: which built-in letter a stage and
 * a language get when the ladder names no template.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DunningStageDefaultTextsTest extends TestCase {

	public function testEachStageHasItsOwnSubjectInDutchAndEnglish(): void {
		$texts = new DunningStageDefaultTexts();

		$this->assertSame('Vriendelijke herinnering: factuur {factuurNummer}', $texts->for(1, 'nl')['subject']);
		$this->assertSame('Second reminder: invoice {factuurNummer}', $texts->for(2, 'en')['subject']);
		$this->assertSame('Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen', $texts->for(3, 'nl')['subject']);
		$this->assertSame('Notice of default: invoice {factuurNummer}', $texts->for(4, 'en')['subject']);
		$this->assertSame('Overdracht aan incassobureau: factuur {factuurNummer}', $texts->for(5, 'nl')['subject']);
	}//end testEachStageHasItsOwnSubjectInDutchAndEnglish()

	public function testAStageOutsideTheLadderTakesTheNearestEnd(): void {
		$texts = new DunningStageDefaultTexts();

		$this->assertSame($texts->for(1, 'nl'), $texts->for(0, 'nl'));
		$this->assertSame($texts->for(1, 'en'), $texts->for(-3, 'en'));
		$this->assertSame($texts->for(5, 'nl'), $texts->for(9, 'nl'));
	}//end testAStageOutsideTheLadderTakesTheNearestEnd()

	public function testAnyLanguageButEnglishGetsDutch(): void {
		$texts = new DunningStageDefaultTexts();

		$this->assertSame($texts->for(2, 'nl'), $texts->for(2, 'de'));
		$this->assertSame($texts->for(2, 'nl'), $texts->for(2, ''));
		$this->assertSame($texts->for(2, 'nl'), $texts->for(2, 'EN'), 'the language code is matched exactly');
		$this->assertNotSame($texts->for(2, 'nl'), $texts->for(2, 'en'));
	}//end testAnyLanguageButEnglishGetsDutch()

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function stages(): array {
		$cases = [];
		foreach ([1, 2, 3, 4, 5] as $stage) {
			foreach (['nl', 'en'] as $language) {
				$cases["stage $stage $language"] = [$stage, $language];
			}
		}

		return $cases;
	}//end stages()

	#[DataProvider('stages')]
	public function testEveryLetterAddressesTheCustomerAndNamesTheInvoice(int $stage, string $language): void {
		$text = (new DunningStageDefaultTexts())->for($stage, $language);

		$this->assertSame(['subject', 'body'], array_keys($text));
		$this->assertStringContainsString('{klantNaam}', $text['body']);
		$this->assertStringContainsString('{factuurNummer}', $text['body']);
		$this->assertStringContainsString('{outstandingAmount}', $text['body']);
	}//end testEveryLetterAddressesTheCustomerAndNamesTheInvoice()

	public function testTheNoticeAndTheDefaultLetterNameTheCostsAndTheInterest(): void {
		$texts = new DunningStageDefaultTexts();

		foreach ([3, 4] as $stage) {
			foreach (['nl', 'en'] as $language) {
				$body = $texts->for($stage, $language)['body'];
				$this->assertStringContainsString('{incassokosten}', $body, "stage $stage $language");
				$this->assertStringContainsString('{rente}', $body, "stage $stage $language");
			}
		}

		$this->assertStringContainsString('14 dagen', $texts->for(3, 'nl')['body'], 'the fourteen-day term before costs');
		$this->assertStringNotContainsString('{incassokosten}', $texts->for(1, 'nl')['body'], 'a friendly reminder names no costs');
	}//end testTheNoticeAndTheDefaultLetterNameTheCostsAndTheInterest()
}//end class
