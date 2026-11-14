<?php

/**
 * Unit tests for RulesAuditCommand and RulesSeedTestDataCommand.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\RulesAuditCommand;
use OCA\Shillinq\Command\RulesSeedTestDataCommand;
use OCA\Shillinq\Service\RuleAuditService;
use OCA\Shillinq\Service\RuleTestDataSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two occ commands over the rule corpus: the audit asks the service for
 * the requested jurisdiction (NL by default) and prints its report; the
 * seeder prints what it backfilled. Both succeed.
 *
 * @covers \OCA\Shillinq\Command\RulesAuditCommand
 * @covers \OCA\Shillinq\Command\RulesSeedTestDataCommand
 */
final class RulesCommandsTest extends TestCase {

	/**
	 * A full audit report.
	 *
	 * @param array<int, array{ruleId: string, count: int}> $top The top violated rules.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function report(array $top): array {
		return [
			'catalogueVersion' => '2026.11',
			'corpusTotal' => 120,
			'machineCheckable' => 80,
			'enforceableRules' => 60,
			'coveragePct' => 75.0,
			'objectsChecked' => 40,
			'objectsCompliant' => 37,
			'objectsWithViolations' => 3,
			'violationsBySeverity' => ['mandatory' => 2, 'recommended' => 1],
			'types' => [
				'ARInvoice' => ['checked' => 30, 'compliant' => 28, 'withViolations' => 2],
				'GLTransaction' => ['checked' => 10, 'compliant' => 9, 'withViolations' => 1],
			],
			'topViolatedRules' => $top,
		];
	}//end report()

	/**
	 * The audit command is registered under its occ name with an NL default.
	 *
	 * @return void
	 */
	public function testTheAuditCommandIsConfigured(): void {
		$command = new RulesAuditCommand($this->createMock(RuleAuditService::class));

		$this->assertSame('shillinq:rules:audit', $command->getName());
		$this->assertSame('NL', $command->getDefinition()->getOption('jurisdiction')->getDefault());
	}//end testTheAuditCommandIsConfigured()

	/**
	 * The audit asks for the default jurisdiction and prints the whole report.
	 *
	 * @return void
	 */
	public function testTheAuditPrintsTheReport(): void {
		$service = $this->createMock(RuleAuditService::class);
		$service->expects($this->once())
			->method('audit')
			->with(['jurisdiction' => 'NL'])
			->willReturn(self::report([['ruleId' => 'BR-CO-10', 'count' => 2], ['ruleId' => 'BR-S-08', 'count' => 1]]));
		$tester = new CommandTester(new RulesAuditCommand($service));

		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Shillinq rule-compliance audit', $display);
		$this->assertStringContainsString('  catalogue version : 2026.11', $display);
		$this->assertStringContainsString('  corpus rules      : 120 (machine-checkable: 80)', $display);
		$this->assertStringContainsString('  enforceable today : 60 (75.0% of machine-checkable)', $display);
		$this->assertStringContainsString('  with violations   : 3', $display);
		$this->assertStringContainsString('  violations        : 2 mandatory / 0 conditional / 1 recommended', $display);
		$this->assertStringContainsString('    ARInvoice        checked=30 compliant=28 withViolations=2', $display);
		$this->assertStringContainsString('  top violated rules:', $display);
		$this->assertStringContainsString('    BR-CO-10                           2', $display);
	}//end testTheAuditPrintsTheReport()

	/**
	 * Another jurisdiction is passed on, and no violated rules prints no top list.
	 *
	 * @return void
	 */
	public function testAnotherJurisdictionWithoutViolations(): void {
		$service = $this->createMock(RuleAuditService::class);
		$service->expects($this->once())->method('audit')->with(['jurisdiction' => 'BE'])->willReturn(self::report([]));
		$tester = new CommandTester(new RulesAuditCommand($service));

		$this->assertSame(0, $tester->execute(['--jurisdiction' => 'BE']));
		$this->assertStringNotContainsString('top violated rules', $tester->getDisplay());
	}//end testAnotherJurisdictionWithoutViolations()

	/**
	 * The seeder command prints each count, an absent invoice count as zero.
	 *
	 * @return void
	 */
	public function testTheSeederPrintsWhatItBackfilled(): void {
		$seeder = $this->createMock(RuleTestDataSeeder::class);
		$seeder->expects($this->once())
			->method('seed')
			->willReturn(['sourceReferencesAdded' => 4, 'linesAdded' => 6, 'alreadyCompliant' => 11]);
		$command = new RulesSeedTestDataCommand($seeder);
		$tester  = new CommandTester($command);

		$this->assertSame('shillinq:rules:seed-testdata', $command->getName());
		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('  sourceReference backfilled : 4', $display);
		$this->assertStringContainsString('  GLLines added              : 6', $display);
		$this->assertStringContainsString('  ARInvoice lines backfilled : 0', $display);
		$this->assertStringContainsString('  already compliant          : 11', $display);
		$this->assertStringContainsString('occ shillinq:rules:audit', $display);
	}//end testTheSeederPrintsWhatItBackfilled()
}//end class
