<?php

/**
 * Tests for the canonical AppHost route table's method contract.
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
 * @spec openspec/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The canonical AppHost route table routes a fixed set of names into THIS
 * app's controller namespace, and OpenRegister only substitutes its generic
 * controller when this app does not ship a class of that name.
 *
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * registers the DI alias `OCA\Shillinq\Controller\XController` ->
 * `OCA\OpenRegister\AppHost\Controller\GenericXController` ONLY when the leaf
 * class does not exist. So the seam has two sides, and they fail differently:
 *
 *   - Leaf does NOT ship the class  -> the alias binds, the generic serves
 *     every canonical method. Nothing is owed. (This is why the absence of
 *     DashboardController / HealthController / MetricsController on disk is
 *     correct per `openspec/specs/apphost-adoption/spec.md` and must not be
 *     "fixed" by creating them.)
 *   - Leaf DOES ship the class      -> the alias is skipped and the generic is
 *     never constructed, so the leaf owes EVERY method the canonical table
 *     routes to that controller. A missing one is not a 404: the router
 *     matches the URL, `ControllerMethodReflector` reflects the method, and the
 *     request dies with a 500 `ReflectionException`.
 *
 * Measured live on the dev instance 2026-08-08: shillinq shipped its own
 * `SettingsController` with `index/create/load` but no `update()`, while the
 * canonical table routes `PUT /api/settings` to `settings#update`.
 * `GET /apps/shillinq/api/settings` returned 200 and
 * `PUT /apps/shillinq/api/settings` returned 500 with
 * "Method OCA\Shillinq\Controller\SettingsController::update() does not exist".
 *
 * This test asserts the ITEM (each individual method), never the container
 * (the controller class merely existing).
 */
final class CanonicalRouteMethodContractTest extends TestCase {

	/**
	 * The canonical route names supplied by
	 * `\OCA\OpenRegister\AppHost\Routes::standard()`.
	 *
	 * Keyed `controllerPrefix => [method, ...]`. This list is a local mirror:
	 * shillinq's `appinfo/routes.php` delegates to `Routes::standard()`
	 * unconditionally and ships NO local fallback copy, so the literal strings
	 * `settings#update` etc. do not appear anywhere in this repository. A test
	 * that grepped `appinfo/routes.php` for them would find nothing and could
	 * only pass vacuously or fail wrongly — see
	 * `testRouteTableStillDelegatesToTheCanonicalAppHostTable()` for the
	 * assertion that IS meaningful here, and
	 * `testCanonicalListMatchesOpenRegistersOwnTableWhenReachable()` for the
	 * best-effort cross-check against the real source.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CANONICAL_ROUTES = [
		'Dashboard' => ['page', 'catchAll'],
		'Settings' => ['index', 'create', 'update', 'load'],
		'Preferences' => ['getPreference', 'setPreference'],
		'Metrics' => ['index'],
		'Health' => ['index'],
	];

	/**
	 * Absolute path to this app's repository root.
	 *
	 * @return string The repository root path.
	 */
	private function repoRoot(): string {
		return dirname(__DIR__, 3);
	}//end repoRoot()

	/**
	 * The route table must still delegate to the canonical AppHost table.
	 *
	 * This is the positive control for the method contract below: the leaf-owed
	 * method list only binds while `appinfo/routes.php` actually returns
	 * `Routes::standard(...)`. If shillinq ever stopped delegating (inlining
	 * its own table instead), the canonical list would be stale and a green
	 * from the next test would mean nothing. Read that green only together
	 * with a green here.
	 *
	 * @return void
	 */
	public function testRouteTableStillDelegatesToTheCanonicalAppHostTable(): void {
		$routesFile = $this->repoRoot() . '/appinfo/routes.php';
		$this->assertFileExists($routesFile, 'appinfo/routes.php must exist');

		$routesSource = file_get_contents($routesFile);
		$this->assertIsString($routesSource, 'appinfo/routes.php must be readable');

		$this->assertStringContainsString(
			'OCA\\OpenRegister\\AppHost\\Routes::standard',
			$routesSource,
			'appinfo/routes.php no longer delegates to the canonical AppHost route '
			. 'table. The leaf-owed method list in this test is derived from that '
			. 'table, so it is now stale — re-derive it from whatever routes.php '
			. 'actually declares before trusting the method contract test.'
		);

	}//end testRouteTableStillDelegatesToTheCanonicalAppHostTable()

	/**
	 * Cross-check the local mirror against OpenRegister's real route table.
	 *
	 * OpenRegister is a runtime app dependency, not a composer dependency, so
	 * its source is not present in this repository's `vendor/` and is normally
	 * unreachable from a CI checkout. This half therefore SKIPS in CI and
	 * provides no signal there — it only fires for a developer running the
	 * suite from a workspace that also holds an openregister checkout. It is
	 * kept because when it does run it is the only thing that can catch the
	 * local mirror drifting from the real table.
	 *
	 * @return void
	 */
	public function testCanonicalListMatchesOpenRegistersOwnTableWhenReachable(): void {
		$candidates = [
			$this->repoRoot() . '/../openregister/lib/AppHost/Routes.php',
			$this->repoRoot() . '/../../openregister/lib/AppHost/Routes.php',
			'/var/www/html/custom_apps/openregister/lib/AppHost/Routes.php',
		];

		$source = null;
		foreach ($candidates as $candidate) {
			if (file_exists($candidate) === true) {
				$source = file_get_contents($candidate);
				break;
			}
		}

		if (is_string($source) === false) {
			$this->markTestSkipped(
				'OpenRegister\'s AppHost/Routes.php is not reachable from this checkout, '
				. 'so the canonical route list cannot be cross-checked here. This is the '
				. 'expected state in CI — treat the mirror in self::CANONICAL_ROUTES as '
				. 'unverified by this run.'
			);
		}

		foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
			foreach ($methods as $method) {
				$routeName = lcfirst($prefix) . '#' . $method;
				$this->assertStringContainsString(
					"'" . $routeName . "'",
					$source,
					sprintf(
						'OpenRegister\'s AppHost route table no longer declares "%s". '
						. 'The mirror in this test is stale.',
						$routeName
					)
				);
			}
		}

	}//end testCanonicalListMatchesOpenRegistersOwnTableWhenReachable()

	/**
	 * A controller this app ships itself must implement every canonical
	 * method routed to it — the AppHost generic will not fill the gap.
	 *
	 * @return void
	 */
	public function testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem(): void {
		$inspected = 0;
		$missing = [];

		foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
			$class = 'OCA\\Shillinq\\Controller\\' . $prefix . 'Controller';

			// The class file existing on disk is what makes the AppHost skip
			// the alias. `class_exists()` alone would also be satisfied by the
			// DI alias target in a booted container, which is precisely the
			// case this test must NOT treat as leaf-owned.
			$file = $this->repoRoot() . '/lib/Controller/' . $prefix . 'Controller.php';
			if (file_exists($file) === false) {
				continue;
			}

			$this->assertTrue(
				class_exists($class),
				sprintf('%s exists on disk but does not autoload as %s', $file, $class)
			);

			$reflection = new ReflectionClass($class);

			foreach ($methods as $method) {
				$inspected++;
				if ($reflection->hasMethod($method) === false) {
					$missing[] = $prefix . 'Controller::' . $method . '()';
					continue;
				}

				$this->assertTrue(
					$reflection->getMethod($method)->isPublic(),
					sprintf('%s::%s() must be public to be dispatchable', $class, $method)
				);
			}
		}//end foreach

		// Positive control: an empty $missing ("nothing is missing") is only
		// meaningful if something was actually inspected. Zero inspections
		// would mean the lib/Controller path probe silently matched nothing.
		$this->assertGreaterThan(
			0,
			$inspected,
			'No leaf-owned canonical controller was inspected — the lib/Controller '
			. 'path probe is broken, so the empty finding list means nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			sprintf(
				'The canonical AppHost route table routes to these method(s), but this '
				. 'app ships the controller itself so no generic is aliased in. Each of '
				. "these is a 500, not a 404.\n  - %s",
				implode("\n  - ", $missing)
			)
		);

	}//end testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem()

	/**
	 * The settings write must be reachable under BOTH canonical verbs.
	 *
	 * Named explicitly (rather than only via the loop above) so a regression
	 * reports the actual defect by name instead of a generic list entry.
	 *
	 * @return void
	 */
	public function testSettingsControllerExposesBothCanonicalWriteMethods(): void {
		$reflection = new ReflectionClass(\OCA\Shillinq\Controller\SettingsController::class);

		foreach (['update', 'create'] as $method) {
			$this->assertTrue(
				$reflection->hasMethod($method),
				sprintf(
					'SettingsController::%s() does not exist. The canonical AppHost table '
					. 'routes PUT /api/settings to settings#update and POST /api/settings '
					. 'to settings#create; a missing method is a 500, not a 404.',
					$method
				)
			);

			$this->assertTrue(
				$reflection->getMethod($method)->isPublic(),
				sprintf('SettingsController::%s() must be public to be dispatchable', $method)
			);
		}

	}//end testSettingsControllerExposesBothCanonicalWriteMethods()

}//end class
