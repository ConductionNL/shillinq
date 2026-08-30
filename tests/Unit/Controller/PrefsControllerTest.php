<?php

/**
 * Unit tests for PreferencesController (prefs API — get/set/clear per-user values).
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
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-mechanical-boilerplate-served-by-apphost-generics
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PreferencesController.
 *
 * Verifies the per-user key/value prefs API (get/set/clear) and the
 * key-sanitization behaviour. setPreference must NOT carry @NoCSRFRequired (H1).
 */
class PrefsControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IConfig.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var PreferencesController
	 */
	private PreferencesController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new PreferencesController(
			request: $this->request,
			config: $this->config,
			userSession: $this->userSession,
		);

	}//end setUp()

	/**
	 * setPreference must NOT have @NoCSRFRequired annotation (H1, per user policy).
	 *
	 * @return void
	 */
	public function testSetPreferenceHasNoCSRFRequiredRemoved(): void {
		$reflection = new \ReflectionMethod(PreferencesController::class, 'setPreference');
		$docComment = ($reflection->getDocComment() ?: '');

		self::assertStringNotContainsString(
			'@NoCSRFRequired',
			$docComment,
			'H1: setPreference must not bypass CSRF protection'
		);

	}//end testSetPreferenceHasNoCSRFRequiredRemoved()

	/**
	 * getPreference returns 401 when the user is not logged in.
	 *
	 * @return void
	 */
	public function testGetPreferenceReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->getPreference(key: 'some-key');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGetPreferenceReturns401WhenUnauthenticated()

	/**
	 * getPreference returns the stored value for a valid key.
	 *
	 * @return void
	 */
	public function testGetPreferenceReturnsStoredValue(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->config->method('getUserValue')->willReturn('true');

		$response = $this->controller->getPreference(key: 'support-seen');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('support-seen', $data['key']);
		self::assertSame('true', $data['value']);

	}//end testGetPreferenceReturnsStoredValue()

	/**
	 * getPreference returns null value when the key has no stored value.
	 *
	 * @return void
	 */
	public function testGetPreferenceReturnsNullWhenKeyNotSet(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->config->method('getUserValue')->willReturn('');

		$response = $this->controller->getPreference(key: 'unset-key');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($data['value']);

	}//end testGetPreferenceReturnsNullWhenKeyNotSet()

	/**
	 * setPreference stores the value and returns 200 with the key+value.
	 *
	 * @return void
	 */
	public function testSetPreferenceStoresValue(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->config->expects($this->once())->method('setUserValue');

		$response = $this->controller->setPreference(key: 'support-seen', value: 'true');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('true', $data['value']);

	}//end testSetPreferenceStoresValue()

	/**
	 * setPreference with empty value deletes the key and returns null.
	 *
	 * @return void
	 */
	public function testSetPreferenceDeletesWhenValueIsEmpty(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->config->expects($this->once())->method('deleteUserValue');

		$response = $this->controller->setPreference(key: 'support-seen', value: '');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($data['value']);

	}//end testSetPreferenceDeletesWhenValueIsEmpty()

	/**
	 * getPreference returns 400 for an invalid key (non-alphanumeric).
	 *
	 * @return void
	 */
	public function testGetPreferenceReturns400ForInvalidKey(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// A key that contains only non-alphanumeric chars (e.g. '!!!') is invalid.
		$response = $this->controller->getPreference(key: '!!!');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testGetPreferenceReturns400ForInvalidKey()

}//end class
