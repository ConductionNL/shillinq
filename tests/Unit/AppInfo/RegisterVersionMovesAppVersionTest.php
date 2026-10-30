<?php

/**
 * Regression guard: a register change must move the app version.
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
 * @spec exclude repository guard over appinfo/info.xml and lib/Settings, no product behaviour
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * The register (lib/Settings/shillinq_register.json plus every
 * lib/Settings/register.d fragment) reaches an existing instance through ONE
 * door: the InitializeSettings post-migration repair step. Nextcloud runs that
 * step on `occ upgrade` only when `<version>` in appinfo/info.xml is newer than
 * the installed version. With the version unchanged, `occ upgrade` answers
 * "No upgrade required.", the step never runs, and the instance keeps the old
 * schemas without a single error. OpenRegister's own import is content-aware,
 * so once the step runs a changed fragment is re-imported; the step simply has
 * to run.
 *
 * MEASURED (live pass, 5 Oct 2026): the dunning pull requests moved
 * DunningLadder to 0.3.0, DunningRun to 0.3.0 and Administration to 0.2.0 and
 * left `<version>` alone. On the dev instance DunningLadder stayed 0.1.0 and
 * none of the landed dunning work was reachable.
 *
 * Hydra gate-110 does not catch this: it asks for a version move only when a
 * diff ADDS a migration or a repair step, and a register fragment is neither.
 *
 * HOW THIS GUARD WORKS. tests/fixtures/register-versions.lock.json holds the
 * version of every schema (and register, and the register's info.version) as it
 * stood at the last app version that delivered them, plus a history of those
 * app versions. The test fails when the tree's versions differ from the lock,
 * and tells the author what to do: move `<version>` in info.xml, then record
 * the new versions under that app version. Every recorded app version must be
 * newer than the one before it, and info.xml may not be older than the last.
 * A new schema counts as a move (it also arrives only through the step).
 */
final class RegisterVersionMovesAppVersionTest extends TestCase {

	/**
	 * Path of the lock, relative to the repository root.
	 *
	 * @var string
	 */
	private const LOCK = 'tests/fixtures/register-versions.lock.json';

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Resolve the repository root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}//end setUp()

	/**
	 * The versions in the tree are the versions the last app version delivered.
	 *
	 * @return void
	 */
	public function testRegisterVersionsMatchTheLastRecordedAppVersion(): void {
		$lock = $this->readLock();
		$current = self::versionMap($this->root);
		$recorded = $lock['versions'];

		$moved = [];
		foreach ($current as $key => $version) {
			if (array_key_exists($key, $recorded) === false) {
				$moved[] = $key . ' (new, ' . ($version === '' ? 'no version' : $version) . ')';
				continue;
			}

			if ($recorded[$key] !== $version) {
				$moved[] = $key . ' (' . $recorded[$key] . ' -> ' . $version . ')';
			}
		}

		foreach (array_keys($recorded) as $key) {
			if (array_key_exists($key, $current) === false) {
				$moved[] = $key . ' (removed)';
			}
		}

		$history = $lock['recorded'];
		$last = end($history);
		$appVersion = $this->appVersion();

		$this->assertSame(
			[],
			$moved,
			"The register changed since app version " . $last['appVersion'] . ":\n  "
			. implode("\n  ", $moved) . "\n"
			. "An existing instance only re-imports the register when <version> in appinfo/info.xml moves "
			. "(the InitializeSettings repair step runs on `occ upgrade` only then).\n"
			. "1. Move <version> in appinfo/info.xml above " . $last['appVersion'] . " (it is " . $appVersion . " now).\n"
			. "2. In " . self::LOCK . ", replace \"versions\" with the map below and append to \"recorded\": "
			. json_encode(['appVersion' => '<the new info.xml version>', 'versionsSha256' => self::hashVersions($current)], JSON_UNESCAPED_SLASHES) . "\n"
			. json_encode($current, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
		);
	}//end testRegisterVersionsMatchTheLastRecordedAppVersion()

	/**
	 * The recorded versions belong to the last recorded app version, recorded
	 * app versions only go up, and info.xml is not older than the last of them.
	 *
	 * @return void
	 */
	public function testEachRecordedChangeMovedTheAppVersion(): void {
		$lock = $this->readLock();
		$history = $lock['recorded'];
		$this->assertNotSame([], $history, 'The lock records no app version.');

		$previous = null;
		foreach ($history as $entry) {
			if ($previous !== null) {
				$this->assertTrue(
					version_compare($entry['appVersion'], $previous, '>'),
					'Recorded app version ' . $entry['appVersion'] . ' is not newer than ' . $previous
					. ': a register change was recorded without moving <version> in appinfo/info.xml.'
				);
			}

			$previous = $entry['appVersion'];
		}

		$last = end($history);
		$this->assertSame(
			$last['versionsSha256'],
			self::hashVersions($lock['versions']),
			'The "versions" map in ' . self::LOCK . ' is not the one recorded under app version '
			. $last['appVersion'] . '. Append a new "recorded" entry with a newer app version instead of editing it.'
		);

		$appVersion = $this->appVersion();
		$this->assertTrue(
			version_compare($appVersion, $last['appVersion'], '>='),
			'appinfo/info.xml says ' . $appVersion . ', older than the last recorded register version '
			. $last['appVersion'] . '. Move <version> up to at least that.'
		);
	}//end testEachRecordedChangeMovedTheAppVersion()

	/**
	 * The guard sees the change that went unnoticed: a schema version moving in
	 * a fragment is reported, and an added schema is too.
	 *
	 * @return void
	 */
	public function testAMovedFragmentVersionAndANewSchemaAreSeen(): void {
		$dir = sys_get_temp_dir() . '/shillinq-regver-' . bin2hex(random_bytes(4));
		mkdir($dir . '/lib/Settings/register.d', 0777, true);
		file_put_contents(
			$dir . '/lib/Settings/shillinq_register.json',
			json_encode(['info' => ['version' => '0.7.0'], 'components' => ['schemas' => ['DunningLadder' => ['version' => '0.1.0']]]])
		);
		file_put_contents(
			$dir . '/lib/Settings/register.d/a.json',
			json_encode(['components' => ['schemas' => ['DunningLadder' => ['version' => '0.3.0'], 'Extra' => ['title' => 'x']]]])
		);
		file_put_contents(
			$dir . '/lib/Settings/register.d/b.json',
			json_encode(['components' => ['schemas' => ['DunningLadder' => ['title' => 'overlay without a version']]]])
		);

		try {
			$map = self::versionMap($dir);
		} finally {
			unlink($dir . '/lib/Settings/register.d/a.json');
			unlink($dir . '/lib/Settings/register.d/b.json');
			rmdir($dir . '/lib/Settings/register.d');
			unlink($dir . '/lib/Settings/shillinq_register.json');
			rmdir($dir . '/lib/Settings');
			rmdir($dir . '/lib');
			rmdir($dir);
		}

		$this->assertSame(
			['info' => '0.7.0', 'schema:DunningLadder' => '0.3.0', 'schema:Extra' => ''],
			$map
		);
	}//end testAMovedFragmentVersionAndANewSchemaAreSeen()

	/**
	 * Build the version map the importer sees: the base register first, then
	 * every fragment in the order SettingsService merges them (sorted file
	 * names); a later version overwrites an earlier one, an overlay without a
	 * version keeps it.
	 *
	 * @param string $root Repository root.
	 *
	 * @return array<string,string> Key (`info`, `register:<key>`, `schema:<key>`) to version.
	 */
	public static function versionMap(string $root): array {
		$files = glob($root . '/lib/Settings/register.d/*.json');
		sort($files);
		array_unshift($files, $root . '/lib/Settings/shillinq_register.json');

		$map = [];
		foreach ($files as $file) {
			$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			if (isset($data['info']['version']) === true) {
				$map['info'] = (string) $data['info']['version'];
			}

			foreach (['registers' => 'register:', 'schemas' => 'schema:'] as $section => $prefix) {
				foreach (($data['components'][$section] ?? []) as $key => $definition) {
					$version = (is_array($definition) === true && isset($definition['version']) === true) ? (string) $definition['version'] : null;
					if ($version !== null) {
						$map[$prefix . $key] = $version;
						continue;
					}

					$map[$prefix . $key] = ($map[$prefix . $key] ?? '');
				}
			}
		}//end foreach

		ksort($map);
		return $map;
	}//end versionMap()

	/**
	 * Stable hash of a version map.
	 *
	 * @param array<string,string> $versions The map.
	 *
	 * @return string
	 */
	private static function hashVersions(array $versions): string {
		ksort($versions);
		return hash('sha256', (string) json_encode($versions, JSON_UNESCAPED_SLASHES));
	}//end hashVersions()

	/**
	 * Read the lock.
	 *
	 * @return array{versions: array<string,string>, recorded: list<array{appVersion: string, versionsSha256: string}>}
	 */
	private function readLock(): array {
		$path = $this->root . '/' . self::LOCK;
		$this->assertFileExists($path);
		return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end readLock()

	/**
	 * The `<version>` in appinfo/info.xml.
	 *
	 * @return string
	 */
	private function appVersion(): string {
		$xml = simplexml_load_file($this->root . '/appinfo/info.xml');
		$this->assertNotFalse($xml);
		return trim((string) $xml->version);
	}//end appVersion()
}//end class
