<?php

/**
 * Seeds the user guide as help articles on install and upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
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

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Service\Help\HelpArticleSource;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts one HelpArticle per page of docs/user-guide, keyed by its source
 * path; a page whose file is unchanged (same sourceHash) is left untouched,
 * so an upgrade rewrites only the articles whose markdown changed
 * (REQ-PHA-001). Without the guide on disk it seeds nothing and says so.
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 */
class SeedHelpArticles implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * The help article schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'HelpArticle';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocation       $location      Where the register lives.
	 * @param ObjectServiceInterface $objectService Reads and writes the articles.
	 * @param LoggerInterface        $logger        Reports an article that could not be saved.
	 * @param HelpArticleSource      $source        Reads the guide into articles.
	 * @param string                 $appRoot       The app's root directory.
	 */
	public function __construct(
		private readonly RegisterLocation $location,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly HelpArticleSource $source = new HelpArticleSource(),
		private readonly string $appRoot = __DIR__ . '/../..',
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
	 */
	public function getName(): string {
		return 'Seed the user guide as help articles (platform-help-agent)';
	}//end getName()

	/**
	 * Seed the articles.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-help-agent/tasks.md#task-2.2
	 */
	public function run(IOutput $output): void {
		if ($this->location->isOpenRegisterAvailable() === false) {
			$output->info('Help articles: OpenRegister is not available; nothing seeded.');
			return;
		}

		$articles = $this->source->articles(appRoot: $this->appRoot, pages: $this->manifestPages());
		if ($articles === []) {
			$output->info('Help articles: no user guide under docs/' . HelpArticleSource::GUIDE . '; nothing seeded.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $this->objectService,
			work: function () use ($articles, $output): void {
				$output->info($this->seed(articles: $articles));
			}
		);
	}//end run()

	/**
	 * Upsert the articles and describe what happened.
	 *
	 * @param array<int, array<string, mixed>> $articles The articles from the guide.
	 *
	 * @return string The summary line.
	 */
	private function seed(array $articles): string {
		$register = $this->location->getRegisterSlug();
		$known = $this->knownArticles(register: $register);
		$counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

		foreach ($articles as $article) {
			$earlier = ($known[$article['sourcePath']] ?? null);
			if ($earlier !== null && $earlier['hash'] === $article['sourceHash']) {
				$counts['unchanged']++;
				continue;
			}

			try {
				$this->objectService->saveObject(
					object: $article,
					register: $register,
					schema: self::SCHEMA,
					uuid: ($earlier['uuid'] ?? null),
					_rbac: false,
					_multitenancy: false,
				);
				$outcome = 'updated';
				if ($earlier === null) {
					$outcome = 'created';
				}

				$counts[$outcome]++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->warning(
					'Help articles: could not save an article',
					['sourcePath' => $article['sourcePath'], 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return vsprintf('Help articles: %d created, %d updated, %d unchanged, %d failed.', array_values($counts));
	}//end seed()

	/**
	 * The stored articles by source path: their uuid and source hash.
	 *
	 * @param string $register The register slug.
	 *
	 * @return array<string, array{uuid: string, hash: string}>
	 */
	private function knownArticles(string $register): array {
		$known = [];
		foreach ($this->readAllRows(objectService: $this->objectService, registerSlug: $register, schema: self::SCHEMA) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			$path = (string)($record['sourcePath'] ?? '');
			$uuid = (string)($record['id'] ?? '');
			if ($path !== '' && $uuid !== '') {
				$known[$path] = ['uuid' => $uuid, 'hash' => (string)($record['sourceHash'] ?? '')];
			}
		}

		return $known;
	}//end knownArticles()

	/**
	 * The pages of the app manifest, for the page ids per documentation link.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function manifestPages(): array {
		$file = $this->appRoot . '/src/manifest.json';
		$manifest = null;
		if (is_file($file) === true) {
			$manifest = json_decode((string)file_get_contents($file), true);
		}

		if (is_array($manifest) === false || is_array($manifest['pages'] ?? null) === false) {
			return [];
		}

		return $manifest['pages'];
	}//end manifestPages()
}//end class
