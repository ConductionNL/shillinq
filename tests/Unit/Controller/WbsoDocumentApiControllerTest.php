<?php

/**
 * Controller tests for WbsoDocumentApiController.
 *
 * Covers REQ-WBSO-003/005/007/009 — list / show / create / file / archive
 * endpoints.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-36
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WbsoDocumentApiController;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-36
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoDocumentApiControllerTest extends TestCase {

	/**
	 * Request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Service.
	 *
	 * @var WbsoDocumentService&MockObject
	 */
	private WbsoDocumentService&MockObject $documents;

	/**
	 * Rbac.
	 *
	 * @var WbsoRbacResolver&MockObject
	 */
	private WbsoRbacResolver&MockObject $rbac;

	/**
	 * Session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Controller.
	 *
	 * @var WbsoDocumentApiController
	 */
	private WbsoDocumentApiController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->documents = $this->createMock(WbsoDocumentService::class);
		$this->rbac = $this->createMock(WbsoRbacResolver::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($user);

		$this->controller = new WbsoDocumentApiController(
			request: $this->request,
			documents: $this->documents,
			rbac: $this->rbac,
			userSession: $this->session,
			logger: $this->logger,
			l10n: $this->l10n,
		);
	}//end setUp()

	/**
	 * Index returns rows.
	 *
	 * @return void
	 */
	public function testIndexReturnsRows(): void {
		// Only `administration_id` resolves to adm-1; type / status / filedFrom
		// must fall back to their default empty strings so the controller takes
		// the unfiltered `getDocumentsByAdministration` path.
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				if ($key === 'administration_id') {
					return 'adm-1';
				}

				return $default;
			}
		);
		$this->rbac->method('hasAny')->willReturn(true);
		$this->documents->method('getDocumentsByAdministration')->willReturn([
			['documentNumber' => 'DOC-1', 'status' => 'draft', 'administrationId' => 'adm-1'],
		]);

		$response = $this->controller->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(1, $data['documents']);

	}//end testIndexReturnsRows()

	/**
	 * File transitions return 200.
	 *
	 * @return void
	 */
	public function testFileHappyPath(): void {
		$this->request->method('getParam')->willReturn('adm-1');
		$this->rbac->method('hasAny')->willReturn(true);
		$this->documents->method('fileDocument')->willReturn([
			'id' => 'd-1',
			'status' => 'filed',
			'filedAt' => '2026-01-15T10:00:00+00:00',
		]);

		$response = $this->controller->file(id: 'd-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testFileHappyPath()

	/**
	 * Archive without retention boundary returns 409.
	 *
	 * @return void
	 */
	public function testArchiveConflictReturns409(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'administration_id' => 'adm-1',
					'reason' => 'audit signed',
					'allowEarly' => false,
					default => $default,
				};
			}
		);
		$this->rbac->method('hasAny')->willReturn(true);
		$this->documents->method('archiveDocument')->willThrowException(new RuntimeException('Retention not elapsed'));

		$response = $this->controller->archive(id: 'd-1');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testArchiveConflictReturns409()

	/**
	 * Only authorised roles can archive.
	 *
	 * @return void
	 */
	public function testArchiveRequiresAuditorOrAdmin(): void {
		$this->request->method('getParam')->willReturn('adm-1');
		$this->rbac->method('hasAny')->willReturn(false);

		$response = $this->controller->archive(id: 'd-1');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testArchiveRequiresAuditorOrAdmin()
}//end class
