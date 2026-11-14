<?php

/**
 * Unit tests for ComputeLawfulnessParagraphAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use DomainException;
use OCA\Shillinq\Kernel\Contracts\LawfulnessParagraphInterface;
use OCA\Shillinq\Lifecycle\Action\ComputeLawfulnessParagraphAction;
use PHPUnit\Framework\TestCase;

/**
 * The compute transition keeps the totals on the paragraph.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\ComputeLawfulnessParagraphAction
 */
final class ComputeLawfulnessParagraphActionTest extends TestCase {

	/**
	 * The paragraph's administration and year are computed, and the totals
	 * replace the stale ones while every other field is kept.
	 *
	 * @return void
	 */
	public function testTheTotalsLandOnTheParagraph(): void {
		$paragraphs = $this->createMock(LawfulnessParagraphInterface::class);
		$paragraphs->expects($this->once())->method('compute')->with('gem', 2026)
			->willReturn(['within_tolerance' => false, 'total_identified_errors' => 2500000.0]);

		$result = (new ComputeLawfulnessParagraphAction(paragraphs: $paragraphs))->execute(
			['id' => 'p1', 'administrationId' => 'gem', 'financialYear' => '2026', 'status' => 'draft', 'within_tolerance' => true],
			[],
			[],
			'compute'
		);

		$this->assertSame(
			['id' => 'p1', 'administrationId' => 'gem', 'financialYear' => '2026', 'status' => 'draft', 'within_tolerance' => false, 'total_identified_errors' => 2500000.0],
			$result
		);
	}//end testTheTotalsLandOnTheParagraph()

	/**
	 * A refusal of the computation stops the transition.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheTransition(): void {
		$paragraphs = $this->createMock(LawfulnessParagraphInterface::class);
		$paragraphs->method('compute')->willThrowException(new DomainException('Record the tolerance for 2026 first.'));

		$this->expectException(DomainException::class);
		(new ComputeLawfulnessParagraphAction(paragraphs: $paragraphs))->execute(['administrationId' => 'gem', 'financialYear' => 2026], [], [], 'compute');
	}//end testARefusalStopsTheTransition()
}//end class
