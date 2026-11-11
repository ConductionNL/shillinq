<?php

/**
 * Golden tests for AdministrationExportController::exportXaf.
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
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the answers, the attachment discovery and the log lines of the XAF
 * export before exportXaf() and bundleZip() are split into named steps.
 */
final class AdministrationExportGoldenTest extends TestCase {
	/**
	 * Logged lines.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params    Request params.
	 * @param mixed                $documents Document rows the store answers, or a Throwable it throws.
	 * @param array<string, mixed> $options   access (bool|Throwable), generator (bool), files (id => node|Throwable).
	 *
	 * @return AdministrationExportController
	 */
	private function controller(array $params, mixed $documents = [], array $options = []): AdministrationExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('exporter');
		$access = ($options['access'] ?? true);
		if ($access instanceof \Throwable) {
			$context->method('canAccess')->willThrowException($access);
		} else {
			$context->method('canAccess')->willReturn($access);
		}

		$store = new class($documents) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param mixed $documents The Document rows or a Throwable.
			 */
			public function __construct(
				private mixed $documents,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = []): array {
				if ($this->schema !== 'Document') {
					return [];
				}

				if ($this->documents instanceof \Throwable) {
					throw $this->documents;
				}

				return $this->documents;
			}//end findAll()
		};
		$generatorContainer = $this->createMock(ContainerInterface::class);
		$generatorContainer->method('get')->willReturn($store);
		$generator = new XafAuditfileGenerator($generatorContainer, new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === XafAuditfileGenerator::class ? (($options['generator'] ?? true) === true ? $generator : new \stdClass()) : $store)
		);

		$files = ($options['files'] ?? []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(
			static function (int $fileId) use ($files): array {
				$node = ($files[$fileId] ?? null);
				if ($node instanceof \Throwable) {
					throw $node;
				}

				return $node === null ? [] : [$node];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		return new AdministrationExportController($request, $context, $container, $root, $logger);
	}//end controller()

	/**
	 * A file node with a name and content.
	 *
	 * @param string $name    The name.
	 * @param string $content The content.
	 *
	 * @return File
	 */
	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}//end file()

	/**
	 * The entries of a ZIP body, name => content.
	 *
	 * @param string $bytes The ZIP bytes.
	 *
	 * @return array<string, string>
	 */
	private static function entries(string $bytes): array {
		$tmp = (string)tempnam(sys_get_temp_dir(), 'xaf-golden-');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		$zip->open($tmp);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entries[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		unlink($tmp);
		return $entries;
	}//end entries()

	/**
	 * A failing access check and a missing generator are logged 500s.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$failed = $this->controller([], [], ['access' => new \RuntimeException('db')])->exportXaf('WERK-001');
		$this->assertSame([500, ['error' => 'Failed to resolve export']], [$failed->getStatus(), $failed->getData()]);

		$missing = $this->controller([], [], ['generator' => false])->exportXaf(' WERK-001 ');
		$this->assertSame([500, ['error' => 'Export generation failed']], [$missing->getStatus(), $missing->getData()]);

		$this->assertSame(
			[
				'error:AdministrationExportController: failed to check export access{"exception":"db"}',
				'error:AdministrationExportController: XAF generation failed{"administrationId":"WERK-001","exception":"XAF generator unavailable"}',
			],
			$this->logged
		);
	}//end testFailures()

	/**
	 * Without a truthy "full" the XAF itself is downloaded.
	 *
	 * @return void
	 */
	public function testPlainDownload(): void {
		foreach (['', 'no', '0'] as $full) {
			$response = $this->controller(['full' => $full, 'period' => '2026'])->exportXaf('WERK-001');
			$this->assertInstanceOf(DataDownloadResponse::class, $response);
			$this->assertStringContainsString('http://www.auditfiles.nl/XAF/3.2', $response->render());
			$this->assertSame('application/xml', explode(';', $response->getHeaders()['Content-Type'])[0]);
		}
	}//end testPlainDownload()

	/**
	 * A truthy "full" bundles the XAF with the readable attachments; an unreadable one is logged and skipped.
	 *
	 * @return void
	 */
	public function testFullBundle(): void {
		if (class_exists(\ZipArchive::class) === false) {
			$this->markTestSkipped('ext-zip not available in this runtime');
		}

		$serialisable = new class implements \JsonSerializable {
			/**
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['fileId' => '8'];
			}//end jsonSerialize()
		};
		$documents = [['fileId' => 5], ['fileId' => '6'], ['fileId' => 'x'], ['fileId' => 7.0], $serialisable, 'junk', ['fileId' => 9]];
		$files = [5 => $this->file(name: 'a.pdf', content: 'AAA'), 6 => new \RuntimeException('gone'), 9 => $this->file(name: 'b.pdf', content: 'BBB')];

		foreach (['1', ' TRUE ', 'yes', 'on'] as $full) {
			$this->logged = [];
			$response = $this->controller(['full' => $full], $documents, ['files' => $files])->exportXaf('WERK-001');

			$this->assertSame('application/zip', explode(';', $response->getHeaders()['Content-Type'])[0]);
			$this->assertStringContainsString('xaf-WERK-001.zip', $response->getHeaders()['Content-Disposition']);
			$entries = self::entries(bytes: $response->render());
			$this->assertSame(['documents/a.pdf' => 'AAA', 'documents/b.pdf' => 'BBB'], array_slice($entries, 1, null, true));
			$this->assertStringContainsString('http://www.auditfiles.nl/XAF/3.2', (string)reset($entries));
			$this->assertSame(['warning:AdministrationExportController: skipped an unreadable attachment{"fileId":6,"exception":"gone"}'], $this->logged);
		}

		$response = $this->controller(['full' => '1'], new \RuntimeException('down'))->exportXaf('WERK-001');
		$this->assertCount(1, self::entries(bytes: $response->render()));
	}//end testFullBundle()
}//end class
