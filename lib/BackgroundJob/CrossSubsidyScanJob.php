<?php

/**
 * Cross Subsidy Scan Job
 *
 * Runs the cross-subsidy checks over every active commercial activity once a
 * day and logs one alert per new signal (public-sector-task-field-allocation,
 * REQ-WMO-014). The work runs inside OpenRegister's system context: a job has
 * no user session.
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
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Kernel\Contracts\CrossSubsidyScanInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the cross-subsidy scan once a day.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 */
class CrossSubsidyScanJob extends TimedJob {
	use RunsJobAsSystem;

	/**
	 * Once a day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time      The clock.
	 * @param CrossSubsidyScanInterface $scan      The scan.
	 * @param ContainerInterface        $container For OpenRegister's system context.
	 * @param LoggerInterface           $logger    The log.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CrossSubsidyScanInterface $scan,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run one scan as the system.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
	 */
	protected function run($argument): void {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
		$this->asSystem(
			work: function () use ($today): void {
				try {
					$counts = $this->scan->run(today: $today);
					$this->logger->info('CrossSubsidyScanJob: scan done', $counts);
				} catch (Throwable $e) {
					$this->logger->error('CrossSubsidyScanJob: scan failed', ['exception' => $e->getMessage()]);
				}
			}
		);

	}//end run()
}//end class
