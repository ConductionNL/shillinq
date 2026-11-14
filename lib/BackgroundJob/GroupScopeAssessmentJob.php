<?php

/**
 * Shillinq Group Scope Assessment Job
 *
 * Weekly: assesses every ultimate parent whose current fiscal year has no
 * scope assessment yet and whose group has a consolidated income statement
 * for the year before, and notifies the parent's responsible owner when
 * the country-by-country or minimum tax outcome differs from the year
 * before (design D1, REQ-CBC-001).
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\Cbcr\GroupScopeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The weekly scope assessment.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 */
class GroupScopeAssessmentJob extends TimedJob {
	/**
	 * The notification subject for a changed scope outcome.
	 *
	 * @var string
	 */
	public const SUBJECT = 'cbcr.scope_changed';

	/**
	 * Interval between runs: one week.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = (7 * 24 * 60 * 60);

	/**
	 * Construct the job and set its weekly interval.
	 *
	 * @param ITimeFactory         $time          Time factory for the TimedJob base and the current year.
	 * @param GroupScopeService    $scope         The scope assessment.
	 * @param INotificationManager $notifications Notification manager.
	 * @param LoggerInterface      $logger        Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly GroupScopeService $scope,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Assess the due ultimate parents for the current calendar year.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
	 */
	protected function run($argument): void {
		$now  = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$year = (int)$now->format('Y');

		try {
			$due = $this->scope->dueUltimateParents(fiscalYear: $year);
		} catch (Throwable $e) {
			$this->logger->warning('GroupScopeAssessmentJob: listing the groups to assess failed', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($due as $upe) {
			$this->assessOne(upe: $upe, year: $year, now: $now);
		}
	}//end run()

	/**
	 * Assess one ultimate parent and notify its owner on a changed outcome.
	 *
	 * @param array<string,mixed> $upe  The ultimate parent.
	 * @param int                 $year The fiscal year.
	 * @param DateTimeImmutable   $now  The moment of assessment.
	 *
	 * @return void
	 */
	private function assessOne(array $upe, int $year, DateTimeImmutable $now): void {
		$upeId = (string)($upe['id'] ?? ($upe['@self']['id'] ?? ''));
		try {
			$assessment = $this->scope->assess(upeId: $upeId, fiscalYear: $year, entered: [], actor: 'system', now: $now);
		} catch (Throwable $e) {
			$this->logger->warning('GroupScopeAssessmentJob: assessing a group failed', ['upeId' => $upeId, 'exception' => $e->getMessage()]);
			return;
		}

		$previous = $assessment['previous'] ?? null;
		if (is_array($previous) === false || self::sameOutcome(current: $assessment, previous: $previous) === true) {
			return;
		}

		$owner = trim((string)($upe['responsibleOwner'] ?? ''));
		if ($owner === '') {
			$this->logger->info('GroupScopeAssessmentJob: the scope changed but the group has no responsible owner to tell', ['upeId' => $upeId]);
			return;
		}

		$this->notify(owner: $owner, upeId: $upeId, upe: $upe, year: $year, assessment: $assessment);
	}//end assessOne()

	/**
	 * Whether both outcomes are the same in both scopes.
	 *
	 * @param array<string,mixed> $current  This year's assessment.
	 * @param array<string,mixed> $previous Last year's assessment.
	 *
	 * @return bool
	 */
	private static function sameOutcome(array $current, array $previous): bool {
		return ((bool)($current['cbcrInScope'] ?? false) === (bool)($previous['cbcrInScope'] ?? false)
			&& (bool)($current['pillar2InScope'] ?? false) === (bool)($previous['pillar2InScope'] ?? false));
	}//end sameOutcome()

	/**
	 * Tell the owner the outcome changed.
	 *
	 * @param string              $owner      The owner's user id.
	 * @param string              $upeId      The ultimate parent's id.
	 * @param array<string,mixed> $upe        The ultimate parent.
	 * @param int                 $year       The fiscal year.
	 * @param array<string,mixed> $assessment The assessment.
	 *
	 * @return void
	 */
	private function notify(string $owner, string $upeId, array $upe, int $year, array $assessment): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($owner)
				->setDateTime(new DateTime('@' . $this->time->getTime()))
				->setObject('group_scope_assessment', $upeId . ':' . $year)
				->setSubject(
					self::SUBJECT,
					[
						'group' => (string)($upe['entityName'] ?? $upeId),
						'year' => $year,
						'cbcrInScope' => (bool)($assessment['cbcrInScope'] ?? false),
						'pillar2InScope' => (bool)($assessment['pillar2InScope'] ?? false),
					]
				);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('GroupScopeAssessmentJob: sending the scope notification failed', ['upeId' => $upeId, 'exception' => $e->getMessage()]);
		}
	}//end notify()
}//end class
