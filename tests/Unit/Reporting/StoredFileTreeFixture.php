<?php

/**
 * A user's Files tree, system tags and a warning log, built from mocks, for the
 * tests that pin how an export or a report is stored and tagged.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every folder and file created, every tag created and assigned, and every warning.
 */
final class StoredFileTreeFixture extends AbstractLogger {

	/**
	 * What happened, in order: "folder <path>", "file <path>", "create-tag <name>",
	 * "assign <fileId> <type> <tagId>" and "warning <message>".
	 *
	 * @var array<int, string>
	 */
	public array $events = [];

	/**
	 * Constructor.
	 *
	 * @param TestCase              $test    The test that builds the mocks.
	 * @param array<string, mixed>  $tree    The existing tree under the home folder: a name maps to
	 *                                       'file' or to the (array) tree of a folder.
	 * @param array<int, string>    $missing Tag names the tag manager does not know yet.
	 * @param array<int, string>    $failing Tag names whose assignment throws.
	 */
	public function __construct(
		private readonly TestCase $test,
		private readonly array $tree,
		private readonly array $missing=[],
		private readonly array $failing=[],
	) {

	}//end __construct()

	/**
	 * The root folder; every user's home is the tree.
	 *
	 * @return IRootFolder
	 */
	public function rootFolder(): IRootFolder {
		$root = $this->test->getMockBuilder(IRootFolder::class)->getMock();
		$root->method('getUserFolder')->willReturn($this->folder('/home', $this->tree));
		return $root;

	}//end rootFolder()

	/**
	 * The tag manager: a missing tag throws TagNotFoundException and is then created.
	 *
	 * @return ISystemTagManager
	 */
	public function tagManager(): ISystemTagManager {
		$manager = $this->test->getMockBuilder(ISystemTagManager::class)->getMock();
		$manager->method('getTag')->willReturnCallback(
			function (string $name, bool $visible, bool $assignable): ISystemTag {
				if (in_array($name, $this->missing, true) === true) {
					throw new TagNotFoundException('missing');
				}

				return $this->tag($name . ($visible && $assignable ? '' : ' (hidden)'));
			}
		);
		$manager->method('createTag')->willReturnCallback(
			function (string $name, bool $visible, bool $assignable): ISystemTag {
				$this->events[] = 'create-tag ' . $name . ($visible && $assignable ? '' : ' (hidden)');
				return $this->tag($name);
			}
		);
		return $manager;

	}//end tagManager()

	/**
	 * The tag mapper: records each assignment, throws for a failing tag.
	 *
	 * @return ISystemTagObjectMapper
	 */
	public function tagMapper(): ISystemTagObjectMapper {
		$mapper = $this->test->getMockBuilder(ISystemTagObjectMapper::class)->getMock();
		$mapper->method('assignTags')->willReturnCallback(
			function (string $objectId, string $type, array $tagIds): void {
				if (in_array($tagIds[0], $this->failing, true) === true) {
					throw new \RuntimeException('tag store down');
				}

				$this->events[] = 'assign ' . $objectId . ' ' . $type . ' ' . implode(',', $tagIds);
			}
		);
		return $mapper;

	}//end tagMapper()

	/**
	 * Record a log line; only warnings are expected.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array<mixed>       $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->events[] = $level . ' ' . $message;

	}//end log()

	/**
	 * A folder at a path holding a tree.
	 *
	 * @param string               $path The folder's path.
	 * @param array<string, mixed> $tree Its existing children.
	 *
	 * @return Folder
	 */
	private function folder(string $path, array $tree): Folder {
		$folder = $this->test->getMockBuilder(Folder::class)->getMock();
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => array_key_exists($name, $tree));
		$folder->method('get')->willReturnCallback(
			fn (string $name) => is_array($tree[$name]) ? $this->folder($path . '/' . $name, $tree[$name]) : $this->file($path . '/' . $name)
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($path): Folder {
				$this->events[] = 'folder ' . $path . '/' . $name;
				return $this->folder($path . '/' . $name, []);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content) use ($path): File {
				$this->events[] = 'file ' . $path . '/' . $name . ' (' . $content . ')';
				return $this->file($path . '/' . $name);
			}
		);
		return $folder;

	}//end folder()

	/**
	 * A file at a path; its id is 42.
	 *
	 * @param string $path The file's path.
	 *
	 * @return File
	 */
	private function file(string $path): File {
		$file = $this->test->getMockBuilder(File::class)->getMock();
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn(42);
		return $file;

	}//end file()

	/**
	 * A tag whose id is its name.
	 *
	 * @param string $name The name.
	 *
	 * @return ISystemTag
	 */
	private function tag(string $name): ISystemTag {
		$tag = $this->test->getMockBuilder(ISystemTag::class)->getMock();
		$tag->method('getId')->willReturn($name);
		return $tag;

	}//end tag()
}//end class
