<?php

/**
 * Unit tests for CbcrChecks and GirChecks: every check passes and fails.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\CbcrChecks;
use OCA\Shillinq\Standards\Checks\GirChecks;
use OCA\Shillinq\Standards\RuleCatalogue;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Standards\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each CbC and GIR check on a passing and a failing joined return.
 */
#[CoversClass(CbcrChecks::class)]
#[CoversClass(GirChecks::class)]
#[UsesClass(RuleEngine::class)]
#[UsesClass(RuleCatalogue::class)]
#[UsesClass(Violation::class)]
final class CbcrGirChecksTest extends TestCase {
	/**
	 * A joined CbC return that passes every check.
	 *
	 * @return array<string,mixed>
	 */
	private static function goodCbc(): array {
		return [
			'reconciliationResidual' => 2500000.0,
			'reconciliationItems' => [['description' => 'Consolidation eliminations', 'amount' => 2500000.0]],
			'_entities' => [
				['id' => 'ge-holding', 'tin' => '001234567', 'figures' => ['source' => 'books', 'unmappedAccounts' => []]],
				['id' => 'ge-trust', 'tin' => 'NOTIN', 'tinMissingReason' => 'A trust without a tax number', 'figures' => ['source' => 'books']],
			],
			'_summaries' => [
				[
					'jurisdiction' => 'NL', 'mainBusinessActivities' => ['CBC511'], 'unrelatedPartyRevenue' => 300.0,
					'relatedPartyRevenue' => 40.0, 'totalRevenue' => 340.0,
				],
				['jurisdiction' => 'DE', 'mainBusinessActivities' => ['CBC505'], 'unrelatedPartyRevenue' => 60.0],
			],
		];
	}//end goodCbc()

	/**
	 * Each CbC check, with a change that makes only it fail.
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	public static function cbcBreaks(): array {
		return [
			'an entity without figures' => [
				'cbc-figures-every-entity',
				static fn (array $o): array => array_replace_recursive($o, ['_entities' => [1 => ['figures' => null]]]),
			],
			'an unmapped account' => [
				'cbc-accounts-mapped',
				static fn (array $o): array => array_replace_recursive($o, ['_entities' => [0 => ['figures' => ['unmappedAccounts' => ['4500']]]]]),
			],
			'no TIN' => ['cbc-entity-tin', static fn (array $o): array => array_replace_recursive($o, ['_entities' => [0 => ['tin' => '']]])],
			'NOTIN without a reason' => [
				'cbc-entity-tin',
				static fn (array $o): array => array_replace_recursive($o, ['_entities' => [1 => ['tinMissingReason' => ' ']]]),
			],
			'a jurisdiction without activity' => [
				'cbc-jurisdiction-activity',
				static fn (array $o): array => array_replace($o, ['_summaries' => [['jurisdiction' => 'DE']]]),
			],
			'a total that does not add up' => [
				'cbc-revenue-total',
				static fn (array $o): array => array_replace_recursive($o, ['_summaries' => [0 => ['totalRevenue' => 341.0]]]),
			],
			'an unexplained residual' => ['cbc-residual-explained', static fn (array $o): array => array_replace($o, ['reconciliationItems' => []])],
			'entered figures' => [
				'cbc-figures-from-books',
				static fn (array $o): array => array_replace_recursive($o, ['_entities' => [1 => ['figures' => ['source' => 'entered']]]]),
			],
		];
	}//end cbcBreaks()

	/**
	 * The good return passes every CbC check.
	 *
	 * @return void
	 */
	public function testAGoodCbcReturnPassesEveryCheck(): void {
		$this->assertSame([], RuleEngine::evaluate('CbcrReturn', self::goodCbc(), ['jurisdiction' => 'NL']));
		$this->assertSame(
			[
				'cbc-figures-every-entity', 'cbc-accounts-mapped', 'cbc-entity-tin',
				'cbc-jurisdiction-activity', 'cbc-revenue-total', 'cbc-residual-explained',
				'cbc-figures-from-books',
			],
			array_keys(CbcrChecks::checks()['CbcrReturn'])
		);
		$this->assertSame([], CbcrChecks::seedSpec());
	}//end testAGoodCbcReturnPassesEveryCheck()

	/**
	 * Breaking one thing fails exactly its check, with the catalogue's severity.
	 *
	 * @param string   $ruleId The check that should fail.
	 * @param callable $break  The change.
	 *
	 * @return void
	 */
	#[DataProvider('cbcBreaks')]
	public function testBreakingOneThingFailsItsCheck(string $ruleId, callable $break): void {
		$violations = RuleEngine::evaluate('CbcrReturn', $break(self::goodCbc()), ['jurisdiction' => 'NL']);

		$this->assertSame([$ruleId], array_map(static fn (Violation $violation): string => $violation->ruleId, $violations));
		$expected = 'mandatory';
		if ($ruleId === 'cbc-figures-from-books') {
			$expected = 'recommended';
		}

		$this->assertSame($expected, $violations[0]->severity);
	}//end testBreakingOneThingFailsItsCheck()

	/**
	 * A residual within EUR 1M needs no explanation.
	 *
	 * @return void
	 */
	public function testASmallResidualNeedsNoItems(): void {
		$return = array_replace(self::goodCbc(), ['reconciliationResidual' => -1000000.0, 'reconciliationItems' => []]);

		$this->assertSame([], RuleEngine::evaluate('CbcrReturn', $return, ['jurisdiction' => 'NL']));
	}//end testASmallResidualNeedsNoItems()

	/**
	 * GIR: covered by safe harbour or approved computation passes; an uncovered jurisdiction or a missing element fails.
	 *
	 * @return void
	 */
	public function testTheGirChecksPassAndFail(): void {
		$good = [
			'missingElements' => [],
			'_jurisdictions' => [
				'DE' => ['safeHarbour' => true, 'computationApproved' => false],
				'IE' => ['safeHarbour' => false, 'computationApproved' => true],
			],
		];
		$ids  = static fn (array $o): array => array_map(
			static fn (Violation $violation): string => $violation->ruleId,
			RuleEngine::evaluate('GlobeInformationReturn', $o, ['jurisdiction' => 'NL'])
		);

		$this->assertSame([], $ids($good));
		$this->assertSame(
			['gir-jurisdiction-covered'],
			$ids(array_replace_recursive($good, ['_jurisdictions' => ['IE' => ['computationApproved' => false]]]))
		);
		$this->assertSame(
			['gir-no-missing-elements'],
			$ids(array_replace($good, ['missingElements' => [['jurisdiction' => 'IE', 'element' => 'GloBEIncome']]]))
		);
		$this->assertSame([], GirChecks::seedSpec());
	}//end testTheGirChecksPassAndFail()
}//end class
