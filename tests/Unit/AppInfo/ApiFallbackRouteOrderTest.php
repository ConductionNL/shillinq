<?php

/**
 * Unit tests for the `/api/{path}` fallback route's POSITION.
 *
 * The fallback only works because of where it sits: after every genuine API
 * route this app declares, and before the SPA catch-all. That ordering is the
 * entire mechanism, and nothing about it is visible in a diff that merely
 * moves a line — exactly the kind of change that would silently restore the
 * bug it exists to prevent (issue #1209).
 *
 * These tests read `appinfo/routes.php` as SOURCE TEXT rather than including
 * it. Including it needs `\OCA\OpenRegister\AppHost\Routes`, which is not
 * loadable in the unit bootstrap; stubbing it would mean re-implementing
 * `standard()` in the fixture and then asserting against my own copy of the
 * merge logic — a test that passes because the fixture agrees with itself.
 * What this app actually controls is the ORDER OF ITS OWN `$extra` entries,
 * and that is what is pinned here. AppHost's half of the contract —
 * `array_merge($canonical, $extra)` followed by appending the `/{path}`
 * catch-all — belongs to OpenRegister and is asserted in its own suite.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Pins the fallback route as the last `$extra` entry shillinq declares.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class ApiFallbackRouteOrderTest extends TestCase {

	/**
	 * The route table source.
	 *
	 * @return string
	 */
	private function source(): string {
		$path = __DIR__ . '/../../../appinfo/routes.php';
		self::assertFileExists($path);
		return (string)file_get_contents($path);

	}//end source()

	/**
	 * Every declared route entry, in order, as [name, url] pairs.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function entries(): array {
		$matches = [];
		preg_match_all(
			"/'name'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'/",
			$this->source(),
			$matches,
			PREG_SET_ORDER
		);

		$out = [];
		foreach ($matches as $m) {
			$out[] = [$m[1], $m[2]];
		}

		return $out;

	}//end entries()

	/**
	 * The fallback is declared, with the shape that makes it reachable.
	 *
	 * `{path}` without a `.+` requirement matches ONE segment. The dead url
	 * that motivated this — `api/openregister/objects/CommitmentLine/
	 * aggregations/committedVsRealisedPerBudgetLine` — is six segments deep, so
	 * a single-segment fallback would let exactly the case it exists to catch
	 * fall straight through to the SPA again.
	 *
	 * @return void
	 */
	public function testFallbackIsDeclaredAndMatchesMultiSegmentPaths(): void {
		$source = $this->source();

		self::assertStringContainsString(
			"'name' => 'apiFallback#notFound', 'url' => '/api/{path}'",
			$source,
			'The /api/{path} fallback must be declared'
		);
		self::assertMatchesRegularExpression(
			"/'apiFallback#notFound'.*'requirements'\s*=>\s*\['path'\s*=>\s*'\.\+'\]/s",
			$source,
			"The fallback's {path} must carry the .+ requirement or it only matches one segment"
		);

	}//end testFallbackIsDeclaredAndMatchesMultiSegmentPaths()

	/**
	 * The fallback is the LAST route entry in the file.
	 *
	 * `Routes::standard()` appends the SPA catch-all after `$extra`, so being
	 * last here means sitting immediately before it.
	 *
	 * @return void
	 */
	public function testFallbackIsTheLastDeclaredEntry(): void {
		$entries = $this->entries();
		self::assertNotEmpty($entries);

		$last = end($entries);
		self::assertSame(
			'apiFallback#notFound',
			$last[0],
			'The fallback must be the last $extra entry, so nothing shillinq declares is shadowed by it'
		);

	}//end testFallbackIsTheLastDeclaredEntry()

	/**
	 * No genuine `/api/` route is declared after the fallback.
	 *
	 * One that was would be shadowed and answer 404 — the fallback would then
	 * cause the very failure it exists to expose.
	 *
	 * @return void
	 */
	public function testNoRealApiRouteIsShadowedByTheFallback(): void {
		$entries = $this->entries();

		$seenFallback = false;
		$shadowed = [];
		foreach ($entries as [$name, $url]) {
			if ($name === 'apiFallback#notFound') {
				$seenFallback = true;
				continue;
			}

			if ($seenFallback === true && str_starts_with($url, '/api/') === true) {
				$shadowed[] = $name . ' => ' . $url;
			}
		}

		self::assertTrue($seenFallback, 'The fallback must be declared');
		self::assertSame([], $shadowed, 'These /api/ routes are shadowed by the fallback and can never match');

	}//end testNoRealApiRouteIsShadowedByTheFallback()

	/**
	 * The app declares a real number of API routes ahead of the fallback.
	 *
	 * A guard against the file being gutted or the regex silently matching
	 * nothing: a fallback that is "last" among two entries proves nothing.
	 *
	 * @return void
	 */
	public function testTheFallbackGuardsAPopulatedRouteTable(): void {
		$apiRoutes = 0;
		foreach ($this->entries() as [$name, $url]) {
			if ($name !== 'apiFallback#notFound' && str_starts_with($url, '/api/') === true) {
				$apiRoutes++;
			}
		}

		self::assertGreaterThan(100, $apiRoutes, 'Expected the full shillinq API surface ahead of the fallback');

	}//end testTheFallbackGuardsAPopulatedRouteTable()
}//end class
