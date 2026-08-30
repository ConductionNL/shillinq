<?php

/**
 * Unit tests for DocumentArchiveCron.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\DocumentArchiveCron;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the nightly archival sweep for filed bookkeeping documents (REQ-WBSO-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocumentArchiveCronTest extends TestCase {
	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock document service.
	 *
	 * @var WbsoDocumentService&MockObject
	 */
	private WbsoDocumentService&MockObject $documents;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->documents = $this->createMock(WbsoDocumentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a job under test with the current mock set.
	 *
	 * @return DocumentArchiveCron
	 */
	private function makeJob(): DocumentArchiveCron {
		return new DocumentArchiveCron(
			$this->createMock(ITimeFactory::class),
			$this->settings,
			$this->documents,
			$this->container,
			$this->logger,
		);
	}//end makeJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param DocumentArchiveCron $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(DocumentArchiveCron $job): void {
		$m = new ReflectionMethod(DocumentArchiveCron::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}//end invokeRun()

	/**
	 * run() skips entirely when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * A record missing an id or administrationId is skipped before the
	 * retention check.
	 *
	 * @return void
	 */
	public function testRunSkipsRecordMissingIdentifiers(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->documents->expects(self::never())->method('isRetentionElapsed');

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [['administrationId' => 'adm-1']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
		$this->addToAssertionCount(1);
	}//end testRunSkipsRecordMissingIdentifiers()

	/**
	 * A document that has not crossed the retention boundary is skipped —
	 * archiveDocument is never called.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenRetentionNotElapsed(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->documents->method('isRetentionElapsed')->willReturn(false);
		$this->documents->expects(self::never())->method('archiveDocument');

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [['id' => 'doc-1', 'administrationId' => 'adm-1', 'filedAt' => '2026-01-01']];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->invokeRun($this->makeJob());
	}//end testRunSkipsWhenRetentionNotElapsed()

	/**
	 * A document past the retention boundary is archived under the system
	 * reason, and a failed archival is counted without aborting the sweep.
	 *
	 * @return void
	 */
	public function testRunArchivesElapsedAndCountsFailure(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->documents->method('isRetentionElapsed')->willReturn(true);
		$this->documents->method('archiveDocument')
			->willReturnCallback(function (string $administrationId, string $documentId): array {
				if ($documentId === 'doc-fail') {
					throw new \RuntimeException('workflow refused');
				}
				return ['id' => $documentId, 'status' => 'archived'];
			});

		$objectService = new class {
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $opts): array {
				return [
					['id' => 'doc-ok', 'administrationId' => 'adm-1', 'filedAt' => '2015-01-01'],
					['id' => 'doc-fail', 'administrationId' => 'adm-1', 'filedAt' => '2015-01-01'],
				];
			}
		};

		$this->container->method('get')->willReturn($objectService);

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('archived=1 skipped=0 failed=1'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('doc-fail'), self::anything());

		$this->invokeRun($this->makeJob());
	}//end testRunArchivesElapsedAndCountsFailure()

	/**
	 * A container resolution failure is caught and logged rather than
	 * crashing the scheduler.
	 *
	 * @return void
	 */
	public function testRunCatchesContainerException(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('DocumentArchiveCron failed'));

		$this->invokeRun($this->makeJob());
	}//end testRunCatchesContainerException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
