<?php

/**
 * Unit tests for the API paths that speak a protocol defined elsewhere.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Middleware\Support;

use OCA\Shillinq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Standard protocol paths are recognised in app-relative and full request form.
 *
 * @covers \OCA\Shillinq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'app relative'           => ['/api/health', '/api/health'],
			'without leading slash'  => ['api/health', '/api/health'],
			'full request path'      => ['/index.php/apps/shillinq/api/health', '/api/health'],
			'pretty request path'    => ['/apps/shillinq/api/metrics', '/api/metrics'],
			'other app is untouched' => ['/apps/shillinqx/api/health', '/apps/shillinqx/api/health'],
			'app root without slash' => ['/apps/shillinq', '/apps/shillinq'],
		];
	}//end relativePaths()

	/**
	 * A request path is cut to the part after the app root.
	 *
	 * @param string $path     The path.
	 * @param string $relative The app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testTheAppRelativePath(string $path, string $relative): void {
		$this->assertSame(expected: $relative, actual: StandardProtocolPaths::appRelative(path: $path));
	}//end testTheAppRelativePath()

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'health'                       => ['/api/health', true],
			'metrics in a request path'    => ['/index.php/apps/shillinq/api/metrics', true],
			'provider webhook'             => ['/api/webhooks/mollie', true],
			'payment request webhook'      => ['/api/v1/payment-requests/webhook/stripe', true],
			'health subpath'               => ['/api/health/details', false],
			'own api path'                 => ['/api/invoices', false],
			'payment requests themselves'  => ['/api/v1/payment-requests', false],
			'webhook outside the api root' => ['/webhooks/mollie', false],
		];
	}//end protocolPaths()

	/**
	 * Only the declared protocol paths match.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testOnlyDeclaredProtocolPathsMatch(string $path, bool $expected): void {
		$this->assertSame(expected: $expected, actual: StandardProtocolPaths::matches(path: $path));
	}//end testOnlyDeclaredProtocolPathsMatch()
}//end class
