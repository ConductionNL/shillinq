<?php

/**
 * Regression guard: every info.xml `<background-jobs><job>` FQCN must resolve.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/background-job-consolidation/specs/background-job-consolidation/spec.md#req-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `appinfo/info.xml`'s `<background-jobs>` block is a bare list of FQCN
 * strings — Nextcloud's job runner resolves each one via the container at
 * cron time. Nothing at build time checks that the string still points at a
 * real, autoloadable class: a rename, a namespace move, or a typo in this
 * file is invisible until the job silently never runs (no exception, no log
 * line — the job simply never executes, because the runner iterates
 * `OC\BackgroundJob\JobList` rows built from this file and a class that does
 * not exist just can't be instantiated).
 *
 * This is exactly the defect class ADR-069 (background-job-conventions)
 * documents: shillinq shipped THREE job directories at once
 * (`lib/BackgroundJob/`, `lib/Cron/`, `lib/Job/`) and a prior sweep found
 * `info.xml` naming the pre-move namespace for a job whose file had already
 * moved. `background-job-consolidation` folds `lib/Cron/` and `lib/Job/`
 * into the single canonical `lib/BackgroundJob/` directory ADR-069 D1
 * requires; this test is the mechanical guard that would have caught the
 * whole category — a stale FQCN in info.xml fails LOUDLY here instead of
 * silently at cron time.
 *
 * Deliberately a SOURCE scan of info.xml (not a hardcoded list mirrored by
 * hand): a future job addition/removal is covered automatically, and the
 * test cannot drift from what Nextcloud will actually try to resolve.
 */
final class BackgroundJobRegistrationContractTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Set up the repository root path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = dirname(__DIR__, 3);

	}//end setUp()

	/**
	 * Every FQCN declared in `<background-jobs><job>` must be a real,
	 * autoloadable class — this is the mechanical guard against a stale
	 * registration silently never running.
	 *
	 * @return void
	 */
	public function testEveryDeclaredBackgroundJobFqcnResolvesToARealClass(): void {
		$jobs = $this->declaredBackgroundJobs();

		// Positive control: an empty violations list only means something if
		// jobs were actually found — this app declares 17 as of this change,
		// so a floor well below that catches the parser silently matching
		// nothing (e.g. a future info.xml reformat this regex doesn't expect).
		$this->assertGreaterThanOrEqual(
			10,
			count($jobs),
			'Sanity floor: appinfo/info.xml\'s <background-jobs> block yielded '
			. 'implausibly few <job> entries, so this test\'s "no missing '
			. 'classes" result would mean the parser did not run, not that '
			. 'the registrations are sound.'
		);

		$missing = [];
		foreach ($jobs as $fqcn) {
			if (class_exists($fqcn) === false) {
				$missing[] = $fqcn;
			}
		}

		$this->assertSame(
			[],
			$missing,
			"The following FQCN(s) are registered in appinfo/info.xml's \n"
			. "<background-jobs> block but do not autoload to a real class.\n"
			. "Nextcloud's job runner cannot instantiate them, so each job\n"
			. "silently never runs — no exception, no log line, just a\n"
			. "cron slot that does nothing forever:\n\n  "
			. implode("\n  ", $missing) . "\n"
		);

	}//end testEveryDeclaredBackgroundJobFqcnResolvesToARealClass()

	/**
	 * Every declared job must extend `TimedJob` or `QueuedJob` — ADR-069 D3:
	 * a raw `\OCP\BackgroundJob\Job` subclass skips interval throttling,
	 * which is the fleet's poison-job defence.
	 *
	 * @return void
	 */
	public function testEveryDeclaredBackgroundJobExtendsTimedOrQueuedJob(): void {
		$jobs = $this->declaredBackgroundJobs();
		$this->assertNotEmpty($jobs, 'No jobs found — see the other test in this class.');

		$violations = [];
		foreach ($jobs as $fqcn) {
			if (class_exists($fqcn) === false) {
				// Covered (and failed) by testEveryDeclaredBackgroundJobFqcnResolvesToARealClass();
				// do not double-report an unresolvable class here.
				continue;
			}

			$reflection = new ReflectionClass($fqcn);
			if ($reflection->isSubclassOf(TimedJob::class) === false
				&& $reflection->isSubclassOf(QueuedJob::class) === false
			) {
				$violations[] = $fqcn . ' extends ' . ($reflection->getParentClass() !== false
					? $reflection->getParentClass()->getName()
					: '(no parent)');
			}
		}

		$this->assertSame(
			[],
			$violations,
			"ADR-069 D3: every scheduled job must extend TimedJob or QueuedJob.\n"
			. "A raw Job subclass skips interval throttling — the fleet's\n"
			. "poison-job defence. Violations:\n\n  " . implode("\n  ", $violations) . "\n"
		);

	}//end testEveryDeclaredBackgroundJobExtendsTimedOrQueuedJob()

	/**
	 * ADR-069 D1: `lib/Cron/` and `lib/Job/` are deprecated — no declared job
	 * may live in either namespace. Locks in the outcome of
	 * background-job-consolidation so a future addition cannot reintroduce
	 * the third/fourth directory.
	 *
	 * @return void
	 */
	public function testNoDeclaredBackgroundJobUsesTheDeprecatedCronOrJobNamespace(): void {
		$jobs = $this->declaredBackgroundJobs();
		$this->assertNotEmpty($jobs, 'No jobs found — see the other test in this class.');

		$violations = array_values(array_filter(
			$jobs,
			static fn (string $fqcn): bool => str_starts_with($fqcn, 'OCA\\Shillinq\\Cron\\')
				|| str_starts_with($fqcn, 'OCA\\Shillinq\\Job\\')
		));

		$this->assertSame(
			[],
			$violations,
			"ADR-069 D1: lib/Cron/ and lib/Job/ are deprecated — the single\n"
			. "canonical job directory is lib/BackgroundJob/. The following\n"
			. "declared job(s) still name the deprecated namespace:\n\n  "
			. implode("\n  ", $violations) . "\n"
		);

	}//end testNoDeclaredBackgroundJobUsesTheDeprecatedCronOrJobNamespace()

	/**
	 * Parse every `<job>` FQCN out of appinfo/info.xml's `<background-jobs>`
	 * block.
	 *
	 * @return array<int,string> Declared FQCNs, in document order.
	 */
	private function declaredBackgroundJobs(): array {
		$infoXmlPath = $this->root . '/appinfo/info.xml';
		$this->assertFileExists($infoXmlPath, 'appinfo/info.xml must exist');

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_file($infoXmlPath);
		libxml_use_internal_errors($previous);

		$this->assertNotFalse($xml, 'appinfo/info.xml must be well-formed XML');

		$jobs = [];
		if (isset($xml->{'background-jobs'}->job)) {
			foreach ($xml->{'background-jobs'}->job as $job) {
				$jobs[] = trim((string)$job);
			}
		}

		return $jobs;

	}//end declaredBackgroundJobs()

}//end class
