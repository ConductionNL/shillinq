<?php

/**
 * Unit tests for WidgetSettingsController's key lifecycle (REQ-WSW-009).
 *
 * The point of these tests is the seam that was MISSING, not the one that
 * worked: `WidgetAuthService::createApiKey()` was fully implemented (mint,
 * bcrypt hash, prefix, persist, audit) and had no route, no controller method
 * and no caller anywhere in the app. The admin view's "Generate key" button
 * posted to `widget/admin/keys/rotate`, and `rotateApiKey()` returns
 * `No active key found for businessId.` when the business has no key yet — so
 * the FIRST key for any business was unobtainable and the public booking widget
 * could not be bootstrapped at all.
 *
 * Covered here:
 *   - create() reaches createApiKey() with BOTH ids and returns the one-time
 *     plaintext key;
 *   - create() refuses (400) without administrationId and never reaches the
 *     service — rotate() derives the tenant boundary from the predecessor
 *     record, create() has no predecessor to derive it from;
 *   - rotate() still refuses on a business with no active key, which is the
 *     behaviour that made this gap invisible, and MUST NOT be "fixed" by
 *     letting rotate mint;
 *   - revoke() is unchanged.
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
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WidgetSettingsController;
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WidgetSettingsController.
 */
class WidgetSettingsControllerTest extends TestCase {

	/**
	 * IRequest stub.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Auth service stub.
	 *
	 * @var WidgetAuthService&MockObject
	 */
	private WidgetAuthService&MockObject $auth;

	/**
	 * User session stub.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build the collaborator doubles and a signed-in admin.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->auth = $this->createMock(WidgetAuthService::class);
		$this->session = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return WidgetSettingsController The controller.
	 */
	private function controller(): WidgetSettingsController {
		return new WidgetSettingsController($this->request, $this->auth, $this->session);
	}//end controller()

	/**
	 * Stub the request parameters the controller reads.
	 *
	 * @param array<string,string> $params Parameter name => value.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

	}//end params()

	/**
	 * create() mints the first key and returns the one-time plaintext.
	 *
	 * @return void
	 */
	public function testCreateMintsTheFirstKeyForABusiness(): void {
		$this->params(['businessId' => 'biz-1', 'administrationId' => 'adm-1']);

		$this->auth->expects($this->once())
			->method('createApiKey')
			->with('adm-1', 'biz-1', 'admin')
			->willReturn(
				[
					'success' => true,
					'businessId' => 'biz-1',
					'apiKey' => 'plaintext-shown-once',
				]
			);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('plaintext-shown-once', $response->getData()['apiKey']);

	}//end testCreateMintsTheFirstKeyForABusiness()

	/**
	 * create() refuses without administrationId and never reaches the service.
	 *
	 * The old admin view sent `administrationId: this.businessId` — a field the
	 * rotate endpoint never read. Minting genuinely needs the tenant boundary,
	 * so it is required rather than silently defaulted to the businessId.
	 *
	 * @return void
	 */
	public function testCreateRefusesWithoutAdministrationId(): void {
		$this->params(['businessId' => 'biz-1']);

		$this->auth->expects($this->never())->method('createApiKey');

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testCreateRefusesWithoutAdministrationId()

	/**
	 * rotate() still refuses a business with no active key.
	 *
	 * This is the behaviour that hid the missing capability: the "Generate key"
	 * button hit this path and always failed on a fresh businessId. The fix is
	 * a create endpoint, NOT letting rotate mint — rotate must keep failing
	 * here, or the 7-day predecessor grace contract loses its meaning.
	 *
	 * @return void
	 */
	public function testRotateStillRefusesWhenThereIsNoActiveKey(): void {
		$this->params(['businessId' => 'biz-fresh']);

		$this->auth->expects($this->never())->method('createApiKey');
		$this->auth->expects($this->once())
			->method('rotateApiKey')
			->with('biz-fresh', 'admin')
			->willReturn(['success' => false, 'message' => 'No active key found for businessId.']);

		$response = $this->controller()->rotate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testRotateStillRefusesWhenThereIsNoActiveKey()

	/**
	 * revoke() delegates unchanged.
	 *
	 * @return void
	 */
	public function testRevokeDelegatesToTheService(): void {
		$this->params(['businessId' => 'biz-1']);

		$this->auth->expects($this->once())
			->method('revokeApiKey')
			->with('biz-1', 'admin')
			->willReturn(['success' => true]);

		$response = $this->controller()->revoke();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRevokeDelegatesToTheService()
}//end class
