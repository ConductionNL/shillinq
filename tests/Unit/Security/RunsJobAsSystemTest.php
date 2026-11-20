<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A job's work runs inside ObjectService::runAsSystem(), and still runs without OpenRegister.
 */
final class RunsJobAsSystemTest extends TestCase {

	private function job(ContainerInterface $container): object {
		return new class($container) {
			use RunsJobAsSystem;

			public function __construct(private ContainerInterface $container) {
			}

			public function go(callable $work): void {
				$this->asSystem(work: $work);
			}
		};
	}

	public function testWorkRunsInsideTheSystemContext(): void {
		$objectService = new class {
			public bool $inside = false;
			public function runAsSystem(callable $work): void {
				$this->inside = true;
				$work();
				$this->inside = false;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$seen = null;
		$this->job($container)->go(function () use ($objectService, &$seen): void {
			$seen = $objectService->inside;
		});
		self::assertTrue($seen);
	}

	public function testWorkStillRunsWithoutOpenRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));

		$ran = false;
		$this->job($container)->go(function () use (&$ran): void {
			$ran = true;
		});
		self::assertTrue($ran);
	}
}
