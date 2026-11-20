<?php

/**
 * Unit tests for StatementManifestService.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-financial-statements/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\StatementManifestService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the idempotent statement-manifest import (REQ-FS-002).
 */
class StatementManifestServiceTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The service under test.
	 *
	 * @var StatementManifestService
	 */
	private StatementManifestService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new StatementManifestService(
			appConfig: $this->appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Import() writes all three manifests when no config exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-financial-statements/spec.md (REQ-FS-002)
	 */
	public function testImportImportsAllWhenAbsent(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->expects($this->exactly(count: 3))->method('setValueString');

		$result = $this->service->import();

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 3, actual: $result['imported']);
		self::assertSame(expected: 0, actual: $result['skipped']);

	}//end testImportImportsAllWhenAbsent()

	/**
	 * Import() preserves operator edits — skips keys that already exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-financial-statements/spec.md (REQ-FS-002)
	 */
	public function testImportSkipsExistingWithoutForce(): void {
		$this->appConfig->method('getValueString')->willReturn('{"_meta":{},"sections":[]}');
		$this->appConfig->expects($this->never())->method('setValueString');

		$result = $this->service->import();

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 0, actual: $result['imported']);
		self::assertSame(expected: 3, actual: $result['skipped']);

	}//end testImportSkipsExistingWithoutForce()

	// testImportForcedReimports was removed with
	// StatementManifestService::importForced(). No caller existed for it —
	// Repair\InitializeSettings, the only consumer, calls import(). The
	// preserve-operator-edits behaviour it contrasted with is still asserted
	// by testImportSkipsExistingWithoutForce above.
	/**
	 * Golden: a mixed import reads every key once, writes only the absent ones
	 * as JSON, logs the counts and answers them.
	 *
	 * @return void
	 */
	public function testMixedImportReadsEveryKeyAndLogsTheCounts(): void {
		$read = [];
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key) use (&$read): string {
				$read[] = $key;
				return (count($read) === 2) ? '{"kept":true}' : '';
			}
		);
		$written = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$written): bool {
				$written[] = [$app, $key, is_array(json_decode($value, true))];
				return true;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Shillinq: statement manifests imported', ['imported' => 2, 'skipped' => 1]);
		$service = new StatementManifestService(appConfig: $this->appConfig, logger: $logger);

		self::assertSame(['success' => true, 'imported' => 2, 'skipped' => 1], $service->import());
		self::assertCount(3, $read);
		self::assertSame([$read[0], $read[2]], array_column($written, 1));
		self::assertSame([true, true], array_column($written, 2));

	}//end testMixedImportReadsEveryKeyAndLogsTheCounts()

}//end class
