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
		// The only container lookup is the system-context wrapper's ObjectService.
		$this->container->expects(self::atMost(1))->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService');

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

	/**
	 * The query, the fallback fields, the archive arguments, the skip order
	 * and the exact log lines.
	 *
	 * @return void
	 */
	public function testQueryFallbacksArgumentsAndLogLines(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('books');
		$calls = [];
		$this->documents->method('isRetentionElapsed')->willReturnCallback(
			static function (string $filedAt) use (&$calls): bool {
				$calls[] = 'elapsed:' . $filedAt;
				return $filedAt !== '2026-01-01';
			}
		);
		$this->documents->method('archiveDocument')->willReturnCallback(
			static function (string $administrationId, string $documentId, string $reason, bool $allowEarly) use (&$calls): array {
				$calls[] = 'archive:' . json_encode([$administrationId, $documentId, $reason, $allowEarly]);
				if ($documentId === 'DOC-9') {
					throw new \RuntimeException('locked');
				}

				return [];
			}
		);
		$objectService = new class($calls) {
			/**
			 * @param array<int,string> $calls The call log.
			 */
			public function __construct(
				private array &$calls,
			) {
			}

			public function setRegister(string $r): self {
				$this->calls[] = 'register:' . $r;
				return $this;
			}

			public function setSchema(string $s): self {
				return $this;
			}

			public function findAll(array $opts): array {
				$this->calls[] = 'findAll:' . json_encode($opts);
				return [
					['documentNumber' => 'DOC-1', 'administrationId' => 'adm-1', 'documentDate' => '2015-01-01'],
					['id' => 'doc-2', 'administrationId' => 'adm-1', 'filedAt' => '2026-01-01', 'documentDate' => '2015-01-01'],
					['id' => '', 'administrationId' => 'adm-1'],
					['id' => 'doc-3'],
					['id' => 'DOC-9', 'administrationId' => 'adm-2', 'filedAt' => '2010-01-01'],
				];
			}
		};
		$this->container->method('get')->willReturn($objectService);
		$logged = [];
		foreach (['info', 'warning'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logged, $level): void {
					$logged[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		$this->invokeRun($this->makeJob());

		$reason = 'Automatic archival after 7-year Archiefwet retention boundary.';
		self::assertSame(
			[
				'register:books',
				'findAll:{"filters":{"status":"filed"},"limit":5000}',
				'elapsed:2015-01-01',
				'archive:' . json_encode(['adm-1', 'DOC-1', $reason, false]),
				'elapsed:2026-01-01',
				'elapsed:2010-01-01',
				'archive:' . json_encode(['adm-2', 'DOC-9', $reason, false]),
			],
			array_values(array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'register:') === false || $c === 'register:books'))
		);
		self::assertSame(
			[
				'warning:DocumentArchiveCron: failed to archive document DOC-9{"administrationId":"adm-2","exception":"locked"}',
				'info:DocumentArchiveCron: archived=1 skipped=3 failed=1 (sampled 5 filed documents).[]',
			],
			$logged
		);
	}//end testQueryFallbacksArgumentsAndLogLines()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
