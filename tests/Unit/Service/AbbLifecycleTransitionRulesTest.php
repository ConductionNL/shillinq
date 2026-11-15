<?php

/**
 * Characterisation tests for AbbLifecycleService transition preconditions and tasks.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Shillinq\Service\AbbLifecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every precondition outcome and message of canTransition and every
 * task envelope of generateTasks (REQ-WMO-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AbbLifecycleTransitionRulesTest extends TestCase {

	/**
	 * Transition, record and expected envelope.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>,3:array<string,mixed>}> Cases.
	 */
	public static function transitions(): array {
		$acm = ['submitted' => true, 'reference' => 'ACM-1'];
		return [
			'unknown source'             => ['nope', 'draft', [], ['ok' => false, 'error' => 'Transition nope -> draft is not allowed']],
			'terminal source'            => ['intrekking', 'geldig', [], ['ok' => false, 'error' => 'Transition intrekking -> geldig is not allowed']],
			'plain transition'           => ['draft', 'raadsvoorstel', [], ['ok' => true]],
			'back to draft'              => ['raadsvoorstel', 'draft', [], ['ok' => true]],
			'resolution blank reference' => ['raadsvoorstel', 'councilResolution', ['reference' => '  '], ['ok' => false, 'error' => 'Transition to council resolution requires a reference']],
			'resolution with reference'  => ['raadsvoorstel', 'councilResolution', ['reference' => 'R-1'], ['ok' => true]],
			'publication no gazette'     => ['councilResolution', 'publicatie', ['publicationDate' => '2026-01-01'], ['ok' => false, 'error' => 'Transition to publicatie requires publicatieGemeenteblad']],
			'publication gazette first'  => ['councilResolution', 'publicatie', [], ['ok' => false, 'error' => 'Transition to publicatie requires publicatieGemeenteblad']],
			'publication no date'        => ['councilResolution', 'publicatie', ['publicationMunicipalGazette' => 'G-1'], ['ok' => false, 'error' => 'Transition to publicatie requires publicatieDatum']],
			'publication complete'       => ['councilResolution', 'publicatie', ['publicationMunicipalGazette' => 'G-1', 'publicationDate' => 'd'], ['ok' => true]],
			'acm not submitted'          => ['publicatie', 'acm-notified', ['notificationAcm' => ['reference' => 'A']], ['ok' => false, 'error' => 'Transition to acm-notified requires kennisgevingAcm.ingediend=true']],
			'acm without block'          => ['publicatie', 'acm-notified', [], ['ok' => false, 'error' => 'Transition to acm-notified requires kennisgevingAcm.ingediend=true']],
			'acm no reference'           => ['publicatie', 'acm-notified', ['notificationAcm' => ['submitted' => 1]], ['ok' => false, 'error' => 'Transition to acm-notified requires kennisgevingAcm.kenmerk']],
			'acm complete'               => ['publicatie', 'acm-notified', ['notificationAcm' => $acm], ['ok' => true]],
			'objection'                  => ['acm-notified', 'bezwaar', [], ['ok' => true]],
			'valid no date'              => ['bezwaar', 'geldig', ['notificationAcm' => $acm], ['ok' => false, 'error' => 'Geldig requires publicatieDatum']],
			'valid acm not submitted'    => ['bezwaar', 'geldig', ['publicationDate' => 'd', 'notificationAcm' => ['reference' => 'A']], ['ok' => false, 'error' => 'Geldig requires ACM kenmerk']],
			'valid acm no reference'     => ['bezwaar', 'geldig', ['publicationDate' => 'd', 'notificationAcm' => ['submitted' => true]], ['ok' => false, 'error' => 'Geldig requires ACM kenmerk']],
			'valid complete'             => ['bezwaar', 'geldig', ['publicationDate' => 'd', 'notificationAcm' => $acm], ['ok' => true]],
			'evaluation back to valid'   => ['evaluatie-due', 'geldig', ['publicationDate' => 'd', 'notificationAcm' => $acm], ['ok' => true]],
			'withdrawal'                 => ['geldig', 'intrekking', [], ['ok' => true]],
			'revision'                   => ['evaluatie-due', 'herziening', [], ['ok' => true]],
		];

	}//end transitions()

	/**
	 * Each transition yields its pinned envelope.
	 *
	 * @param string              $from     The current status.
	 * @param string              $to       The desired status.
	 * @param array<string,mixed> $abb      The ABB record.
	 * @param array<string,mixed> $expected The expected envelope.
	 *
	 * @return void
	 */
	#[DataProvider('transitions')]
	public function testCanTransition(string $from, string $to, array $abb, array $expected): void {
		self::assertSame($expected, (new AbbLifecycleService())->canTransition($from, $to, $abb));

	}//end testCanTransition()

	/**
	 * Dated task envelopes per status.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string}> Cases.
	 */
	public static function datedTasks(): array {
		return [
			'resolution'  => ['councilResolution', 'publish-gemeenteblad', 'Publish in gemeenteblad: R-9', 'P14D', 'griffier'],
			'publication' => ['publicatie', 'notify-acm', 'Notify ACM: R-9', 'P7D', 'juridisch-beleidsadviseur'],
			'acm'         => ['acm-notified', 'review-bezwaarschriften', 'Review bezwaarschriften (6 weeks): R-9', 'P42D', 'juridisch-beleidsadviseur'],
		];

	}//end datedTasks()

	/**
	 * A dated task is due the given interval from now.
	 *
	 * @param string $status   The new status.
	 * @param string $type     The task type.
	 * @param string $subject  The task subject.
	 * @param string $interval The due interval.
	 * @param string $assignee The assignee.
	 *
	 * @return void
	 */
	#[DataProvider('datedTasks')]
	public function testDatedTask(string $status, string $type, string $subject, string $interval, string $assignee): void {
		$before = (new DateTimeImmutable('now'))->add(new DateInterval($interval))->format('Y-m-d');
		$tasks = (new AbbLifecycleService())->generateTasks(['reference' => 'R-9', '_id' => 'u-1'], $status);
		$after = (new DateTimeImmutable('now'))->add(new DateInterval($interval))->format('Y-m-d');

		self::assertCount(1, $tasks);
		self::assertContains($tasks[0]['dueDate'], [$before, $after]);
		$tasks[0]['dueDate'] = 'due';
		self::assertSame(
			['type' => $type, 'subject' => $subject, 'dueDate' => 'due', 'assignedTo' => $assignee, 'abbId' => 'u-1'],
			$tasks[0]
		);

	}//end testDatedTask()

	/**
	 * The evaluation task is due on nextEvaluation, defaulting to today.
	 *
	 * @return void
	 */
	public function testEvaluationTask(): void {
		$svc = new AbbLifecycleService();
		$tasks = $svc->generateTasks(['id' => 'a-1', '_id' => 'u-1', 'nextEvaluation' => '2028-03-01'], 'evaluatie-due');
		self::assertSame(
			[['type' => 'evaluate-abb', 'subject' => 'Evaluate ABB: ABB', 'dueDate' => '2028-03-01', 'assignedTo' => 'juridisch-beleidsadviseur', 'abbId' => 'a-1']],
			$tasks
		);

		$before = (new DateTimeImmutable('now'))->format('Y-m-d');
		$undated = $svc->generateTasks([], 'evaluatie-due');
		$after = (new DateTimeImmutable('now'))->format('Y-m-d');
		self::assertContains($undated[0]['dueDate'], [$before, $after]);
		self::assertSame('', $undated[0]['abbId']);

	}//end testEvaluationTask()

	/**
	 * Statuses without automatic tasks.
	 *
	 * @return void
	 */
	public function testStatusesWithoutTasks(): void {
		$svc = new AbbLifecycleService();
		foreach (['draft', 'raadsvoorstel', 'bezwaar', 'geldig', 'herziening', 'intrekking', 'unknown'] as $status) {
			self::assertSame([], $svc->generateTasks(['reference' => 'R'], $status), $status);
		}

	}//end testStatusesWithoutTasks()

	/**
	 * Dependent activities: only for herziening / intrekking, junk skipped,
	 * id falls back to _id, then empty.
	 *
	 * @return void
	 */
	public function testFlagDependentActivities(): void {
		$svc = new AbbLifecycleService();
		$activities = [['id' => 'a'], 'junk', ['_id' => 'b'], []];

		self::assertSame(
			[
				['commercialActivityId' => 'a', 'reason' => 'Exemption ABB R-1 ingetrokken; review activity'],
				['commercialActivityId' => 'b', 'reason' => 'Exemption ABB R-1 ingetrokken; review activity'],
				['commercialActivityId' => '', 'reason' => 'Exemption ABB R-1 ingetrokken; review activity'],
			],
			$svc->flagDependentActivities($activities, ['status' => 'intrekking', 'reference' => 'R-1'])
		);
		self::assertSame(
			[['commercialActivityId' => 'a', 'reason' => 'Exemption ABB ABB in herziening; review activity']],
			$svc->flagDependentActivities([['id' => 'a']], ['status' => 'herziening'])
		);
		self::assertSame([], $svc->flagDependentActivities($activities, ['status' => 'geldig']));
		self::assertSame([], $svc->flagDependentActivities($activities, []));

	}//end testFlagDependentActivities()

	/**
	 * Entering geldig sets nextEvaluation only with a determination date.
	 *
	 * @return void
	 */
	public function testTransitionToValidSetsNextEvaluationOnlyWithDate(): void {
		$svc = new AbbLifecycleService();
		$abb = ['status' => 'bezwaar', 'publicationDate' => 'd', 'notificationAcm' => ['submitted' => true, 'reference' => 'A']];

		$without = $svc->transition($abb, 'geldig');
		self::assertArrayNotHasKey('nextEvaluation', $without['abb']);
		self::assertSame([], $without['tasks']);

		$with = $svc->transition($abb + ['determinationDate' => '2026-01-15', 'evaluationCadence' => 'driejaarlijks'], 'geldig');
		self::assertSame('2029-01-15', $with['abb']['nextEvaluation']);
		self::assertSame('geldig', $with['abb']['status']);

	}//end testTransitionToValidSetsNextEvaluationOnlyWithDate()
}//end class
