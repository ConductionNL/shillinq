<?php

/**
 * Unit tests for SbrFilingFileStore: where a filing's file is kept.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use OCA\Shillinq\Service\Sbr\SbrFilingFileStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SbrFilingFileStore::class)]
final class SbrFilingFileStoreTest extends TestCase {

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/**
	 * A folder whose named children exist (as given) or are created on demand.
	 */
	private function folder(array $children = [], ?Folder $created = null, ?File $newFile = null): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => isset($children[$name]));
		$folder->method('get')->willReturnCallback(static fn (string $name): mixed => $children[$name]);
		if ($created !== null) {
			$folder->method('newFolder')->willReturn($created);
		}

		if ($newFile !== null) {
			$folder->method('newFile')->willReturn($newFile);
		}

		return $folder;
	}

	public function testANewFilingIsWrittenUnderShillinqSbrAndTheAdministration(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$admin = $this->createMock(Folder::class);
		$admin->method('nodeExists')->with('OB-2026-Q3.xbrl')->willReturn(false);
		$admin->expects($this->once())->method('newFile')->with('OB-2026-Q3.xbrl', '<x/>')->willReturn($file);
		$sbr = $this->createMock(Folder::class);
		$sbr->method('nodeExists')->with('adm-1')->willReturn(false);
		$sbr->expects($this->once())->method('newFolder')->with('adm-1')->willReturn($admin);
		$home = $this->folder(['Shillinq' => $this->folder(['Sbr' => $sbr])]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('anna')->willReturn($home);

		$this->assertSame('nextcloud://files/42', (new SbrFilingFileStore($root, $this->session('anna')))->store('adm-1', 'OB-2026/Q3.xbrl', '<x/>'));
	}

	public function testAnExistingFileIsReplaced(): void {
		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('putContent')->with('<y/>');
		$file->method('getId')->willReturn(7);
		$sbr = $this->folder(['f.xbrl' => $file]);
		$home = $this->folder(['Shillinq' => $this->folder(['Sbr' => $sbr])]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$this->assertSame('nextcloud://files/7', (new SbrFilingFileStore($root, $this->session('anna')))->store('', 'f.xbrl', '<y/>'));
	}

	public function testAFileInThePlaceOfAFolderIsRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->folder(['Shillinq' => $this->createMock(File::class)]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Shillinq is not a folder; the SBR filing cannot be stored.');

		(new SbrFilingFileStore($root, $this->session('anna')))->store('adm-1', 'f.xbrl', '<x/>');
	}

	public function testAFolderInThePlaceOfTheFileIsRefused(): void {
		$sbr = $this->folder(['f.xbrl' => $this->createMock(Folder::class)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->folder(['Shillinq' => $this->folder(['Sbr' => $sbr])]));

		$this->expectExceptionMessage('f.xbrl is not a file; the SBR filing cannot be stored.');

		(new SbrFilingFileStore($root, $this->session('anna')))->store('', 'f.xbrl', '<x/>');
	}

	public function testWithoutASignedInUserNothingIsStored(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$this->expectExceptionMessage('The SBR filing can only be stored for a signed-in user.');

		(new SbrFilingFileStore($root, $this->session(null)))->store('adm-1', 'f.xbrl', '<x/>');
	}
}
