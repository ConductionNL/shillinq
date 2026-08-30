<?php

/**
 * Unit tests for the ADR-005 tenant guard on OssController::generateReturn().
 *
 * The OSS return draft exposes cross-border EU turnover per member state for
 * one administration. Before #520 the only thing standing in front of it was
 * `preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $administrationId)` — a
 * character-class test on the id's SHAPE, not a check that the caller belongs
 * to it — so any authenticated user could draft any tenant's OSS return.
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\OssController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\OssRateResolver;
use OCA\Shillinq\Service\OssReturnGenerator;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the OSS return-draft tenant guard.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OssControllerTest extends TestCase {

	/**
	 * Mock return generator.
	 *
	 * @var OssReturnGenerator&MockObject
	 */
	private OssReturnGenerator&MockObject $generator;

	/**
	 * Mock membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * What canAccess() answers, read through a callback so a test can flip it
	 * without appending a second matcher to the same mocked method.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * Controller under test.
	 *
	 * @var OssController
	 */
	private OssController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$this->generator = $this->createMock(OssReturnGenerator::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$session = $this->createMock(IUserSession::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		$params = [
			'administration_id' => 'adm-1',
			'period_year' => 2024,
			'period_quarter' => 'Q1',
			'registration_id' => 'reg-1',
		];
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return $params[$key] ?? $default;
			}
		);

		$this->controller = new OssController(
			request: $request,
			rateResolver: $this->createMock(OssRateResolver::class),
			returnGenerator: $this->generator,
			logger: new NullLogger(),
			userSession: $session,
			context: $this->context,
		);

	}//end setUp()

	/**
	 * generateReturn() refuses an administration the caller is not a member of.
	 *
	 * The refusal must happen before the generator runs: the draft itself is
	 * the disclosure, so producing it and then hiding it would not be a guard.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function testGenerateReturnRefusesAForeignAdministration(): void {
		$this->canAccess = false;
		$this->generator->expects($this->never())->method('generateDraft');

		$response = $this->controller->generateReturn();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Administration not found', $response->getData()['error']);

	}//end testGenerateReturnRefusesAForeignAdministration()

	/**
	 * A member still gets the draft — the guard scopes, it does not disable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function testAMemberStillGetsTheDraft(): void {
		$this->generator->expects($this->once())
			->method('generateDraft')
			->willReturn(['administrationId' => 'adm-1', 'lines' => []]);

		$response = $this->controller->generateReturn();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('adm-1', $response->getData()['administrationId']);

	}//end testAMemberStillGetsTheDraft()
}//end class
