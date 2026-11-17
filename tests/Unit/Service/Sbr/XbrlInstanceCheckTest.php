<?php

/**
 * Unit tests for XbrlInstanceCheck: the failing items of an XBRL instance.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XbrlInstanceCheck::class)]
final class XbrlInstanceCheckTest extends TestCase {

	private const TAXONOMY = [
		'taxonomyId' => 'nt-2026',
		'entryPoints' => ['kvk-jaarrekening'],
		'requiredConcepts' => ['kvk-jaarrekening' => ['jenv-bw2-i:Equity', 'Assets']],
		'summations' => [
			['entryPoint' => 'kvk-jaarrekening', 'total' => 'jenv-bw2-i:Assets', 'parts' => ['jenv-bw2-i:AssetsNoncurrent', 'jenv-bw2-i:AssetsCurrent']],
			['entryPoint' => 'belastingdienst-ob', 'total' => 'jenv-bw2-i:Assets', 'parts' => ['jenv-bw2-i:Nothing']],
		],
	];

	private XbrlInstanceCheck $check;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));
		$this->check = new XbrlInstanceCheck($l10n);
	}

	private static function xml(array $facts, bool $identifier = true, bool $period = true): string {
		$body = '';
		foreach ($facts as $name => $value) {
			$body .= "<{$name} contextRef=\"c1\" unitRef=\"EUR\" decimals=\"0\">{$value}</{$name}>";
		}

		$id  = $identifier === true ? '<xbrli:identifier scheme="http://www.kvk.nl/kvk-id/">12345678</xbrli:identifier>' : '<xbrli:identifier scheme="x"> </xbrli:identifier>';
		$per = $period === true ? '<xbrli:period><xbrli:startDate>2025-01-01</xbrli:startDate><xbrli:endDate>2025-12-31</xbrli:endDate></xbrli:period>' : '';

		return '<?xml version="1.0"?><xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:jenv-bw2-i="http://www.nltaxonomie.nl/jenv/bw2">'
			. "<xbrli:context id=\"c1\"><xbrli:entity>{$id}</xbrli:entity>{$per}</xbrli:context>{$body}</xbrli:xbrl>";
	}

	private static function instance(string $xml, array $fields = []): array {
		return $fields + ['entryPoint' => 'kvk-jaarrekening', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31', 'instanceXml' => $xml];
	}

	public function testACleanInstanceHasNoProblems(): void {
		$xml = self::xml(['jenv-bw2-i:Equity' => '400', 'jenv-bw2-i:Assets' => '1000.00', 'jenv-bw2-i:AssetsNoncurrent' => '600', 'jenv-bw2-i:AssetsCurrent' => '400.004']);

		$this->assertSame([], $this->check->problems(self::instance($xml), self::TAXONOMY));
	}

	public function testAMissingEquityConceptIsNamed(): void {
		$xml = self::xml(['jenv-bw2-i:Assets' => '1000', 'jenv-bw2-i:AssetsNoncurrent' => '600', 'jenv-bw2-i:AssetsCurrent' => '400', 'jenv-bw2-i:Equity' => '']);

		$this->assertSame(['The required concept jenv-bw2-i:Equity is missing.'], $this->check->problems(self::instance($xml), self::TAXONOMY));
	}

	public function testATotalThatDoesNotAddUpNamesTheTotalAndItsParts(): void {
		$xml = self::xml(['jenv-bw2-i:Equity' => '400', 'jenv-bw2-i:Assets' => '1000', 'jenv-bw2-i:AssetsNoncurrent' => '600', 'jenv-bw2-i:AssetsCurrent' => '350.5']);

		$this->assertSame(
			['The total jenv-bw2-i:Assets (1000) is not the sum of jenv-bw2-i:AssetsNoncurrent, jenv-bw2-i:AssetsCurrent (950.5).'],
			$this->check->problems(self::instance($xml), self::TAXONOMY)
		);
	}

	public function testMissingPeriodIdentifierAndTaxonomyAreEachNamed(): void {
		$xml = self::xml(['jenv-bw2-i:Equity' => '1'], identifier: false, period: false);

		$this->assertSame(
			[
				'The reporting period is missing.',
				'No taxonomy is effective for this period that carries the entry point kvk-jaarrekening.',
				'The entity identifier is missing.',
				'The instance names no period in its context.',
			],
			$this->check->problems(self::instance($xml, ['reportingPeriodEnd' => '']), null)
		);
	}

	public function testAnInstanceWithoutXmlIsRefusedForThat(): void {
		$this->assertSame(['The instance has no valid XBRL document.'], $this->check->problems(self::instance('<not-closed'), self::TAXONOMY));
		$this->assertSame(['The instance has no valid XBRL document.'], $this->check->problems(self::instance(''), self::TAXONOMY));
	}

	public function testAnotherEntryPointsRulesDoNotApply(): void {
		$xml = self::xml(['jenv-bw2-i:Assets' => '5']);

		$this->assertSame([], $this->check->problems(self::instance($xml, ['entryPoint' => 'belastingdienst-vpb']), self::TAXONOMY));
		$this->assertSame([], XbrlInstanceCheck::summations(self::TAXONOMY, 'belastingdienst-vpb'));
		$this->assertSame([['total' => 'jenv-bw2-i:Assets', 'parts' => ['jenv-bw2-i:Nothing']]], XbrlInstanceCheck::summations(self::TAXONOMY, 'belastingdienst-ob'));
	}

	public function testRequiredConceptsIgnoreEmptyAndNonStringEntries(): void {
		$taxonomy = ['requiredConcepts' => ['x' => [' a:B ', '', 7, 'a:B']]];

		$this->assertSame(['a:B'], XbrlInstanceCheck::requiredConcepts($taxonomy, 'x'));
		$this->assertSame([], XbrlInstanceCheck::requiredConcepts(['requiredConcepts' => ['x' => 'a:B']], 'x'));
	}
}
