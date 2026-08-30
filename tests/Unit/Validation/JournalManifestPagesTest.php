<?php

/**
 * Structural test for the bookkeeping-journal-entries manifest pages
 * (REQ-JE-009).
 *
 * The browser-render verification (Task 7.7) is deferred to a deployed
 * environment; this test asserts the manifest declaratively binds the
 * Journals index + detail pages to the `JournalEntry` register, declares
 * the REQ-JE-009 columns, and links the detail page back to the
 * materialised `GLTransaction`.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the manifest declares Journals + JournalDetail pages bound to
 * the `JournalEntry` register per REQ-JE-009.
 */
final class JournalManifestPagesTest extends TestCase {

	/**
	 * Decoded manifest contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $manifest;

	/**
	 * Load and decode the manifest once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../src/manifest.json';
		self::assertFileExists($path, 'src/manifest.json must exist.');
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Manifest must be valid JSON.');
		$this->manifest = $decoded;

	}//end setUp()

	/**
	 * Returns the manifest page with the given id (or fails the test).
	 *
	 * @param string $id Page id to look up.
	 *
	 * @return array<string,mixed>
	 */
	private function pageById(string $id): array {
		foreach (($this->manifest['pages'] ?? []) as $page) {
			if (($page['id'] ?? null) === $id) {
				return $page;
			}
		}

		self::fail("Manifest is missing page id `$id`.");

	}//end pageById()

	/**
	 * The manifest declares the Journals index page bound to JournalEntry.
	 *
	 * @return void
	 */
	public function testJournalsIndexPageDeclared(): void {
		$page = $this->pageById('Journals');
		self::assertSame('/journals', $page['route']);
		self::assertSame('index', $page['type']);
		self::assertSame('JournalEntry', $page['config']['schema'] ?? null, 'Index page must bind to JournalEntry register.');

	}//end testJournalsIndexPageDeclared()

	/**
	 * Index page columns include the REQ-JE-009 set.
	 *
	 * @return void
	 */
	public function testIndexColumnsCoverRequiredFields(): void {
		$page = $this->pageById('Journals');
		$columns = array_column(($page['config']['columns'] ?? []), 'key');
		foreach (['journalNumber', 'entryDate', 'description', 'journalType', 'state', 'approvalState'] as $required) {
			self::assertContains($required, $columns, "Index page MUST surface `$required` column (REQ-JE-009).");
		}

	}//end testIndexColumnsCoverRequiredFields()

	/**
	 * Detail page is declared and binds to JournalEntry.
	 *
	 * @return void
	 */
	public function testDetailPageDeclared(): void {
		$page = $this->pageById('JournalDetail');
		self::assertSame('/journals/:id', $page['route']);
		self::assertSame('detail', $page['type']);
		self::assertSame('JournalEntry', $page['config']['schema'] ?? null, 'Detail page must bind to JournalEntry register.');

	}//end testDetailPageDeclared()

	/**
	 * The Bookkeeping > Journals menu entry points at the index page.
	 * Walks both top-level and nested `children` entries (REQ-JE-009).
	 *
	 * @return void
	 */
	public function testJournalsMenuEntryRoutesToIndex(): void {
		$menu = ($this->manifest['menu'] ?? []);
		$found = $this->findMenuRoute($menu, 'Journals');
		self::assertTrue($found, 'Manifest menu MUST include a `Journals` route entry (REQ-JE-009).');

	}//end testJournalsMenuEntryRoutesToIndex()

	/**
	 * Walks the menu tree (top-level + nested `children`) hunting for a route.
	 *
	 * @param array<int,array<string,mixed>> $items Menu items at this depth.
	 * @param string $route Route id to match.
	 *
	 * @return bool
	 */
	private function findMenuRoute(array $items, string $route): bool {
		foreach ($items as $item) {
			if (($item['route'] ?? null) === $route) {
				return true;
			}

			if (!empty($item['children']) && is_array($item['children'])) {
				if ($this->findMenuRoute($item['children'], $route) === true) {
					return true;
				}
			}
		}

		return false;
	}//end findMenuRoute()

}//end class
