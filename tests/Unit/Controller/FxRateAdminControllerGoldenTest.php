<?php

/**
 * Golden test for FxRateAdminController::status.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\BackgroundJob\FxRateImportJob;
use OCA\Shillinq\Controller\FxRateAdminController;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the status envelope for every last-run and dormancy combination, and the calls and warnings behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class FxRateAdminControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The status envelope for the given job runs and dormancy.
	 *
	 * @param array<int,int|string>|null $runs    Last-run values of the listed jobs ('x' lists a non-job); null makes the lookup throw.
	 * @param bool|null                  $dormant The adapter's answer; null makes it throw.
	 *
	 * @return mixed
	 */
	private function envelopeFor(array|null $runs, bool|null $dormant): mixed {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('getJobsIterator')->willReturnCallback(
			function (mixed $job, ?int $limit, int $offset) use ($runs): iterable {
				$this->calls[] = ['getJobsIterator', $job, $limit, $offset];
				if ($runs === null) {
					throw new RuntimeException('jobs down');
				}

				return $this->listed(runs: $runs);
			}
		);
		$adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		$adapter->method('isDormant')->willReturnCallback(
			function () use ($dormant): bool {
				$this->calls[] = ['isDormant'];
				if ($dormant === null) {
					throw new RuntimeException('adapter down');
				}

				return $dormant;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['warning', $message, $context];
			}
		);

		return (new FxRateAdminController($this->createMock(IRequest::class), $jobs, $adapter, $logger))->status()->getData();
	}//end envelopeFor()

	/**
	 * The listed jobs.
	 *
	 * @param array<int,int|string> $runs Last-run values; 'x' lists a non-job.
	 *
	 * @return array<int,object>
	 */
	private function listed(array $runs): array {
		$listed = [];
		foreach ($runs as $run) {
			if ($run === 'x') {
				$listed[] = new \stdClass();
				continue;
			}

			$job = $this->createMock(IJob::class);
			$job->method('getLastRun')->willReturnCallback(
				function () use ($run): int {
					$this->calls[] = ['getLastRun', $run];
					return $run;
				}
			);
			$listed[] = $job;
		}

		return $listed;
	}//end listed()

	/**
	 * The envelope.
	 *
	 * @param int|null $epoch   The last-run epoch.
	 * @param string   $status  The status key.
	 * @param bool     $dormant Whether the adapter is dormant.
	 *
	 * @return array<string,mixed>
	 */
	private static function envelope(int|null $epoch, string $status, bool $dormant=false): array {
		$iso = null;
		if ($epoch !== null) {
			$iso = gmdate('Y-m-d\TH:i:sP', $epoch);
		}

		return [
			'jobClass' => FxRateImportJob::class,
			'lastRunAt' => $iso,
			'lastRunEpoch' => $epoch,
			'adapterDormant' => $dormant,
			'interval' => 86400,
			'status' => $status,
		];
	}//end envelope()

	/**
	 * The latest positive last run wins; non-jobs and zero or negative runs are ignored.
	 *
	 * @return void
	 */
	public function testLastRun(): void {
		$actual = $this->envelopeFor(runs: [0, 'x', 1700000500, 1600000000, -5], dormant: false);
		self::assertSame(expected: self::envelope(epoch: 1700000500, status: 'ok'), actual: $actual);
		$expected = [
			['getJobsIterator', FxRateImportJob::class, null, 0],
			['getLastRun', 0],
			['getLastRun', 1700000500],
			['getLastRun', 1600000000],
			['getLastRun', -5],
			['isDormant'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
		self::assertSame(expected: self::envelope(epoch: null, status: 'never-ran'), actual: $this->envelopeFor(runs: [0, 'x'], dormant: false));
		self::assertSame(expected: self::envelope(epoch: null, status: 'never-ran'), actual: $this->envelopeFor(runs: [], dormant: false));
	}//end testLastRun()

	/**
	 * Dormant overrides both other states; failures are warnings with safe defaults.
	 *
	 * @return void
	 */
	public function testDormantAndFailures(): void {
		$actual = $this->envelopeFor(runs: [1700000000], dormant: true);
		self::assertSame(expected: self::envelope(epoch: 1700000000, status: 'dormant', dormant: true), actual: $actual);
		self::assertSame(expected: self::envelope(epoch: null, status: 'dormant', dormant: true), actual: $this->envelopeFor(runs: [], dormant: true));
		$this->calls = [];
		self::assertSame(expected: self::envelope(epoch: null, status: 'never-ran'), actual: $this->envelopeFor(runs: null, dormant: null));
		$expected = [
			['getJobsIterator', FxRateImportJob::class, null, 0],
			['warning', 'Shillinq FxRateAdminController: IJobList lookup threw', ['exception' => 'jobs down']],
			['isDormant'],
			['warning', 'Shillinq FxRateAdminController: TreasuryRateAdapter.isDormant() threw', ['exception' => 'adapter down']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testDormantAndFailures()
}//end class
