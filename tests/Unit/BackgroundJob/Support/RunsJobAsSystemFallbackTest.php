<?php

/**
 * Unit tests for the RunsJobAsSystem trait's service lookup and fallbacks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob\Support;

use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Which service the trait asks for, and how often the work runs on each path.
 */
final class RunsJobAsSystemFallbackTest extends TestCase {

	/**
	 * A job using the trait on the given container.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return object
	 */
	private function job(ContainerInterface $container): object {
		return new class($container) {
			use RunsJobAsSystem;

			/**
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private ContainerInterface $container) {
			}

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function go(callable $work): void {
				$this->asSystem(work: $work);
			}
		};
	}//end job()

	/**
	 * The trait asks the container for OpenRegister's ObjectService by its class name,
	 * and the work runs once inside runAsSystem.
	 *
	 * @return void
	 */
	public function testAsksForTheObjectServiceAndRunsTheWorkOnce(): void {
		$objectService = new class {
			public int $systemRuns = 0;

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->systemRuns++;
				$work();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$runs = 0;
		$this->job($container)->go(function () use (&$runs): void {
			$runs++;
		});

		$this->assertSame(1, $runs);
		$this->assertSame(1, $objectService->systemRuns);
	}//end testAsksForTheObjectServiceAndRunsTheWorkOnce()

	/**
	 * An ObjectService without runAsSystem (an older OpenRegister) runs the work plainly, once.
	 *
	 * @return void
	 */
	public function testAServiceWithoutRunAsSystemRunsTheWorkPlainly(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		$runs = 0;
		$this->job($container)->go(function () use (&$runs): void {
			$runs++;
		});

		$this->assertSame(1, $runs);
	}//end testAServiceWithoutRunAsSystemRunsTheWorkPlainly()

	/**
	 * An Error from the container (not only an Exception) still lets the work run, once.
	 *
	 * @return void
	 */
	public function testAnErrorFromTheContainerStillRunsTheWork(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \Error('class not found'));

		$runs = 0;
		$this->job($container)->go(function () use (&$runs): void {
			$runs++;
		});

		$this->assertSame(1, $runs);
	}//end testAnErrorFromTheContainerStillRunsTheWork()

	/**
	 * An exception from the work inside the system context reaches the job.
	 *
	 * @return void
	 */
	public function testAnExceptionFromTheWorkPropagates(): void {
		$objectService = new class {
			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$work();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('mandate write failed');

		$this->job($container)->go(function (): void {
			throw new \RuntimeException('mandate write failed');
		});
	}//end testAnExceptionFromTheWorkPropagates()
}//end class
