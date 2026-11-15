<?php

/**
 * Unit tests for WbsoDocumentGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WbsoDocumentGuard;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A draft document with a file reference may be filed; a filed document may
 * be archived only once its seven-year retention has run out.
 *
 * @covers \OCA\Shillinq\Lifecycle\WbsoDocumentGuard
 * @uses   \OCA\Shillinq\Service\WbsoDocumentService
 */
final class WbsoDocumentGuardTest extends TestCase {

	/**
	 * The debug lines the guard logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The guard over a real retention calculation.
	 *
	 * @return WbsoDocumentGuard
	 */
	private function guard(): WbsoDocumentGuard {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);

		$documents = new WbsoDocumentService(
			appConfig: $this->createMock(IAppConfig::class),
			userSession: $this->createMock(IUserSession::class),
			objectService: new DuckObjectServiceAdapter(new \stdClass()),
		);

		return new WbsoDocumentGuard(documents: $documents, logger: $logger);

	}//end guard()

	/**
	 * Filing needs a draft with a file reference.
	 *
	 * @return void
	 */
	public function testCanFileNeedsADraftWithAReference(): void {
		$guard = $this->guard();

		$this->assertTrue($guard->canFile(['status' => 'draft', 'fileReference' => 'WBSO/2026/1']));
		$this->assertSame([], $this->logged);

		$this->assertFalse($guard->canFile(['status' => 'filed', 'fileReference' => 'WBSO/2026/1']));
		$this->assertFalse($guard->canFile(['fileReference' => 'WBSO/2026/1']));
		$this->assertFalse($guard->canFile(['status' => 'draft', 'fileReference' => '  ']));
		$this->assertFalse($guard->canFile(['status' => 'draft']));
		$this->assertSame(
			[
				'WbsoDocumentGuard: canFile rejected — not in draft',
				'WbsoDocumentGuard: canFile rejected — not in draft',
				'WbsoDocumentGuard: canFile rejected — missing fileReference',
				'WbsoDocumentGuard: canFile rejected — missing fileReference',
			],
			$this->logged
		);

	}//end testCanFileNeedsADraftWithAReference()

	/**
	 * Archiving needs a filed document whose retention has elapsed.
	 *
	 * @return void
	 */
	public function testCanArchiveNeedsElapsedRetention(): void {
		$guard = $this->guard();

		$this->assertTrue($guard->canArchive(['status' => 'filed', 'filedAt' => '2000-01-01']));
		$this->assertTrue($guard->canArchive(['status' => 'filed', 'documentDate' => '2001-06-30']));
		$this->assertFalse($guard->canArchive(['status' => 'filed', 'filedAt' => '2999-01-01', 'documentDate' => '2000-01-01']));
		$this->assertFalse($guard->canArchive(['status' => 'filed']));
		$this->assertFalse($guard->canArchive(['status' => 'filed', 'filedAt' => 'not a date']));
		$this->assertSame([], $this->logged);

		$this->assertFalse($guard->canArchive(['status' => 'draft', 'filedAt' => '2000-01-01']));
		$this->assertSame(['WbsoDocumentGuard: canArchive rejected — not in filed'], $this->logged);

	}//end testCanArchiveNeedsElapsedRetention()
}//end class
