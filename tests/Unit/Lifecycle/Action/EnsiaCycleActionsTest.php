<?php

/**
 * Unit tests for the ENSIA cycle transition actions.
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use DomainException;
use OCA\Shillinq\Kernel\Contracts\EnsiaCycleStepsInterface;
use OCA\Shillinq\Lifecycle\Action\CreateEnsiaFindingsAction;
use OCA\Shillinq\Lifecycle\Action\ExportEnsiaSubmissionAction;
use OCA\Shillinq\Lifecycle\Action\RenderEnsiaStatementAction;
use PHPUnit\Framework\TestCase;

/**
 * The cycle's transitions hand the cycle to the step they declare, and the
 * register declares each action on its transition.
 *
 * @covers \OCA\Shillinq\Lifecycle\Action\CreateEnsiaFindingsAction
 * @covers \OCA\Shillinq\Lifecycle\Action\RenderEnsiaStatementAction
 * @covers \OCA\Shillinq\Lifecycle\Action\ExportEnsiaSubmissionAction
 */
final class EnsiaCycleActionsTest extends TestCase {

	/**
	 * The cycle after a transition.
	 *
	 * @var array<string, mixed>
	 */
	private const CYCLE = ['id' => 'c1', 'administrationId' => 'gem', 'year' => 2026, 'status' => 'peer-review'];

	/**
	 * Moving to peer review creates the findings and keeps the cycle as it is.
	 *
	 * @return void
	 */
	public function testPeerReviewCreatesTheFindings(): void {
		$steps = $this->createMock(EnsiaCycleStepsInterface::class);
		$steps->expects($this->once())->method('createFindings')->with(self::CYCLE)->willReturn(['b1', 'b2']);

		$this->assertSame(self::CYCLE, (new CreateEnsiaFindingsAction(steps: $steps))->execute(self::CYCLE, [], [], 'naarPeerReview'));
	}//end testPeerReviewCreatesTheFindings()

	/**
	 * Moving to college approval answers the cycle with its statement.
	 *
	 * @return void
	 */
	public function testCollegeApprovalStoresTheStatement(): void {
		$steps = $this->createMock(EnsiaCycleStepsInterface::class);
		$steps->expects($this->once())->method('withStatement')->with(self::CYCLE)
			->willReturn(self::CYCLE + ['declarationDocx' => 'UEs=']);

		$this->assertSame(
			self::CYCLE + ['declarationDocx' => 'UEs='],
			(new RenderEnsiaStatementAction(steps: $steps))->execute(self::CYCLE, [], [], 'naarCollegeAkkoord')
		);
	}//end testCollegeApprovalStoresTheStatement()

	/**
	 * Submitting answers the cycle with its XML, and a refusal stops the transition.
	 *
	 * @return void
	 */
	public function testSubmittingStoresTheXmlOrStops(): void {
		$steps = $this->createMock(EnsiaCycleStepsInterface::class);
		$steps->expects($this->exactly(2))->method('withSubmission')->with(self::CYCLE)->willReturnOnConsecutiveCalls(
			self::CYCLE + ['submissionXml' => '<ensia/>'],
			$this->throwException(new DomainException('Only a cycle approved by the college with its statement can be submitted.'))
		);
		$action = new ExportEnsiaSubmissionAction(steps: $steps);

		$this->assertSame(self::CYCLE + ['submissionXml' => '<ensia/>'], $action->execute(self::CYCLE, [], [], 'indienen'));
		$this->expectException(DomainException::class);
		$action->execute(self::CYCLE, [], [], 'indienen');
	}//end testSubmittingStoresTheXmlOrStops()

	/**
	 * The register runs each action on its transition, the XML after submittedAt is stamped.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheActions(): void {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/register.d/bookkeeping-ensia-zelfevaluatie.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		$transitions = $fragment['components']['schemas']['ENSIAJaarcyclus']['x-openregister-lifecycle']['transitions'];
		$actions = static fn (string $transition): array => array_column(($transitions[$transition]['actions'] ?? []), 'action');

		$this->assertSame([CreateEnsiaFindingsAction::class], $actions('naarPeerReview'));
		$this->assertSame([RenderEnsiaStatementAction::class], $actions('naarCollegeAkkoord'));
		$this->assertSame(['set-fields', ExportEnsiaSubmissionAction::class], $actions('indienen'));
	}//end testTheRegisterDeclaresTheActions()
}//end class
