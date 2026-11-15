<?php

/**
 * Unit tests for ImportSourceReader: which source file it picks, whose Files
 * it reads, by id or by path, and how an unreadable file is refused.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use DomainException;
use OCA\Shillinq\Service\Import\ImportSourceReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reader over a root folder and user session double.
 */
#[CoversClass(ImportSourceReader::class)]
final class ImportSourceReaderTest extends TestCase {
	/**
	 * The root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * The user session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The user folder double.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $folder;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->folder = $this->createMock(Folder::class);

	}//end setUp()

	/**
	 * The reader.
	 *
	 * @return ImportSourceReader
	 */
	private function reader(): ImportSourceReader {
		return new ImportSourceReader($this->rootFolder, $this->userSession);

	}//end reader()

	/**
	 * A file double.
	 *
	 * @param string $content  Its content.
	 * @param bool   $readable Whether it is readable.
	 *
	 * @return File&MockObject
	 */
	private function file(string $content, bool $readable = true): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getContent')->willReturn($content);
		return $file;

	}//end file()

	/**
	 * The first xaf entry with a path is read from the batch owner's Files by its path.
	 *
	 * @return void
	 */
	public function testReadsTheFirstAuditfileFromTheOwnersFilesByPath(): void {
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('anna')->willReturn($this->folder);
		$this->folder->expects($this->once())->method('get')->with('/Import/2025.xaf')->willReturn($this->file('<auditfile/>'));
		$this->folder->expects($this->never())->method('getById');
		$this->userSession->expects($this->never())->method('getUser');

		$content = $this->reader()->readAuditfile(
			[
				'owner' => 'anna',
				'sourceFiles' => [
					'not an entry',
					['kind' => 'csv', 'path' => '/Import/relations.csv'],
					['kind' => 'xaf', 'path' => ''],
					['path' => '/Import/2025.xaf'],
					['kind' => 'xaf', 'path' => '/Import/2024.xaf'],
				],
			]
		);

		$this->assertSame('<auditfile/>', $content);

	}//end testReadsTheFirstAuditfileFromTheOwnersFilesByPath()

	/**
	 * Without an owner the current user's Files are read, and a file id wins over the path.
	 *
	 * @return void
	 */
	public function testFallsBackToTheCurrentUserAndReadsByFileId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bert');
		$this->userSession->method('getUser')->willReturn($user);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('bert')->willReturn($this->folder);
		$this->folder->expects($this->once())->method('getById')->with(42)->willReturn([$this->file('by id')]);
		$this->folder->expects($this->never())->method('get');

		$content = $this->reader()->readAuditfile(['sourceFiles' => [['kind' => 'xaf', 'path' => '/a.xaf', 'fileId' => '42']]]);

		$this->assertSame('by id', $content);

	}//end testFallsBackToTheCurrentUserAndReadsByFileId()

	/**
	 * A file id that resolves to nothing is refused, naming the path.
	 *
	 * @return void
	 */
	public function testAFileIdThatFindsNothingIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('')->willReturn($this->folder);
		$this->folder->method('getById')->willReturn([]);

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The file /gone.xaf cannot be read.');
		$this->reader()->readAuditfile(['sourceFiles' => [['path' => '/gone.xaf', 'fileId' => '7']]]);

	}//end testAFileIdThatFindsNothingIsRefused()

	/**
	 * An unreadable file is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsRefused(): void {
		$this->rootFolder->method('getUserFolder')->willReturn($this->folder);
		$this->folder->method('get')->willReturn($this->file('secret', false));

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The file /locked.xaf cannot be read.');
		$this->reader()->readAuditfile(['owner' => 'anna', 'sourceFiles' => [['path' => '/locked.xaf']]]);

	}//end testAnUnreadableFileIsRefused()

	/**
	 * A lookup failure is refused with the failure kept as the previous exception.
	 *
	 * @return void
	 */
	public function testALookupFailureIsRefusedKeepingTheCause(): void {
		$cause = new NotFoundException('no such file');
		$this->rootFolder->method('getUserFolder')->willReturn($this->folder);
		$this->folder->method('get')->willThrowException($cause);

		try {
			$this->reader()->readAuditfile(['owner' => 'anna', 'sourceFiles' => [['path' => '/missing.xaf']]]);
			$this->fail('readAuditfile did not refuse');
		} catch (DomainException $e) {
			$this->assertSame('The file /missing.xaf cannot be read.', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}

	}//end testALookupFailureIsRefusedKeepingTheCause()

	/**
	 * A batch without an auditfile entry is refused before any file is touched.
	 *
	 * @return void
	 */
	public function testABatchWithoutAnAuditfileIsRefused(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Choose the auditfile (XAF) to import.');
		$this->reader()->readAuditfile(['owner' => 'anna', 'sourceFiles' => [['kind' => 'csv', 'path' => '/r.csv']]]);

	}//end testABatchWithoutAnAuditfileIsRefused()
}//end class
