<?php

/**
 * Reading the user guide into help articles: routes, titles, page ids, hashes.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Help
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Help;

use OCA\Shillinq\Service\Help\HelpArticleSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HelpArticleSource over a temporary app root.
 */
#[CoversClass(HelpArticleSource::class)]
final class HelpArticleSourceTest extends TestCase {

	/**
	 * The temporary app root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Lay out a small user guide.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/shillinq-help-' . bin2hex(random_bytes(4));
		$files = [
			'docs/user-guide/bookkeeping/year-end.md' => "---\nsidebar_position: 3\ntitle: \"Periode afsluiten\"\nlanguage: nl\n---\n\n# Year end\n\nClose the period.\n",
			'docs/user-guide/admin/05-inventory-cogs-posting.md' => "---\ntitle: Configure inventory GL posting\n---\n# Configure\n",
			'docs/user-guide/bookkeeping/cbs-submissions/index.md' => "# CBS IV3 Submissions\n\nText.\n",
			'docs/user-guide/tax/vat.mdx' => "---\nslug: /guides/vat-return\n---\nNo heading here.\n",
			'docs/user-guide/tax/notes.txt' => 'not markdown',
			'docs/Technical/architecture.md' => "# Not the user guide\n",
		];
		foreach ($files as $path => $content) {
			@mkdir(dirname($this->root . '/' . $path), 0777, true);
			file_put_contents($this->root . '/' . $path, $content);
		}
	}//end setUp()

	/**
	 * Remove the temporary app root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
		parent::tearDown();
	}//end tearDown()

	/**
	 * Every markdown page of the user guide becomes an article at its published route.
	 *
	 * @return void
	 */
	public function testTheUserGuidePagesBecomeArticlesAtTheirRoutes(): void {
		$articles = (new HelpArticleSource())->articles($this->root, []);

		self::assertSame(
			[
				'docs/user-guide/admin/05-inventory-cogs-posting.md' => 'https://shillinq.conduction.nl/user-guide/admin/inventory-cogs-posting',
				'docs/user-guide/bookkeeping/cbs-submissions/index.md' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/cbs-submissions',
				'docs/user-guide/bookkeeping/year-end.md' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/year-end',
				'docs/user-guide/tax/vat.mdx' => 'https://shillinq.conduction.nl/guides/vat-return',
			],
			array_column($articles, 'documentationUrl', 'sourcePath')
		);
	}//end testTheUserGuidePagesBecomeArticlesAtTheirRoutes()

	/**
	 * An article carries its title, body without front matter, language, slug, hash and page ids.
	 *
	 * @return void
	 */
	public function testAnArticleCarriesItsFields(): void {
		$content = (string)file_get_contents($this->root . '/docs/user-guide/bookkeeping/year-end.md');
		$pages = [
			['id' => 'PeriodClose', 'config' => ['documentationUrl' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/year-end/']],
			['id' => 'PeriodCloseDetail', 'config' => ['documentationUrl' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/year-end#steps']],
			['id' => 'Elsewhere', 'config' => ['documentationUrl' => 'https://example.org/user-guide/bookkeeping/year-end']],
			['id' => 'NoLink', 'config' => []],
		];
		$source = new HelpArticleSource();

		$article = $source->article('user-guide/bookkeeping/year-end.md', $content, $source->pageIdsByUrl($pages));

		self::assertSame(
			[
				'slug' => 'user-guide-bookkeeping-year-end',
				'title' => 'Periode afsluiten',
				'body' => "# Year end\n\nClose the period.\n",
				'documentationUrl' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/year-end',
				'pageIds' => ['PeriodClose', 'PeriodCloseDetail'],
				'language' => 'nl',
				'sourcePath' => 'docs/user-guide/bookkeeping/year-end.md',
				'sourceHash' => hash('sha256', $content),
			],
			$article
		);
	}//end testAnArticleCarriesItsFields()

	/**
	 * Without a front matter title the first heading is the title, else the file name; the language defaults to en.
	 *
	 * @return void
	 */
	public function testTheTitleFallsBackToTheHeadingThenTheFileName(): void {
		$articles = array_column((new HelpArticleSource())->articles($this->root, []), null, 'sourcePath');

		self::assertSame('CBS IV3 Submissions', $articles['docs/user-guide/bookkeeping/cbs-submissions/index.md']['title']);
		self::assertSame('vat.mdx', $articles['docs/user-guide/tax/vat.mdx']['title']);
		self::assertSame('en', $articles['docs/user-guide/tax/vat.mdx']['language']);
		self::assertSame('guides-vat-return', $articles['docs/user-guide/tax/vat.mdx']['slug']);
	}//end testTheTitleFallsBackToTheHeadingThenTheFileName()

	/**
	 * An app root without the user guide yields no articles.
	 *
	 * @return void
	 */
	public function testNoGuideNoArticles(): void {
		self::assertSame([], (new HelpArticleSource())->articles($this->root . '/missing', []));
	}//end testNoGuideNoArticles()
}//end class
