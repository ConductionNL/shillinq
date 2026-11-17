<?php

/**
 * Reads shillinq's user guide into help articles.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Help
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Help;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Turns the markdown pages under the user guide directory into HelpArticle
 * rows, each with the route the documentation site publishes it at and the
 * ids of the app pages whose documentation link points there.
 *
 * Routes follow Docusaurus with `docs.path: './'`: the path below the docs
 * directory without its extension, number prefixes (`05-`) dropped from each
 * segment, `index` / `README` standing for their folder, and a front matter
 * `slug` replacing the route.
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 */
class HelpArticleSource {

	/**
	 * The documentation site the routes are published on.
	 *
	 * @var string
	 */
	public const SITE = 'https://shillinq.conduction.nl';

	/**
	 * The documentation site's default language.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE = 'en';

	/**
	 * The user guide below the docs directory.
	 *
	 * @var string
	 */
	public const GUIDE = 'user-guide';

	/**
	 * The help articles of the user guide, ordered by source path.
	 *
	 * @param string                           $appRoot The app's root directory (holds docs/).
	 * @param array<int, array<string, mixed>> $pages   The manifest pages.
	 *
	 * @return array<int, array<string, mixed>> The articles; none when the guide is not there.
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
	 */
	public function articles(string $appRoot, array $pages): array {
		$guide = rtrim($appRoot, '/') . '/docs/' . self::GUIDE;
		if (is_dir($guide) === false) {
			return [];
		}

		$pageIds = $this->pageIdsByUrl(pages: $pages);
		$articles = [];
		foreach ($this->markdownFiles(directory: $guide) as $relative => $content) {
			$articles[] = $this->article(relative: $relative, content: $content, pageIds: $pageIds);
		}

		return $articles;
	}//end articles()

	/**
	 * One article from a markdown file.
	 *
	 * @param string                       $relative The path below docs/, e.g. user-guide/inventory/overview.md.
	 * @param string                       $content  The file's content.
	 * @param array<string, array<string>> $pageIds  Page ids by documentation URL.
	 *
	 * @return array<string, mixed> The article.
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
	 */
	public function article(string $relative, string $content, array $pageIds): array {
		[$meta, $body] = self::frontMatter(content: $content);
		$route = self::route(relative: $relative, slug: ($meta['slug'] ?? ''));
		$url = self::SITE . $route;
		$language = ($meta['language'] ?? '');
		if ($language === '') {
			$language = self::DEFAULT_LANGUAGE;
		}

		return [
			'slug' => trim(str_replace('/', '-', $route), '-'),
			'title' => self::title(meta: $meta, body: $body, relative: $relative),
			'body' => trim($body) . "\n",
			'documentationUrl' => $url,
			'pageIds' => ($pageIds[$url] ?? []),
			'language' => $language,
			'sourcePath' => 'docs/' . $relative,
			'sourceHash' => hash('sha256', $content),
		];
	}//end article()

	/**
	 * The ids of the manifest pages per documentation URL (no trailing slash or fragment).
	 *
	 * @param array<int, array<string, mixed>> $pages The manifest pages.
	 *
	 * @return array<string, array<int, string>> Page ids by URL, in manifest order.
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
	 */
	public function pageIdsByUrl(array $pages): array {
		$byUrl = [];
		foreach ($pages as $page) {
			$id = (string)($page['id'] ?? '');
			$config = ($page['config'] ?? []);
			$url = '';
			if (is_array($config) === true) {
				$url = self::normaliseUrl(url: (string)($config['documentationUrl'] ?? ''));
			}

			if ($id === '' || $url === '') {
				continue;
			}

			$byUrl[$url][] = $id;
		}

		return $byUrl;
	}//end pageIdsByUrl()

	/**
	 * The markdown files below a directory, by path relative to its parent docs directory, sorted.
	 *
	 * @param string $directory The user guide directory.
	 *
	 * @return array<string, string> Content by relative path.
	 */
	private function markdownFiles(string $directory): array {
		$files = [];
		$base = dirname($directory) . '/';
		$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($walk as $file) {
			$isMarkdown = in_array(strtolower($file->getExtension()), ['md', 'mdx'], true);
			if ($file instanceof SplFileInfo === false || $file->isFile() === false || $isMarkdown === false) {
				continue;
			}

			$content = file_get_contents($file->getPathname());
			if ($content !== false) {
				$files[substr($file->getPathname(), strlen($base))] = $content;
			}
		}

		ksort($files);

		return $files;
	}//end markdownFiles()

	/**
	 * The front matter fields (flat `key: value` lines) and the body after it.
	 *
	 * @param string $content The file content.
	 *
	 * @return array{0: array<string, string>, 1: string}
	 */
	private static function frontMatter(string $content): array {
		if (preg_match('/^---\R(.*?)\R---\R?/s', $content, $match) !== 1) {
			return [[], $content];
		}

		$meta = [];
		foreach (preg_split('/\R/', $match[1]) as $line) {
			if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $field) === 1) {
				$meta[$field[1]] = trim($field[2], " \t\"'");
			}
		}

		return [$meta, substr($content, strlen($match[0]))];
	}//end frontMatter()

	/**
	 * The published route of a file, starting with a slash.
	 *
	 * @param string $relative The path below docs/.
	 * @param string $slug     The front matter slug, empty when none.
	 *
	 * @return string The route.
	 */
	private static function route(string $relative, string $slug): string {
		$segments = explode('/', preg_replace('/\.mdx?$/i', '', $relative));
		$segments = array_map(static fn (string $segment): string => (string)preg_replace('/^\d+[-_]/', '', $segment), $segments);
		$last = array_pop($segments);
		if ($slug !== '') {
			if (str_starts_with($slug, '/') === true) {
				return '/' . trim($slug, '/');
			}

			$last = trim($slug, '/');
		}

		if ($slug === '' && in_array(strtolower($last), ['index', 'readme'], true) === true) {
			$last = '';
		}

		return '/' . trim(implode('/', array_merge($segments, [$last])), '/');
	}//end route()

	/**
	 * The title: the front matter title, else the first heading, else the file name.
	 *
	 * @param array<string, string> $meta     The front matter.
	 * @param string                $body     The body.
	 * @param string                $relative The path below docs/.
	 *
	 * @return string The title.
	 */
	private static function title(array $meta, string $body, string $relative): string {
		if (($meta['title'] ?? '') !== '') {
			return $meta['title'];
		}

		if (preg_match('/^#\s+(.+)$/m', $body, $heading) === 1) {
			return trim($heading[1]);
		}

		return basename($relative);
	}//end title()

	/**
	 * A documentation URL without fragment, query or trailing slash; empty when not on the site.
	 *
	 * @param string $url The URL.
	 *
	 * @return string The normalised URL.
	 */
	private static function normaliseUrl(string $url): string {
		$url = (string)preg_replace('/[#?].*$/', '', $url);
		if (str_starts_with($url, self::SITE . '/') === false) {
			return '';
		}

		return rtrim($url, '/');
	}//end normaliseUrl()
}//end class
