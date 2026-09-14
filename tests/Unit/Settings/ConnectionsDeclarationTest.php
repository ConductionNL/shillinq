<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of the External Connections page. Nothing in shillinq reads it at
 * runtime, so a broken file fails nowhere in this repo: integriq skips it whole
 * and the page goes empty on some other instance. Every assertion here is a way
 * that file could go wrong without a sound, including the way it could stop
 * telling the truth about which adapters shillinq calls.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\ConnectionReportService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * @coversNothing
 */
final class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Every adapter family's port interface, by connection key, in roster order.
	 *
	 * The short name is what a caller would type, so it is what the "nothing
	 * calls it" guard searches for.
	 *
	 * @var array<string, string>
	 */
	private const PORTS = [
		'digipoort-sbr' => 'DigipoortSbrAdapterInterface',
		'salarisbureau' => 'SalarisbureauAdapterInterface',
		'rvo' => 'RvOAanvraagAdapterInterface',
		'ib47' => 'Ib47AdapterInterface',
		'cbs-bestanden' => 'CbsBestandenAdapterInterface',
		'cbs-iv3' => 'CbsIv3AdapterInterface',
		'bzk-sisa' => 'BzkSisaUploadAdapterInterface',
		'mollie' => 'MolliePaymentAdapterInterface',
		'bunq' => 'BunqBankConnectorAdapterInterface',
		'kvk' => 'KvkHandelsregisterAdapterInterface',
		'uwv' => 'UwvLoonaangifteAdapterInterface',
		'treasury-rates' => 'TreasuryRateAdapterInterface',
		'ccm-rule-engine' => 'CcmRuleEngineAdapterInterface',
		'csrd-esrs-xbrl' => 'CsrdEsrsXbrlAdapterInterface',
		'deposit-payment' => 'DepositPaymentAdapterInterface',
	];

	/**
	 * The source templates integriq ships, checked on integriq development on
	 * 2026-09-14 (`lib/Settings/register.d/*-source.json`). Only the ones a
	 * shillinq family could use are listed.
	 *
	 * @var array<int, string>
	 */
	private const INTEGRIQ_SOURCE_TEMPLATES = ['kvk'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * Validate a JSON string against the vendored integriq schema.
	 *
	 * @param string $json The document.
	 *
	 * @return bool Whether it is valid.
	 */
	private function validates(string $json): bool {
		$this->assertTrue(
			condition: class_exists(Validator::class),
			message: 'opis/json-schema is not installed; without it this guard cannot fail, so it fails here instead'
		);

		$schema = file_get_contents($this->root() . '/tests/fixtures/integriq/connections.schema.json');
		$this->assertIsString(actual: $schema);

		return (new Validator())->validate(data: json_decode($json), schema: json_decode($schema))->isValid();
	}//end validates()

	/**
	 * The file validates against integriq's connections.schema.json.
	 *
	 * The control beside it proves the validator can say no: a copy with a
	 * field D2 does not allow must fail.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$this->assertTrue(condition: $this->validates(json: $this->raw()), message: 'connections.json breaks integriq\'s schema');

		$broken = $this->declaration();
		$broken['connections'][0]['status'] = 'configured';
		$this->assertFalse(
			condition: $this->validates(json: (string)json_encode($broken)),
			message: 'the control failed: the validator accepted a field the schema forbids'
		);

		// The vendored schema must be the amended one (hydra#673): it knows
		// `reportedOnly` and types it. An old copy would reject the file above,
		// and a copy without the type would accept this.
		$mistyped = $this->declaration();
		$mistyped['connections'][0]['reportedOnly'] = 'yes';
		$this->assertFalse(
			condition: $this->validates(json: (string)json_encode($mistyped)),
			message: 'the control failed: the validator accepted a reportedOnly that is not a boolean'
		);
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The file names the app it ships in.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The fifteen roster keys, in roster order, unique, with a rising order.
	 *
	 * A row is keyed by app and key. A renamed key orphans a row.
	 *
	 * @return void
	 */
	public function testTheFifteenRosterKeysAreDeclaredOnceInOrder(): void {
		$connections = $this->declaration()['connections'];
		$keys = array_column($connections, 'key');

		$this->assertSame(expected: array_keys(self::PORTS), actual: $keys);
		$this->assertSame(expected: count($keys), actual: count(array_unique($keys)));

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertSame(expected: count($orders), actual: count(array_unique($orders)));
	}//end testTheFifteenRosterKeysAreDeclaredOnceInOrder()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: ' -- ', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * The reported families are exactly the ones not declared unavailable.
	 *
	 * A family declared unavailable never leaves that state (contract rule 2),
	 * so a report for it is a wasted write. A family not declared unavailable
	 * and never reported sits on Not checked yet forever. Every reported
	 * family is `reportedOnly`, and no other family is.
	 *
	 * @return void
	 */
	public function testTheReportedFamiliesAreTheAvailableOnes(): void {
		$available = [];
		foreach ($this->declaration()['connections'] as $connection) {
			if (($connection['available'] ?? true) === true) {
				$available[] = $connection['key'];
				// Only shillinq can see which class DI bound, so integriq must
				// not judge the row from app config (contract D4, hydra#673).
				$this->assertTrue(
					condition: ($connection['reportedOnly'] ?? false) === true,
					message: $connection['key'] . ' is reported, so it must be reportedOnly'
				);
				$this->assertStringContainsString(
					needle: 'once a day',
					haystack: (string)($connection['unconfiguredMessage'] ?? ''),
					message: $connection['key'] . ' must say when shillinq reports on it'
				);
				continue;
			}

			$this->assertStringContainsString(
				needle: 'no screen or service calls it',
				haystack: (string)($connection['unavailableMessage'] ?? ''),
				message: $connection['key'] . ' must say why it is not available'
			);
			$this->assertArrayNotHasKey(
				key: 'reportedOnly',
				array: $connection,
				message: $connection['key'] . ' gets no report, so reportedOnly would promise one that never comes'
			);
		}

		$this->assertSame(expected: array_keys(ConnectionReportService::FAMILIES), actual: $available);
	}//end testTheReportedFamiliesAreTheAvailableOnes()

	/**
	 * A family declared unavailable really has no caller in lib/.
	 *
	 * The declaration says "no screen or service calls it". The day a service
	 * injects one of these ports, that sentence is false and the row hides a
	 * working seam. This fails on that day, and the fix is to move the family
	 * into ConnectionReportService::FAMILIES and drop `available: false`.
	 *
	 * The control asserts the search can find a caller: a reported family's
	 * port must turn up.
	 *
	 * @return void
	 */
	public function testAnUnavailableFamilyHasNoCallerInLib(): void {
		$sources = $this->callerSources();
		$this->assertGreaterThan(expected: 500, actual: count($sources), message: 'the walker must see lib/');

		foreach ($this->declaration()['connections'] as $connection) {
			$port = self::PORTS[$connection['key']];
			$callers = array_keys(
				array_filter($sources, static fn (string $source): bool => str_contains($source, $port))
			);

			if (($connection['available'] ?? true) === false) {
				$this->assertSame(expected: [], actual: $callers, message: $port . ' is called, so ' . $connection['key'] . ' is not unavailable');
				continue;
			}

			$this->assertNotSame(expected: [], actual: $callers, message: 'the control failed: no caller found for ' . $port);
		}
	}//end testAnUnavailableFamilyHasNoCallerInLib()

	/**
	 * A settings link opens a page shillinq's manifest declares.
	 *
	 * A link to a route nothing declares opens the app's start page and logs
	 * nothing, which is the untruth this page exists to stop.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkOpensADeclaredPage(): void {
		$routes = $this->manifestRoutes();
		$this->assertContains(needle: '/external-adapters', haystack: $routes, message: 'the control failed: the route walker found nothing');

		$linked = [];
		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/apps/shillinq/', string: $url);
			$this->assertContains(
				needle: substr($url, strlen('/apps/shillinq')),
				haystack: $routes,
				message: $connection['key'] . ' links to a page the manifest does not declare'
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: ['treasury-rates'], actual: $linked);
	}//end testEverySettingsLinkOpensADeclaredPage()

	/**
	 * A source template is one integriq ships.
	 *
	 * @return void
	 */
	public function testEverySourceTemplateIsOneIntegriqShips(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('sourceTemplate', $connection) === true) {
				$this->assertContains(needle: $connection['sourceTemplate'], haystack: self::INTEGRIQ_SOURCE_TEMPLATES);
			}
		}
	}//end testEverySourceTemplateIsOneIntegriqShips()

	/**
	 * Every PHP file under lib/ that could call a port, keyed by path.
	 *
	 * The adapters themselves and the composition root that binds them are not
	 * callers.
	 *
	 * @return array<string, string>
	 */
	private function callerSources(): array {
		$root = $this->root() . '/lib';
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		$sources = [];
		foreach ($files as $file) {
			$path = substr((string)$file->getPathname(), strlen($root) + 1);
			if (str_ends_with($path, '.php') === false
				|| str_starts_with($path, 'Service/External/') === true
				|| $path === 'AppInfo/Application.php'
				|| $path === 'Service/ConnectionReportService.php'
			) {
				continue;
			}

			$sources[$path] = (string)file_get_contents((string)$file->getPathname());
		}

		return $sources;
	}//end callerSources()

	/**
	 * Every page route the manifest and its fragments declare.
	 *
	 * @return array<int, string>
	 */
	private function manifestRoutes(): array {
		$fragments = glob($this->root() . '/src/manifest.d/*.json');
		$this->assertIsArray(actual: $fragments);
		$files = array_merge([$this->root() . '/src/manifest.json'], $fragments);

		$routes = [];
		foreach ($files as $file) {
			$manifest = json_decode((string)file_get_contents($file), true);
			foreach (($manifest['pages'] ?? []) as $page) {
				if (isset($page['route']) === true) {
					$routes[] = (string)$page['route'];
				}
			}
		}

		return $routes;
	}//end manifestRoutes()
}//end class
