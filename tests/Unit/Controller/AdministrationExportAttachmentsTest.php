<?php

/**
 * Unit tests for the attachment bundling of AdministrationExportController.
 *
 * A full export streams a ZIP of the XAF plus the administration's attached
 * documents. These tests pin which documents land in it, that an unreadable
 * attachment is skipped rather than failing the export, that an unreadable
 * document list yields an XAF-only bundle, and that a failing bundle is a
 * logged 500.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AdministrationExportController;
use OCA\Shillinq\Kernel\Contracts\XafAuditfileGeneratorInterface;
use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Tests the ZIP bundle of a full XAF export.
 */
final class AdministrationExportAttachmentsTest extends TestCase {

	/**
	 * The Document rows the object service answers with (or an exception to throw).
	 *
	 * @var mixed
	 */
	private mixed $documentRows = [];

	/**
	 * The filters the Document lookup was made with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $documentQueries = [];

	/**
	 * Files root mock.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger     = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller; the 'full' request flag is served as given.
	 *
	 * @param mixed $full The raw 'full' request value.
	 *
	 * @return AdministrationExportController
	 */
	private function controller(mixed $full): AdministrationExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'full' ? $full : $default)
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('exporter');
		$context->method('canAccess')->willReturn(true);

		$generator = $this->createMock(XafAuditfileGeneratorInterface::class);
		$generator->method('generate')->willReturn(
			new GeneratedFile(fileName: 'audit.xaf', mimeType: 'text/xml', format: 'xml', content: '<auditfile/>')
		);

		$test = $this;
		// phpcs:disable
		$objectService = new class($test) {
			public function __construct(private AdministrationExportAttachmentsTest $test) {
			}
			public function setRegister(string $_): self {
				return $this;
			}
			public function setSchema(string $_): self {
				return $this;
			}
			public function findAll(array $config = []): mixed {
				return $this->test->documentsFor($config);
			}
		};
		// phpcs:enable

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($generator, $objectService): mixed {
				if ($id === XafAuditfileGeneratorInterface::class) {
					return $generator;
				}

				return $objectService;
			}
		);

		return new AdministrationExportController($request, $context, $container, $this->rootFolder, $this->logger);

	}//end controller()

	/**
	 * Answer the Document lookup (called by the object-service double).
	 *
	 * @param array<string, mixed> $config The findAll() config.
	 *
	 * @return mixed The rows.
	 */
	public function documentsFor(array $config): mixed {
		$this->documentQueries[] = $config;
		if ($this->documentRows instanceof \Throwable) {
			throw $this->documentRows;
		}

		return $this->documentRows;

	}//end documentsFor()

	/**
	 * A Files node with a name and content.
	 *
	 * @param string $name    The file name.
	 * @param string $content The bytes.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, string $content): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;

	}//end file()

	/**
	 * The entries of a ZIP response, name => bytes.
	 *
	 * @param DataDownloadResponse $response The download.
	 *
	 * @return array<string, string>
	 */
	private function zipEntries(DataDownloadResponse $response): array {
		$tmp = (string)tempnam(sys_get_temp_dir(), 'xaf-test-');
		file_put_contents($tmp, $response->render());
		$zip = new ZipArchive();
		self::assertTrue($zip->open($tmp));
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($tmp);
		return $entries;

	}//end zipEntries()

	/**
	 * A full export bundles the XAF and every attached document with a numeric
	 * file id (an int, a digit string, or an entity serialising to one); rows
	 * without a usable id are left out, and the lookup is scoped to the
	 * administration.
	 *
	 * @return void
	 */
	public function testFullExportBundlesTheAttachedDocuments(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return ['fileId' => 30];
			}
		};
		$this->documentRows = [
			['fileId' => 10],
			['fileId' => '20'],
			$entity,
			['fileId' => 'abc'],
			['fileId' => 1.5],
			['title' => 'no file'],
			'scalar-row',
		];
		$this->rootFolder->method('getById')->willReturnCallback(
			fn (int $id): array => [$this->file('doc-' . $id . '.pdf', 'bytes-' . $id)]
		);

		$response = $this->controller('1')->exportXaf('ADM-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('application/zip', $response->getHeaders()['Content-Type']);
		self::assertStringContainsString('xaf-ADM-1.zip', $response->getHeaders()['Content-Disposition']);
		self::assertSame(
			[
				'audit.xaf'            => '<auditfile/>',
				'documents/doc-10.pdf' => 'bytes-10',
				'documents/doc-20.pdf' => 'bytes-20',
				'documents/doc-30.pdf' => 'bytes-30',
			],
			$this->zipEntries($response)
		);
		self::assertSame(['administrationId' => 'ADM-1'], $this->documentQueries[0]['filters']);

	}//end testFullExportBundlesTheAttachedDocuments()

	/**
	 * An attachment that cannot be read is logged and skipped; a missing node
	 * is skipped silently; the rest of the bundle is still produced.
	 *
	 * @return void
	 */
	public function testAnUnreadableAttachmentIsSkipped(): void {
		$this->documentRows = [['fileId' => 1], ['fileId' => 2], ['fileId' => 3]];
		$this->rootFolder->method('getById')->willReturnCallback(
			function (int $id): array {
				if ($id === 1) {
					throw new \RuntimeException('storage offline');
				}

				if ($id === 2) {
					return [];
				}

				return [$this->file('kept.pdf', 'kept')];
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'AdministrationExportController: skipped an unreadable attachment',
			['fileId' => 1, 'exception' => 'storage offline']
		);

		$response = $this->controller(true)->exportXaf('ADM-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(
			['audit.xaf' => '<auditfile/>', 'documents/kept.pdf' => 'kept'],
			$this->zipEntries($response)
		);

	}//end testAnUnreadableAttachmentIsSkipped()

	/**
	 * A document list that cannot be read, or is not a list, gives an
	 * XAF-only bundle.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentListGivesAnXafOnlyBundle(): void {
		$this->rootFolder->expects(self::never())->method('getById');

		foreach ([new \RuntimeException('register offline'), null] as $rows) {
			$this->documentRows = $rows;

			$response = $this->controller('yes')->exportXaf('ADM-1');

			self::assertInstanceOf(DataDownloadResponse::class, $response);
			self::assertSame(['audit.xaf' => '<auditfile/>'], $this->zipEntries($response));
		}

	}//end testAnUnreadableDocumentListGivesAnXafOnlyBundle()

	/**
	 * A boolean false 'full' flag streams the plain XAF, not a ZIP.
	 *
	 * @return void
	 */
	public function testABooleanFalseFlagStreamsThePlainXaf(): void {
		$response = $this->controller(false)->exportXaf('ADM-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('text/xml', $response->getHeaders()['Content-Type']);
		self::assertSame('<auditfile/>', $response->render());
		self::assertSame([], $this->documentQueries);

	}//end testABooleanFalseFlagStreamsThePlainXaf()

	/**
	 * A document row that fails while being read fails the bundle: a logged
	 * 500 that carries no exception text.
	 *
	 * @return void
	 */
	public function testAFailingBundleIsALogged500(): void {
		$this->documentRows = [
			new class implements \JsonSerializable {
				public function jsonSerialize(): mixed {
					throw new \RuntimeException('corrupt row');
				}
			},
		];
		$this->logger->expects(self::once())->method('error')->with(
			'AdministrationExportController: XAF ZIP bundling failed',
			['administrationId' => 'ADM-1', 'exception' => 'corrupt row']
		);

		$response = $this->controller('1')->exportXaf('ADM-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Export bundling failed'], $response->getData());

	}//end testAFailingBundleIsALogged500()
}//end class
