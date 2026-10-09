<?php

/**
 * Depreciation Run Job
 *
 * Daily (ADR-069): posts last month's depreciation, keeps the assets' book
 * values in line with their schedules and releases expired reinvestment
 * reserves (assets-method-change-and-reserve, REQ-AMCR-001, REQ-AMCR-005).
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\Service\Asset\DepreciationRun;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the depreciation pass once a day.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class DepreciationRunJob extends TimedJob {

	/**
	 * Once a day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time   The clock.
	 * @param DepreciationRun $pass   The pass.
	 * @param LoggerInterface $logger The log.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DepreciationRun $pass,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run one pass.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 */
	protected function run($argument): void {
		try {
			$counts = $this->pass->run(today: new DateTimeImmutable('@' . $this->time->getTime()));
			$this->logger->info('DepreciationRunJob: pass done', $counts);
		} catch (Throwable $e) {
			$this->logger->error('DepreciationRunJob: pass failed', ['exception' => $e->getMessage()]);
		}

	}//end run()
}//end class
