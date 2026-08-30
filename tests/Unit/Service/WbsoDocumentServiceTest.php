<?php

/**
 * Unit tests for WbsoDocumentService.
 *
 * Covers REQ-WBSO-003 (schema), REQ-WBSO-007 (file lifecycle gate) and
 * REQ-WBSO-009 (seven-year retention boundary).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-33
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-33
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoDocumentServiceTest extends TestCase {

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * App-config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build service.
	 *
	 * @param array<int,array<string,mixed>> $docs Docs.
	 *
	 * @return WbsoDocumentService
	 */
	private function buildService(array $docs): WbsoDocumentService {
		$stub = new class($docs) {

			/**
			 * Backing.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $docs;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $docs Docs.
			 */
			public function __construct(array $docs) {
				$this->docs = $docs;
			}

			/**
			 * @param string $r Register.
			 *
			 * @return static
			 */
			public function setRegister(string $r): static {
				return $this;
			}

			/**
			 * @param string $s Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $s): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $p Params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $p = []): array {
				$filters = ($p['filters'] ?? []);
				if ($filters === []) {
					return $this->docs;
				}

				return array_values(array_filter(
					$this->docs,
					static function (array $row) use ($filters): bool {
						foreach ($filters as $k => $v) {
							if (($row[$k] ?? null) !== $v) {
								return false;
							}
						}
						return true;
					}
				));
			}

			/**
			 * Save.
			 *
			 * @param array<string,mixed> $object Object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === true) {
					foreach ($this->docs as $i => $row) {
						if (($row['id'] ?? null) === $object['id']) {
							$this->docs[$i] = $object;
							return $object;
						}
					}
				}

				$this->docs[] = $object;

				return $object;
			}
		};

		$this->container->method('get')->willReturn($stub);

		return new WbsoDocumentService(
			appConfig: $this->appConfig,
			userSession: $this->session,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Create defaults to draft.
	 *
	 * @return void
	 */
	public function testCreateDocumentIsDraft(): void {
		$service = $this->buildService([]);
		$row = $service->createDocument(
			administrationId: 'adm-1',
			payload: [
				'documentType' => 'invoice',
				'documentNumber' => 'DOC-1',
				'documentDate' => '2026-01-15',
			]
		);

		self::assertSame('draft', $row['status']);
		self::assertSame('bob', $row['createdBy']);

	}//end testCreateDocumentIsDraft()

	/**
	 * Filing requires fileReference.
	 *
	 * @return void
	 */
	public function testFileRejectsMissingFileReference(): void {
		$service = $this->buildService([
			[
				'id' => 'd-1',
				'documentNumber' => 'DOC-1',
				'documentType' => 'invoice',
				'documentDate' => '2026-01-15',
				'status' => 'draft',
				'administrationId' => 'adm-1',
			],
		]);

		$this->expectException(RuntimeException::class);
		$service->fileDocument(administrationId: 'adm-1', documentId: 'd-1', approver: 'admin');

	}//end testFileRejectsMissingFileReference()

	/**
	 * Successful filing flips status and captures filedAt.
	 *
	 * @return void
	 */
	public function testFileTransitionsToFiled(): void {
		$service = $this->buildService([
			[
				'id' => 'd-2',
				'documentNumber' => 'DOC-2',
				'documentType' => 'invoice',
				'documentDate' => '2026-01-15',
				'status' => 'draft',
				'fileReference' => 'docudesk://invoices/d-2.pdf',
				'administrationId' => 'adm-1',
			],
		]);

		$row = $service->fileDocument(administrationId: 'adm-1', documentId: 'd-2', approver: 'admin');

		self::assertSame('filed', $row['status']);
		self::assertNotEmpty($row['filedAt']);
		self::assertSame('admin', $row['filedBy']);

	}//end testFileTransitionsToFiled()

	/**
	 * Early archive without admin override is rejected.
	 *
	 * @return void
	 */
	public function testArchiveRequiresRetentionWindow(): void {
		$service = $this->buildService([
			[
				'id' => 'd-3',
				'documentNumber' => 'DOC-3',
				'documentType' => 'invoice',
				'documentDate' => '2026-01-15',
				'status' => 'filed',
				'filedAt' => '2026-01-15T10:00:00+00:00',
				'administrationId' => 'adm-1',
			],
		]);

		$this->expectException(RuntimeException::class);
		$service->archiveDocument(administrationId: 'adm-1', documentId: 'd-3', reason: 'too early');

	}//end testArchiveRequiresRetentionWindow()

	/**
	 * Admin override bypasses the retention boundary.
	 *
	 * @return void
	 */
	public function testArchiveOverrideBypassesRetention(): void {
		$service = $this->buildService([
			[
				'id' => 'd-4',
				'documentNumber' => 'DOC-4',
				'documentType' => 'invoice',
				'documentDate' => '2026-01-15',
				'status' => 'filed',
				'filedAt' => '2026-01-15T10:00:00+00:00',
				'administrationId' => 'adm-1',
			],
		]);

		$row = $service->archiveDocument(
			administrationId: 'adm-1',
			documentId: 'd-4',
			reason: 'compliance signed off',
			allowEarly: true,
		);

		self::assertSame('archived', $row['status']);
		self::assertNotEmpty($row['archivedAt']);

	}//end testArchiveOverrideBypassesRetention()

	/**
	 * Retention math: seven years in the past returns true.
	 *
	 * @return void
	 */
	public function testRetentionElapsedAfterSevenYears(): void {
		$service = $this->buildService([]);
		$past = (new \DateTimeImmutable('-8 years'))->format(\DateTimeInterface::ATOM);

		self::assertTrue($service->isRetentionElapsed($past));
		self::assertFalse($service->isRetentionElapsed(date(\DateTimeInterface::ATOM)));

	}//end testRetentionElapsedAfterSevenYears()

	/**
	 * getDocumentsByType filters as expected.
	 *
	 * @return void
	 */
	public function testFilterByType(): void {
		$service = $this->buildService([
			['documentType' => 'invoice', 'documentNumber' => 'DOC-1', 'administrationId' => 'adm-1', 'status' => 'filed', 'documentDate' => '2026-01-01'],
			['documentType' => 'memo', 'documentNumber' => 'MEM-1', 'administrationId' => 'adm-1', 'status' => 'draft', 'documentDate' => '2026-02-01'],
		]);

		$rows = $service->getDocumentsByType(administrationId: 'adm-1', type: 'invoice');
		self::assertCount(1, $rows);
		self::assertSame('DOC-1', $rows[0]['documentNumber']);

	}//end testFilterByType()

	/**
	 * getDocumentsByType rejects unknown types.
	 *
	 * @return void
	 */
	public function testFilterRejectsUnknownType(): void {
		$service = $this->buildService([]);

		$this->expectException(InvalidArgumentException::class);
		$service->getDocumentsByType(administrationId: 'adm-1', type: 'unknown');

	}//end testFilterRejectsUnknownType()
}//end class
