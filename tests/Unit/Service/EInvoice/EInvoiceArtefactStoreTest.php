<?php

/**
 * Unit tests for EInvoiceArtefactStore: where the e-invoice files land, how an
 * earlier send of the same invoice is replaced, and what happens without a
 * user or with a path that is in the way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\Shillinq\Service\EInvoice\EInvoiceArtefactStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\EInvoice\EInvoiceArtefactStore
 */
final class EInvoiceArtefactStoreTest extends TestCase {

	private IRootFolder&MockObject $root;

	private IUserSession&MockObject $session;

	private LoggerInterface&MockObject $logger;

	/**
	 * Files written, as name => content.
	 *
	 * @var array<string,string>
	 */
	private array $written = [];

	/**
	 * Folders created, in order.
	 *
	 * @var array<int,string>
	 */
	private array $created = [];

	protected function setUp(): void {
		$this->root    = $this->createMock(IRootFolder::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	private function store(): EInvoiceArtefactStore {
		return new EInvoiceArtefactStore($this->root, $this->session, $this->logger);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	/**
	 * A folder with nothing in it that records what is created below it.
	 *
	 * @param string $path The folder's path.
	 */
	private function emptyFolder(string $path): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($path): Folder {
				$this->created[] = $path . '/' . $name;
				return $this->emptyFolder(path: $path . '/' . $name);
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, ?string $content) use ($path): File {
				$this->written[$path . '/' . $name] = (string)$content;
				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn($path . '/' . $name);
				return $file;
			}
		);
		return $folder;
	}

	public function testTheFilesLandInTheAdministrationsYearFolder(): void {
		$this->signIn(uid: 'jan');
		$this->root->expects(self::once())->method('getUserFolder')->with('jan')->willReturn($this->emptyFolder(path: '/jan/files'));

		$path = $this->store()->store('adm-1', ['invoiceNumber' => '2026-0420', 'invoiceDate' => '2025-12-31'], '%PDF-bytes', '<Invoice/>');

		self::assertSame('/jan/files/Shillinq/adm-1/e-invoices/2025/invoice-2026-0420.xml', $path);
		self::assertSame(
			['/jan/files/Shillinq', '/jan/files/Shillinq/adm-1', '/jan/files/Shillinq/adm-1/e-invoices', '/jan/files/Shillinq/adm-1/e-invoices/2025'],
			$this->created
		);
		self::assertSame(
			[
				'/jan/files/Shillinq/adm-1/e-invoices/2025/invoice-2026-0420.pdf' => '%PDF-bytes',
				'/jan/files/Shillinq/adm-1/e-invoices/2025/invoice-2026-0420.xml' => '<Invoice/>',
			],
			$this->written
		);
	}

	public function testUnsafeNamesAreMadeIntoOneSegmentAndAMissingDateUsesThisYear(): void {
		$this->signIn(uid: 'jan');
		$this->root->method('getUserFolder')->willReturn($this->emptyFolder(path: '/jan/files'));

		$path = $this->store()->store('../adm 1', ['invoiceNumber' => 'INV/2026 07'], 'pdf', 'xml');

		self::assertSame('/jan/files/Shillinq/adm-1/e-invoices/' . date('Y') . '/invoice-INV-2026-07.xml', $path);
	}

	public function testAnEarlierSendOfTheSameInvoiceIsReplaced(): void {
		$this->signIn(uid: 'jan');
		$xml = $this->createMock(File::class);
		$xml->expects(self::once())->method('putContent')->with('<Invoice v="2"/>');
		$xml->method('getPath')->willReturn('/jan/files/Shillinq/adm-1/e-invoices/2026/invoice-7.xml');
		$pdf = $this->createMock(File::class);
		$pdf->expects(self::once())->method('putContent')->with('%PDF-2');

		$year = $this->createMock(Folder::class);
		$year->method('nodeExists')->willReturn(true);
		$year->method('get')->willReturnMap([['invoice-7.pdf', $pdf], ['invoice-7.xml', $xml]]);
		$year->expects(self::never())->method('newFile');

		$folder = $year;
		foreach (['2026', 'e-invoices', 'adm-1', 'Shillinq'] as $name) {
			$parent = $this->createMock(Folder::class);
			$parent->method('nodeExists')->with($name)->willReturn(true);
			$parent->method('get')->with($name)->willReturn($folder);
			$parent->expects(self::never())->method('newFolder');
			$folder = $parent;
		}

		$this->root->method('getUserFolder')->willReturn($folder);

		self::assertSame(
			'/jan/files/Shillinq/adm-1/e-invoices/2026/invoice-7.xml',
			$this->store()->store('adm-1', ['invoiceNumber' => '7', 'invoiceDate' => '2026-03-01'], '%PDF-2', '<Invoice v="2"/>')
		);
	}

	public function testWithoutAUserNothingIsStored(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->root->expects(self::never())->method('getUserFolder');
		$this->logger->expects(self::once())->method('warning')
			->with('EInvoiceArtefactStore: no user session, the e-invoice files are not stored');

		self::assertNull($this->store()->store('adm-1', ['invoiceNumber' => '1'], 'pdf', 'xml'));
	}

	public function testAFileWhereAFolderBelongsStoresNothing(): void {
		$this->signIn(uid: 'jan');
		$base = $this->createMock(Folder::class);
		$base->method('nodeExists')->willReturn(true);
		$base->method('get')->willReturn($this->createMock(File::class));
		$base->expects(self::never())->method('newFile');
		$this->root->method('getUserFolder')->willReturn($base);
		$this->logger->expects(self::once())->method('warning')->with(
			'EInvoiceArtefactStore: failed to store the e-invoice files',
			['administrationId' => 'adm-1', 'invoiceNumber' => '1', 'exception' => 'Path segment "Shillinq" is not a folder']
		);

		self::assertNull($this->store()->store('adm-1', ['invoiceNumber' => '1'], 'pdf', 'xml'));
	}

	public function testAFolderWhereTheFileBelongsStoresNothing(): void {
		$this->signIn(uid: 'jan');
		$year = $this->createMock(Folder::class);
		$year->method('nodeExists')->willReturn(true);
		$year->method('get')->willReturn($this->createMock(Folder::class));
		$folder = $year;
		foreach (['2026', 'e-invoices', 'adm-1', 'Shillinq'] as $name) {
			$parent = $this->createMock(Folder::class);
			$parent->method('nodeExists')->willReturn(true);
			$parent->method('get')->willReturn($folder);
			$folder = $parent;
		}

		$this->root->method('getUserFolder')->willReturn($folder);
		$this->logger->expects(self::once())->method('warning')->with(
			'EInvoiceArtefactStore: failed to store the e-invoice files',
			self::callback(static fn (array $context): bool => $context['exception'] === '"invoice-1.pdf" exists and is not a file')
		);

		self::assertNull($this->store()->store('adm-1', ['invoiceNumber' => '1', 'invoiceDate' => '2026-01-01'], 'pdf', 'xml'));
	}
}
