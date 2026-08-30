<?php

/**
 * Unit tests for BudgetBBVMappingController.
 *
 * Covers security-endpoint-guards REQ-001's JUSTIFY verdict for both
 * `index()` and `show()`: neither method reads an OpenRegister object —
 * `index()` returns a session-derived scope envelope and `show()` echoes
 * the URL `$id` back unread — so no per-object tenant guard applies
 * beyond the anonymous-rejection check already present. These tests prove
 * that check actually rejects anonymous callers (negative direction) and
 * that an authenticated caller still succeeds (positive direction), per
 * REQ-004.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BudgetBBVMappingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Budget BBV Mapping index + detail page envelopes.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
final class BudgetBBVMappingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Mock FiscalYearContextService.
	 *
	 * @var FiscalYearContextService&MockObject
	 */
	private FiscalYearContextService&MockObject $fiscalYearContext;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->fiscalYearContext = $this->createMock(FiscalYearContextService::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return BudgetBBVMappingController
	 */
	private function controller(): BudgetBBVMappingController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new BudgetBBVMappingController(
			$this->request,
			$this->userSession,
			$l10n,
			$this->administrationContext,
			$this->fiscalYearContext,
		);

	}//end controller()

	/**
	 * Put a session user in place.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function actAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end actAs()

	/**
	 * NEGATIVE CONTROL: index() rejects an anonymous caller with 401
	 * before any scope resolution runs.
	 *
	 * @return void
	 */
	public function testIndexRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testIndexRejectsAnonymous()

	/**
	 * POSITIVE CONTROL: an authenticated caller with an accessible
	 * administration receives the index envelope with a resolved scope.
	 *
	 * @return void
	 */
	public function testIndexSucceedsForAuthenticatedCaller(): void {
		$this->actAs(uid: 'alice');
		$this->administrationContext->method('buildContext')->willReturn(
			['activeAdministrationId' => 'adm-1']
		);
		$this->fiscalYearContext->method('resolveActiveWindow')->willReturn(
			[
				'fiscalYear' => 2026,
				'startDate' => '2026-01-01',
				'endDate' => '2027-01-01',
			]
		);

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame('shillinq', $data['register']);
		self::assertSame('BudgetBBVMapping', $data['schema']);
		self::assertSame('adm-1', $data['scope']['administrationId']);
		self::assertSame(2026, $data['scope']['fiscalYear']);

	}//end testIndexSucceedsForAuthenticatedCaller()

	/**
	 * NEGATIVE CONTROL: show() rejects an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testShowRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller()->show('mapping-1');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testShowRejectsAnonymous()

	/**
	 * POSITIVE CONTROL: an authenticated caller receives the detail
	 * envelope for the id named in the URL. show() performs no
	 * OpenRegister lookup (see class docblock), so any authenticated
	 * caller reaching this route sees only routing metadata, never
	 * another tenant's stored data.
	 *
	 * @return void
	 */
	public function testShowSucceedsForAuthenticatedCaller(): void {
		$this->actAs(uid: 'alice');
		$this->administrationContext->method('buildContext')->willReturn(
			['activeAdministrationId' => 'adm-1']
		);
		$this->fiscalYearContext->method('resolveActiveWindow')->willReturn(
			[
				'fiscalYear' => 2026,
				'startDate' => '2026-01-01',
				'endDate' => '2027-01-01',
			]
		);

		$response = $this->controller()->show('mapping-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame('mapping-1', $data['id']);
		self::assertSame('BudgetBBVMapping', $data['schema']);

	}//end testShowSucceedsForAuthenticatedCaller()

	/**
	 * When the caller has no accessible administration, the scope
	 * envelope answers null-valued fields rather than erroring (an empty
	 * / no-administrations state, not a security failure).
	 *
	 * @return void
	 */
	public function testIndexScopeIsEmptyWhenNoActiveAdministration(): void {
		$this->actAs(uid: 'bob');
		$this->administrationContext->method('buildContext')->willReturn([]);

		$response = $this->controller()->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertNull($data['scope']['administrationId']);

	}//end testIndexScopeIsEmptyWhenNoActiveAdministration()
}//end class
