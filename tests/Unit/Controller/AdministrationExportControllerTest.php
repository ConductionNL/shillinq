<?php

/**
 * Unit tests for AdministrationExportController.
 *
 * Proves the streaming half of REQ-MA-011 / REQ-MA-007: the export route
 * resolves the `exportScope()` `xaf-3.2` descriptor to real bytes — a plain XAF
 * download, and a ZIP bundle (containing the XAF) for a full export — while
 * enforcing the same masked-404 membership guard as the rest of the
 * administration API (REQ-MA-001).
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
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AdministrationExportController;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Fake OpenRegister ObjectService (empty) for the export controller test.
 */
final class FakeXafObjectService {

	/**
	 * @param string $register Ignored.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * @param string $schema Ignored.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $config Ignored.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		return [];
	}//end findAll()
}//end class

/**
 * A minimal PSR container resolving only the OpenRegister ObjectService.
 */
final class FakeXafContainer implements ContainerInterface {

	/**
	 * @param FakeXafObjectService $objectService The fake ObjectService.
	 */
	public function __construct(
		private FakeXafObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * @param string $id Service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		if ($id === 'OCA\OpenRegister\Service\ObjectService') {
			return $this->objectService;
		}

		throw new \RuntimeException('Unknown service: ' . $id);
	}//end get()

	/**
	 * @param string $id Service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return $id === 'OCA\OpenRegister\Service\ObjectService';
	}//end has()
}//end class

/**
 * Tests the streaming XAF export route: bytes (and a ZIP for full export), not a descriptor.
 */
final class AdministrationExportControllerTest extends TestCase {

	/**
	 * Build a controller whose container yields a real XAF generator over an
	 * empty fixture (the byte-shape is asserted by the generator's own tests;
	 * here we only care that the route STREAMS the bytes).
	 *
	 * @param bool $canAccess Whether the membership guard allows the id.
	 * @param string $fullParam The 'full' request param value.
	 * @param string $userId The authenticated user id ('' = anonymous).
	 *
	 * @return AdministrationExportController
	 */
	private function controller(bool $canAccess, string $fullParam, string $userId = 'exporter'): AdministrationExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function ($key, $default = null) use ($fullParam) {
				if ($key === 'full') {
					return $fullParam;
				}

				return $default;
			}
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId === '' ? null : $userId);
		$context->method('canAccess')->willReturn($canAccess);

		// A container that yields a real XAF generator (over an empty ObjectService)
		// and the OpenRegister ObjectService lookup used for attachment discovery.
		$emptyObjectService = new FakeXafObjectService([]);
		$generator = new XafAuditfileGenerator(
			new FakeXafContainer($emptyObjectService),
			new NullLogger()
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function ($id) use ($generator, $emptyObjectService) {
				if ($id === XafAuditfileGenerator::class) {
					return $generator;
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $emptyObjectService;
				}

				throw new \RuntimeException('Unknown service: ' . $id);
			}
		);

		return new AdministrationExportController(
			$request,
			$context,
			$container,
			$this->createMock(IRootFolder::class),
			new NullLogger()
		);
	}//end controller()

	/**
	 * A plain export streams the XAF bytes as an XML download (not a descriptor).
	 *
	 * @return void
	 */
	public function testPlainExportStreamsXafBytes(): void {
		$response = $this->controller(true, '')->exportXaf('WERK-001');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->render();
		$this->assertStringContainsString('<auditfile', $body);
		$this->assertStringContainsString('http://www.auditfiles.nl/XAF/3.2', $body);
	}//end testPlainExportStreamsXafBytes()

	/**
	 * A full export streams a ZIP bundle that contains the XAF file.
	 *
	 * @return void
	 */
	public function testFullExportStreamsZipContainingXaf(): void {
		if (class_exists(\ZipArchive::class) === false) {
			$this->markTestSkipped('ext-zip not available in this runtime');
		}

		$response = $this->controller(true, '1')->exportXaf('WERK-001');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// The body is a real ZIP — open it and assert it contains an XAF entry.
		$tmp = tempnam(sys_get_temp_dir(), 'xaf-zip-test-');
		file_put_contents($tmp, $response->render());

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp) === true, 'Full export is not a valid ZIP');

		$found = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_contains($name, 'xaf') === true && str_ends_with($name, '.xml') === true) {
				$entry = (string)$zip->getFromIndex($i);
				$this->assertStringContainsString('http://www.auditfiles.nl/XAF/3.2', $entry);
				$found = true;
			}
		}

		$zip->close();
		@unlink($tmp);
		$this->assertTrue($found, 'ZIP bundle does not contain an XAF file');
	}//end testFullExportStreamsZipContainingXaf()

	/**
	 * A non-member is masked as 404 (never 403) — REQ-MA-001.
	 *
	 * @return void
	 */
	public function testForbiddenAdministrationMasked404(): void {
		$response = $this->controller(false, '')->exportXaf('WERK-secret');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testForbiddenAdministrationMasked404()

	/**
	 * An anonymous request is rejected with 401.
	 *
	 * @return void
	 */
	public function testAnonymousRejected(): void {
		$response = $this->controller(true, '', '')->exportXaf('WERK-001');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousRejected()

	/**
	 * A malformed administration id is rejected with 400.
	 *
	 * @return void
	 */
	public function testMalformedIdRejected(): void {
		$response = $this->controller(true, '')->exportXaf('not a valid id!');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testMalformedIdRejected()
}//end class
