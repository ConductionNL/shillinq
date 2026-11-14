<?php

/**
 * Unit tests that keep shillinq's platform properties true.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Five properties describe how shillinq behaves as an app: sign-in and
 * two-factor authentication stay Nextcloud's, the API is reached through
 * Nextcloud's access attributes, the interface ships its catalogues, and the
 * app runs on the customer's own server under EUPL-1.2 with OpenRegister as
 * its only required app. Nothing renders any of these, so nothing would
 * notice one breaking; these tests read the files that carry them.
 *
 * Like ApiFallbackRouteOrderTest, the route table is read as SOURCE TEXT:
 * including `appinfo/routes.php` needs OpenRegister's AppHost routes, which
 * the unit bootstrap does not load.
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
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * REQ-PLAT-001 to REQ-PLAT-005, asserted over the files that carry them.
 */
#[CoversNothing]
class PlatformPropertiesTest extends TestCase {

	/**
	 * The Nextcloud attributes that state who may call a controller method.
	 */
	private const ACCESS_ATTRIBUTES = ['NoAdminRequired', 'PublicPage', 'AuthorizedAdminSetting', 'SubAdminRequired'];

	/**
	 * Every routed `#[PublicPage]` method, with the file and the call that
	 * stands in for a Nextcloud login on it. A method without a check names
	 * why it needs none.
	 *
	 * @var array<string, array{file: string, check: string}>
	 */
	private const PUBLIC_METHODS = [
		// The OpenAPI document of the public API: no data, rate limited.
		'OpenApiController::show' => ['file' => 'OpenApiController.php', 'check' => '#[AnonRateLimit('],
		// The booking widget: a per-business API key as bearer.
		'WidgetApiController::services' => ['file' => 'WidgetApiController.php', 'check' => '$this->auth->validateApiKey('],
		'WidgetApiController::slots' => ['file' => 'WidgetApiController.php', 'check' => '$this->auth->validateApiKey('],
		'WidgetApiController::appointments' => ['file' => 'WidgetApiController.php', 'check' => '$this->auth->validateApiKey('],
		// The confirmation link: the appointment's one-time token.
		'ConfirmationApiController::lookupByToken' => ['file' => 'ConfirmationApiController.php', 'check' => '$this->tokens->validate('],
		'ConfirmationApiController::confirm' => ['file' => 'ConfirmationApiController.php', 'check' => '$this->tokens->validate('],
		// The scanner: its device key.
		'BarcodeLookupController::lookup' => ['file' => 'BarcodeLookupController.php', 'check' => '$this->isAuthorized()'],
		// Payment provider webhooks: the provider's signature over the raw body.
		'DepositWebhookController::handle' => ['file' => 'PaymentWebhookReceiver.php', 'check' => '->verifiedPayload('],
		'PaymentRequestWebhookController::handle' => ['file' => 'PaymentWebhookReceiver.php', 'check' => '->verifiedPayload('],
		// The payroll webhook: HMAC over the body; GET only answers 501.
		'PayrollWebhookController::info' => ['file' => 'PayrollWebhookController.php', 'check' => 'Http::STATUS_NOT_IMPLEMENTED'],
		'PayrollWebhookController::receive' => ['file' => 'PayrollWebhookController.php', 'check' => '$this->verifySignature('],
		// Calls from the portal: its signed assertion.
		'PortalPaymentInitiationController::initiate' => ['file' => 'PortalPaymentInitiationController.php', 'check' => '$this->verifier->verify('],
		'PortalContributionDeclineController::decline' => ['file' => 'PortalContributionDeclineController.php', 'check' => '$this->verifier->verify('],
	];

	/**
	 * The two places a password hash may appear: share and widget tokens.
	 */
	private const PASSWORD_HASH_FILES = ['Service/WidgetAuthService.php', 'Util/TokenValidator.php'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The text of a repository file.
	 *
	 * @param string $path The path from the repository root.
	 *
	 * @return string
	 */
	private static function read(string $path): string {
		$text = file_get_contents(self::root() . '/' . $path);
		self::assertIsString($text, $path . ' must be readable');

		return $text;
	}//end read()

	/**
	 * Every `controller#method` the route table names, once each.
	 *
	 * @return array<int, array{0: string, 1: string}> Controller class and method.
	 */
	private static function routedMethods(): array {
		preg_match_all("/'name'\s*=>\s*'([A-Za-z0-9_]+)#([A-Za-z0-9_]+)'/", self::read(path: 'appinfo/routes.php'), $matches, PREG_SET_ORDER);
		$methods = [];
		foreach ($matches as [, $controller, $method]) {
			$methods[ucfirst($controller) . 'Controller::' . $method] = [ucfirst($controller) . 'Controller', $method];
		}

		return array_values($methods);
	}//end routedMethods()

	/**
	 * The attributes and docblock directly above one method.
	 *
	 * @param string $source The controller source.
	 * @param string $method The method name.
	 *
	 * @return string|null The text, or null when the method is not there.
	 */
	private static function preamble(string $source, string $method): ?string {
		$position = strpos($source, 'public function ' . $method . '(');
		if ($position === false) {
			return null;
		}

		$before = substr($source, 0, $position);
		$start  = max((int)strrpos($before, "}\n"), (int)strrpos($before, "{\n"));

		return substr($before, $start);
	}//end preamble()

	/**
	 * Every PHP file under lib/, relative to lib/.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	private static function libSources(): array {
		$sources = [];
		$lib = self::root() . '/lib';
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() === 'php') {
				$sources[substr($file->getPathname(), strlen($lib) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}

		ksort($sources);
		return $sources;
	}//end libSources()

	/**
	 * REQ-PLAT-005: the app is EUPL-1.2, ships the licence text, and needs no app but OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-properties/tasks.md#2-a-test-that-keeps-the-properties-true
	 */
	public function testTheAppIsEuplAndNeedsOnlyOpenRegister(): void {
		$info = simplexml_load_string(self::read(path: 'appinfo/info.xml'));
		self::assertNotFalse($info, 'appinfo/info.xml must parse');
		self::assertSame('EUPL-1.2', (string)$info->licence);

		$licence = self::read(path: 'LICENSE');
		self::assertStringStartsWith('EUROPEAN UNION PUBLIC LICENCE v. 1.2', ltrim($licence));
		self::assertStringContainsString('EUPL © the European Union 2007, 2016', $licence);

		$manifest = json_decode(self::read(path: 'src/manifest.json'), true);
		self::assertSame(['openregister'], $manifest['dependencies'], 'OpenRegister is the only app the manifest requires');
	}//end testTheAppIsEuplAndNeedsOnlyOpenRegister()

	/**
	 * REQ-PLAT-001: every routed method states who may call it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-properties/tasks.md#2-a-test-that-keeps-the-properties-true
	 */
	public function testEveryRoutedMethodCarriesAnAccessAttribute(): void {
		$routed = self::routedMethods();
		self::assertGreaterThan(100, count($routed), 'the route table must still be read');

		$bare = [];
		foreach ($routed as [$class, $method]) {
			$preamble = self::preamble(source: self::read(path: 'lib/Controller/' . $class . '.php'), method: $method);
			self::assertNotNull($preamble, $class . '::' . $method . ' is routed but not defined');
			if (preg_match('/#\[(' . implode('|', self::ACCESS_ATTRIBUTES) . ')\b/', $preamble) !== 1) {
				$bare[] = $class . '::' . $method;
			}
		}

		self::assertSame([], $bare, 'routed methods without a Nextcloud access attribute');
	}//end testEveryRoutedMethodCarriesAnAccessAttribute()

	/**
	 * REQ-PLAT-001 and REQ-PLAT-003: a public method is one this test names,
	 * and the check it relies on is still in its code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-properties/tasks.md#2-a-test-that-keeps-the-properties-true
	 */
	public function testEveryPublicMethodIsAllowListedWithItsCheck(): void {
		$public = [];
		foreach (self::routedMethods() as [$class, $method]) {
			$preamble = (string)self::preamble(source: self::read(path: 'lib/Controller/' . $class . '.php'), method: $method);
			if (str_contains($preamble, '#[PublicPage]') === true) {
				$public[] = $class . '::' . $method;
			}
		}

		sort($public);
		$allowed = array_keys(self::PUBLIC_METHODS);
		sort($allowed);
		self::assertSame($allowed, $public, 'a #[PublicPage] method must be on the allow-list with its check');

		foreach (self::PUBLIC_METHODS as $name => $entry) {
			self::assertStringContainsString($entry['check'], self::read(path: 'lib/Controller/' . $entry['file']), $name . ' no longer makes its check');
		}
	}//end testEveryPublicMethodIsAllowListedWithItsCheck()

	/**
	 * REQ-PLAT-001 and REQ-PLAT-002: sign-in stays Nextcloud's. No file starts
	 * a session or sets a login cookie, and a password hash is only made of
	 * share and widget tokens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-properties/tasks.md#2-a-test-that-keeps-the-properties-true
	 */
	public function testSignInStaysNextclouds(): void {
		$sessions = [];
		$hashes   = [];
		foreach (self::libSources() as $path => $source) {
			if (preg_match('/\b(setcookie|setrawcookie|session_start)\s*\(/', $source) === 1) {
				$sessions[] = $path;
			}

			if (preg_match('/\bpassword_hash\s*\(/', $source) === 1) {
				$hashes[] = $path;
			}
		}

		self::assertSame([], $sessions, 'files under lib/ that start a session or set a cookie');
		self::assertSame(self::PASSWORD_HASH_FILES, $hashes, 'password_hash outside the token hashing');
	}//end testSignInStaysNextclouds()

	/**
	 * REQ-PLAT-004: the Dutch and English catalogues exist and parse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-properties/tasks.md#2-a-test-that-keeps-the-properties-true
	 */
	public function testTheDutchAndEnglishCataloguesParse(): void {
		foreach (['nl', 'en'] as $locale) {
			$catalogue = json_decode(self::read(path: 'l10n/' . $locale . '.json'), true);
			self::assertIsArray($catalogue, 'l10n/' . $locale . '.json must parse');
			self::assertIsArray($catalogue['translations'] ?? null, 'l10n/' . $locale . '.json must carry translations');
			self::assertNotEmpty($catalogue['translations']);
		}
	}//end testTheDutchAndEnglishCataloguesParse()
}//end class
