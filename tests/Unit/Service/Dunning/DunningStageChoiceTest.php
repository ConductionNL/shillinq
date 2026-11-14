<?php

/**
 * Unit tests for DunningStageChoice: when an invoice is overdue, which stage is due,
 * the single cost-free stage, and the parameters a stage is dispatched with.
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

use DateTimeImmutable;
use OCA\Shillinq\Service\Dunning\DunningStageChoice;
use OCA\Shillinq\Service\Dunning\DunningStageSelector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Dunning\DunningStageChoice
 */
class DunningStageChoiceTest extends TestCase {

	/**
	 * The ladder's stages.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const STAGES = [
		['nr' => 1, 'channel' => 'EMAIL', 'templateId' => 'tpl-1'],
		['nr' => 2, 'channel' => 'POST', 'templateId' => 'tpl-2'],
	];

	/**
	 * The selector double.
	 *
	 * @var DunningStageSelector&MockObject
	 */
	private DunningStageSelector&MockObject $selector;

	/**
	 * A choice for the invoice, with the selector, the ladder and a fixed now.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return DunningStageChoice
	 */
	private function choice(array $invoice = ['id' => 'inv-1', 'grossAmount' => '121.50']): DunningStageChoice {
		$this->selector = $this->createMock(DunningStageSelector::class);
		$choice           = new DunningStageChoice(administrationId: 'adm-1', invoice: $invoice);
		$choice->selector = $this->selector;
		$choice->now      = new DateTimeImmutable('2026-11-13T09:00:00+00:00');
		$choice->ladder   = ['ladderId' => 'ladder-b2c', 'stages' => self::STAGES];

		return $choice;
	}//end choice()

	/**
	 * An invoice with an id and days in arrears is overdue; the id may sit under @self.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithArrearsIsOverdue(): void {
		$choice = $this->choice(invoice: ['@self' => ['id' => 'inv-9'], 'dueDate' => '2026-10-01']);
		$this->selector->expects($this->once())
			->method('daysOverdue')
			->with(['@self' => ['id' => 'inv-9'], 'dueDate' => '2026-10-01'], $choice->now)
			->willReturn(43);

		$this->assertTrue($choice->isOverdue());
		$this->assertSame('inv-9', $choice->invoiceId);
		$this->assertSame(43, $choice->daysInArrears);
	}//end testAnInvoiceWithArrearsIsOverdue()

	/**
	 * Zero days in arrears still counts; no arrears or no id does not.
	 *
	 * @return void
	 */
	public function testOverdueNeedsAnIdAndArrears(): void {
		$choice = $this->choice();
		$this->selector->method('daysOverdue')->willReturn(0);
		$this->assertTrue($choice->isOverdue());

		$choice = $this->choice();
		$this->selector->method('daysOverdue')->willReturn(null);
		$this->assertFalse($choice->isOverdue());
		$this->assertNull($choice->daysInArrears);

		$choice = $this->choice(invoice: ['grossAmount' => 10]);
		$this->selector->method('daysOverdue')->willReturn(30);
		$this->assertFalse($choice->isOverdue());
		$this->assertSame('', $choice->invoiceId);
	}//end testOverdueNeedsAnIdAndArrears()

	/**
	 * The due stage is asked for with the ladder's stages, the runs so far, the arrears and now.
	 *
	 * @return void
	 */
	public function testTheDueStageComesFromTheSelector(): void {
		$choice                = $this->choice();
		$choice->runs          = [['stageNr' => 1]];
		$choice->daysInArrears = 20;
		$this->selector->expects($this->exactly(2))
			->method('next')
			->with(self::STAGES, [['stageNr' => 1]], 20, $choice->now)
			->willReturnOnConsecutiveCalls(self::STAGES[1], null);

		$this->assertTrue($choice->chooseDueStage());
		$this->assertSame(self::STAGES[1], $choice->stage);
		$this->assertFalse($choice->chooseDueStage());
		$this->assertNull($choice->stage);
	}//end testTheDueStageComesFromTheSelector()

	/**
	 * The only stage is the ladder's stage one, numbered one.
	 *
	 * @return void
	 */
	public function testTheOnlyStageIsStageOne(): void {
		$choice        = $this->choice();
		$choice->stage = self::STAGES[1];
		$this->selector->expects($this->once())->method('definition')->with(self::STAGES, 1)->willReturn(['nr' => '1', 'channel' => 'EMAIL']);

		$choice->useOnlyStage();

		$this->assertSame(['nr' => 1, 'channel' => 'EMAIL'], $choice->stage);
	}//end testTheOnlyStageIsStageOne()

	/**
	 * A ladder without stage one keeps the chosen stage, renumbered one.
	 *
	 * @return void
	 */
	public function testWithoutStageOneTheChosenStageIsRenumbered(): void {
		$choice        = $this->choice();
		$choice->stage = self::STAGES[1];
		$this->selector->method('definition')->willReturn(null);

		$choice->useOnlyStage();

		$this->assertSame(['nr' => 1, 'channel' => 'POST', 'templateId' => 'tpl-2'], $choice->stage);
	}//end testWithoutStageOneTheChosenStageIsRenumbered()

	/**
	 * The only stage is used once: before any run it is taken with the cost-free parameters, after a run not at all.
	 *
	 * @return void
	 */
	public function testTheOnlyStageIsUsedOnce(): void {
		$choice         = $this->choice();
		$choice->params = ['costAmount' => 40.0];
		$this->selector->method('definition')->willReturn(self::STAGES[0]);

		$this->assertTrue($choice->useOnlyStageOnce(costFreeParams: ['costAmount' => 0.0, 'voluntary' => true]));
		$this->assertSame(self::STAGES[0], $choice->stage);
		$this->assertSame(['costAmount' => 0.0, 'voluntary' => true], $choice->params);

		$after         = $this->choice();
		$after->runs   = [['stageNr' => 1]];
		$after->params = ['costAmount' => 40.0];
		$this->selector->expects($this->never())->method('definition');

		$this->assertFalse($after->useOnlyStageOnce(costFreeParams: ['costAmount' => 0.0]));
		$this->assertNull($after->stage);
		$this->assertSame(['costAmount' => 40.0], $after->params);
	}//end testTheOnlyStageIsUsedOnce()

	/**
	 * Stage parameters take channel and template from the stage, and the amount from the invoice.
	 *
	 * @return void
	 */
	public function testStageParamsComeFromTheStage(): void {
		$choice            = $this->choice();
		$choice->invoiceId = 'inv-1';
		$choice->stage     = self::STAGES[1];

		$this->assertSame(
			[
				'invoiceId' => 'inv-1',
				'ladderId' => 'ladder-b2c',
				'stageNr' => 2,
				'channel' => 'POST',
				'templateId' => 'tpl-2',
				'invoiceAmount' => 121.5,
				'stage' => self::STAGES[1],
			],
			$choice->stageParams(stageNr: 2)
		);
	}//end testStageParamsComeFromTheStage()

	/**
	 * Dispatch overrides win over the stage and are passed along; without either, email and no template.
	 *
	 * @return void
	 */
	public function testOverridesWinAndDefaultsFillIn(): void {
		$choice            = $this->choice(invoice: ['id' => 'inv-2']);
		$choice->invoiceId = 'inv-2';
		$choice->stage     = self::STAGES[1];
		$choice->params    = ['channel' => 'SMS', 'templateId' => 'tpl-x', 'stageNr' => 7, 'note' => 'manual'];

		$params = $choice->stageParams(stageNr: 2);
		$this->assertSame(['SMS', 'tpl-x', 7, 'manual', 0.0], [$params['channel'], $params['templateId'], $params['stageNr'], $params['note'], $params['invoiceAmount']]);

		$bare            = $this->choice();
		$bare->invoiceId = 'inv-1';
		$params          = $bare->stageParams(stageNr: 1);
		$this->assertSame(['EMAIL', '', [], 1], [$params['channel'], $params['templateId'], $params['stage'], $params['stageNr']]);
	}//end testOverridesWinAndDefaultsFillIn()
}//end class
