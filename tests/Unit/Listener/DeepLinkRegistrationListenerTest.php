<?php

/**
 * Unit tests for DeepLinkRegistrationListener.
 *
 * Regression coverage for the shillinq-ap-push-notifications defect where
 * the APTransaction deeplink's urlTemplate (`/apps/shillinq/bookkeeping/
 * accounts-payable/{uuid}`) pointed at a route that does not exist in the
 * manifest — the real APTransactionDetail route is
 * `/bookkeeping/ap-transactions/:id`. Every deep link this listener
 * registers is now cross-checked against a real declared manifest `detail`
 * page route so a future drift between the listener and the manifest fails
 * the suite instead of 404ing in the browser.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-ap-push-notifications/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCP\EventDispatcher\GenericEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers: the listener registers a deeplink per real Shillinq schema, each
 * of whose urlTemplate resolves (after stripping the `/apps/shillinq`
 * vue-router base and substituting `{uuid}`) to a route the manifest
 * actually declares.
 */
final class DeepLinkRegistrationListenerTest extends TestCase {

	/**
	 * Vue-router base path the app is mounted under (main.js: `base:
	 * generateUrl('/apps/shillinq')`).
	 */
	private const APP_PREFIX = '/apps/shillinq';

	/**
	 * Build the listener with a mocked IAppConfig that resolves the
	 * register slug to 'shillinq'.
	 *
	 * @return DeepLinkRegistrationListener
	 */
	private function makeListener(): DeepLinkRegistrationListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new DeepLinkRegistrationListener($appConfig);
	}//end makeListener()

	/**
	 * Non-matching events are ignored (no exception, no registration).
	 *
	 * @return void
	 */
	public function testHandleIgnoresNonMatchingEvent(): void {
		$listener = $this->makeListener();
		$listener->handle(new GenericEvent());
		$this->addToAssertionCount(1);

	}//end testHandleIgnoresNonMatchingEvent()

	/**
	 * The listener registers a deeplink for both the `account` and the
	 * `APTransaction` schemas.
	 *
	 * @return void
	 */
	public function testHandleRegistersAccountAndApTransactionDeepLinks(): void {
		$listener = $this->makeListener();
		$event = new DeepLinkRegistrationEvent();

		$listener->handle($event);

		$registrations = $event->getRegistrations();
		$schemaSlugs = array_column($registrations, 'schemaSlug');

		self::assertContains('account', $schemaSlugs);
		self::assertContains('APTransaction', $schemaSlugs);

	}//end testHandleRegistersAccountAndApTransactionDeepLinks()

	/**
	 * The APTransaction deeplink's urlTemplate resolves to the real
	 * APTransactionDetail route declared in
	 * src/manifest.d/bookkeeping-accounts-payable-core.json
	 * (`/bookkeeping/ap-transactions/:id`) — NOT the non-existent
	 * `/bookkeeping/accounts-payable/:id` path a prior version pointed at.
	 *
	 * @return void
	 */
	public function testApTransactionDeepLinkResolvesToRealManifestRoute(): void {
		$registration = $this->registrationFor('APTransaction');

		self::assertSame(
			self::APP_PREFIX . '/bookkeeping/ap-transactions/{uuid}',
			$registration['urlTemplate'],
			'APTransaction deeplink must target the real APTransactionDetail route.'
		);

		$this->assertUrlTemplateResolvesToDeclaredDetailRoute($registration['urlTemplate'], 'APTransaction');

	}//end testApTransactionDeepLinkResolvesToRealManifestRoute()

	/**
	 * The account deeplink resolves to a real declared manifest route too
	 * (guards the pre-existing T1 registration against the same class of
	 * drift).
	 *
	 * @return void
	 */
	public function testAccountDeepLinkResolvesToRealManifestRoute(): void {
		$registration = $this->registrationFor('account');
		$this->assertUrlTemplateResolvesToDeclaredDetailRoute($registration['urlTemplate'], 'account');

	}//end testAccountDeepLinkResolvesToRealManifestRoute()

	/**
	 * Dispatch the event and return the registration for the given schema
	 * slug (fails the test if absent).
	 *
	 * @param string $schemaSlug The schema slug to look up.
	 *
	 * @return array<string,mixed>
	 */
	private function registrationFor(string $schemaSlug): array {
		$listener = $this->makeListener();
		$event = new DeepLinkRegistrationEvent();
		$listener->handle($event);

		foreach ($event->getRegistrations() as $registration) {
			if ($registration['schemaSlug'] === $schemaSlug) {
				return $registration;
			}
		}

		self::fail("No deeplink registration found for schema `$schemaSlug`.");

	}//end registrationFor()

	/**
	 * Assert that, once `{uuid}` is substituted and the vue-router base is
	 * stripped, the urlTemplate matches a `detail` page route declared for
	 * the given schema somewhere across `src/manifest.json` and every
	 * `src/manifest.d/*.json` fragment.
	 *
	 * @param string $urlTemplate The registered urlTemplate.
	 * @param string $schema The manifest page `config.schema` to match.
	 *
	 * @return void
	 */
	private function assertUrlTemplateResolvesToDeclaredDetailRoute(string $urlTemplate, string $schema): void {
		self::assertStringStartsWith(
			self::APP_PREFIX,
			$urlTemplate,
			'Deeplink urlTemplate must be mounted under the vue-router base (/apps/shillinq).'
		);

		$resolved = str_replace('{uuid}', 'REGRESSION-TEST-UUID', $urlTemplate);
		$routerPath = substr($resolved, strlen(self::APP_PREFIX));

		$matchedRoutes = [];
		foreach ($this->collectManifestDetailRoutes($schema) as $route) {
			$matchedRoutes[] = $route;
			if (preg_match($this->routeToRegex($route), $routerPath) === 1) {
				$this->addToAssertionCount(1);
				return;
			}
		}

		self::fail(
			"Deeplink urlTemplate `$urlTemplate` (router path `$routerPath`) does not match any declared "
			. "`detail` page route for schema `$schema`. Declared candidate routes: "
			. (empty($matchedRoutes) ? '(none found)' : implode(', ', $matchedRoutes))
		);

	}//end assertUrlTemplateResolvesToDeclaredDetailRoute()

	/**
	 * Collect the `route` of every `type: detail` manifest page bound to
	 * the given schema, across the bundled manifest and all manifest.d
	 * fragments. Matching is case-insensitive because the OpenRegister
	 * deeplink `schemaSlug` (e.g. `account`) and the manifest page's
	 * `config.schema` (e.g. `Account`) do not always share casing.
	 *
	 * @param string $schema The `config.schema` to match.
	 *
	 * @return array<int,string>
	 */
	private function collectManifestDetailRoutes(string $schema): array {
		$routes = [];
		foreach ($this->allManifestPages() as $page) {
			if (($page['type'] ?? null) !== 'detail') {
				continue;
			}

			$pageSchema = $page['config']['schema'] ?? null;
			if (is_string($pageSchema) === false || strcasecmp($pageSchema, $schema) !== 0) {
				continue;
			}

			if (is_string($page['route'] ?? null)) {
				$routes[] = $page['route'];
			}
		}

		return $routes;
	}//end collectManifestDetailRoutes()

	/**
	 * Load and concatenate the `pages` arrays of `src/manifest.json` and
	 * every `src/manifest.d/*.json` fragment — mirrors the runtime merge
	 * `main.js` performs via `buildManifest(bundledManifest, fragments, ...)`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function allManifestPages(): array {
		$pages = [];

		$mainPath = __DIR__ . '/../../../src/manifest.json';
		self::assertFileExists($mainPath, 'src/manifest.json must exist.');
		$main = json_decode((string)file_get_contents($mainPath), true);
		self::assertIsArray($main, 'src/manifest.json must be valid JSON.');
		foreach (($main['pages'] ?? []) as $page) {
			$pages[] = $page;
		}

		$fragmentDir = __DIR__ . '/../../../src/manifest.d';
		self::assertDirectoryExists($fragmentDir, 'src/manifest.d must exist.');
		foreach (glob($fragmentDir . '/*.json') as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			self::assertIsArray($fragment, "$fragmentPath must be valid JSON.");
			foreach (($fragment['pages'] ?? []) as $page) {
				$pages[] = $page;
			}
		}

		return $pages;
	}//end allManifestPages()

	/**
	 * Turn a vue-router path pattern (e.g. `/bookkeeping/ap-transactions/:id`)
	 * into an anchored regex, treating `:param` segments as wildcards.
	 *
	 * @param string $route The manifest page route pattern.
	 *
	 * @return string
	 */
	private function routeToRegex(string $route): string {
		$segments = explode('/', $route);
		$regexSegments = array_map(
			static function (string $segment): string {
				if ($segment !== '' && $segment[0] === ':') {
					return '[^/]+';
				}

				return preg_quote($segment, '#');
			},
			$segments
		);

		return '#^' . implode('/', $regexSegments) . '$#';
	}//end routeToRegex()

}//end class
