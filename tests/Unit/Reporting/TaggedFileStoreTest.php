<?php

/**
 * Unit tests for TaggedFileStore: folder creation, collision-free names and best-effort tagging.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\SystemTagAssigner;
use OCA\Shillinq\Reporting\TaggedFileStore;
use OCA\Shillinq\Reporting\TaggedFileStoreLog;
use OCA\Shillinq\Reporting\TaggedFileStoreMessages;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Reporting\TaggedFileStore
 * @uses   \OCA\Shillinq\Reporting\SystemTagAssigner
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreLog
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreMessages
 */
final class TaggedFileStoreTest extends TestCase {

	/** @var ISystemTagManager&MockObject Tag lookup and creation. */
	private ISystemTagManager&MockObject $tagManager;

	/** @var ISystemTagObjectMapper&MockObject Tag-to-file assignment. */
	private ISystemTagObjectMapper&MockObject $tagMapper;

	/** @var LoggerInterface&MockObject Receives the store warnings. */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper  = $this->createMock(ISystemTagObjectMapper::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}

	private function store(): TaggedFileStore {
		return new TaggedFileStore(
			tags: new SystemTagAssigner(tagManager: $this->tagManager, tagMapper: $this->tagMapper),
			log: new TaggedFileStoreLog(
				logger: $this->logger,
				messages: new TaggedFileStoreMessages(notFolder: 'Not a folder: {segment}', tagFailed: 'Tag failed: {tag}'),
			),
		);
	}

	private function tag(string $id): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		return $tag;
	}

	public function testEnsureFolderCreatesMissingSegmentsAndReusesExistingOnes(): void {
		$leaf     = $this->createMock(Folder::class);
		$existing = $this->createMock(Folder::class);
		$existing->method('nodeExists')->with('2026')->willReturn(false);
		$existing->expects(self::once())->method('newFolder')->with('2026')->willReturn($leaf);
		$existing->expects(self::never())->method('get');

		$base = $this->createMock(Folder::class);
		$base->method('nodeExists')->with('Reports')->willReturn(true);
		$base->expects(self::once())->method('get')->with('Reports')->willReturn($existing);
		$base->expects(self::never())->method('newFolder');

		$this->logger->expects(self::never())->method('warning');

		self::assertSame($leaf, $this->store()->ensureFolder($base, ['Reports', '2026']));
	}

	public function testEnsureFolderWithNoSegmentsReturnsTheBase(): void {
		$base = $this->createMock(Folder::class);
		$base->expects(self::never())->method('nodeExists');

		self::assertSame($base, $this->store()->ensureFolder($base, []));
	}

	public function testEnsureFolderStopsAndWarnsWhenASegmentIsAFile(): void {
		$base = $this->createMock(Folder::class);
		$base->method('nodeExists')->with('Reports')->willReturn(true);
		$base->method('get')->with('Reports')->willReturn($this->createMock(File::class));
		$base->expects(self::never())->method('newFolder');

		$this->logger->expects(self::once())->method('warning')
			->with('Not a folder: {segment}', ['segment' => 'Reports']);

		self::assertNull($this->store()->ensureFolder($base, ['Reports', '2026']));
	}

	public function testUniqueNameKeepsAFreeName(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);

		self::assertSame('report.pdf', $this->store()->uniqueName($folder, 'report.pdf'));
	}

	public function testUniqueNameNumbersBeforeTheLastExtension(): void {
		$taken  = ['report.v2.pdf', 'report.v2-1.pdf', 'report.v2-2.pdf'];
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, $taken, true));

		self::assertSame('report.v2-3.pdf', $this->store()->uniqueName($folder, 'report.v2.pdf'));
	}

	public function testUniqueNameAppendsTheNumberWhenThereIsNoExtension(): void {
		$taken  = ['export', 'export-1'];
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, $taken, true));

		self::assertSame('export-2', $this->store()->uniqueName($folder, 'export'));
	}

	public function testUniqueNameGivesUpAfterNineHundredNinetyNineCandidates(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::exactly(1000))->method('nodeExists')->willReturn(true);

		self::assertSame('a-999.csv', $this->store()->uniqueName($folder, 'a.csv'));
	}

	public function testApplyTagsTrimsNamesSkipsBlanksAndAssignsEachToTheFile(): void {
		$this->tagManager->method('getTag')->willReturnMap([
			['Finance', true, true, $this->tag('7')],
			['Q1', true, true, $this->tag('9')],
		]);

		$assigned = [];
		$this->tagMapper->expects(self::exactly(2))->method('assignTags')
			->willReturnCallback(function (string $objId, string $type, array $tagIds) use (&$assigned): void {
				$assigned[] = [$objId, $type, $tagIds];
			});

		$this->store()->applyTags(42, [' Finance ', '', '   ', 'Q1']);

		self::assertSame([['42', 'files', ['7']], ['42', 'files', ['9']]], $assigned);
	}

	public function testApplyTagsLogsAFailedTagAndCarriesOnWithTheRest(): void {
		$this->tagManager->method('getTag')->willReturnCallback(
			function (string $name): ISystemTag {
				if ($name === 'Broken') {
					throw new RuntimeException('database gone');
				}
				return $this->tag('3');
			}
		);
		$this->tagMapper->expects(self::once())->method('assignTags')->with('5', 'files', ['3']);

		$this->logger->expects(self::once())->method('warning')->with(
			'Tag failed: {tag}',
			['tag' => 'Broken', 'fileId' => 5, 'exception' => 'database gone']
		);

		$this->store()->applyTags(5, ['Broken', 'Fine']);
	}

	public function testApplyTagsCreatesATagThatDoesNotExistYet(): void {
		$this->tagManager->method('getTag')->willThrowException(new TagNotFoundException('missing'));
		$this->tagManager->expects(self::once())->method('createTag')->with('New', true, true)->willReturn($this->tag('11'));
		$this->tagMapper->expects(self::once())->method('assignTags')->with('8', 'files', ['11']);
		$this->logger->expects(self::never())->method('warning');

		$this->store()->applyTags(8, ['New']);
	}
}
