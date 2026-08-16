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
}//end class
