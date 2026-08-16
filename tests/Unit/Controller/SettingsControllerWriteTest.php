<?php

/**
 * SettingsController write-path unit tests.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Controller\SettingsController;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The canonical AppHost route table routes BOTH `PUT /api/settings`
 * (`settings#update`) and `POST /api/settings` (`settings#create`) into this
 * controller, and because shillinq ships the class itself no generic is
 * aliased in to cover either.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters, and
 * that the returned payload carries the service's result. A test that only
 * checked for a 200, or only that the response was a JSONResponse, would pass
 * against a controller that silently wrote nothing.
 */
final class SettingsControllerWriteTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Controller under test.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		$this->controller = new SettingsController(
			request: $this->request,
			settingsService: $this->settingsService
		);

	}//end setUp()

	/**
	 * PUT /api/settings must persist the request parameters and return them.
	 *
	 * @return void
	 */
	public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void {
		$submitted = ['register' => 'shillinq'];
		$stored = [
			'register' => 'shillinq',
			'rgs_template' => 'bbv',
			'administration_id' => 'ADM-001',
			'openregisters' => true,
			'isAdmin' => true,
		];

		$this->request->method('getParams')->willReturn($submitted);

		// The ITEM: the write reaches the service, with the submitted params.
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'update() must return the config the service actually stored, not the submission'
		);

	}//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

	/**
	 * POST /api/settings is the legacy alias and must write identically.
	 *
	 * The alias staying a real write — not an empty success — is load-bearing:
	 * shillinq's admin settings UI still POSTs to this route.
	 *
	 * @return void
	 */
	public function testCreateDelegatesToUpdateAndStillWrites(): void {
		$submitted = ['rgs_template' => 'bbv'];
		$stored = ['rgs_template' => 'bbv'];

		$this->request->method('getParams')->willReturn($submitted);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(
			[
				'success' => true,
				'config' => $stored,
			],
			$response->getData(),
			'create() must produce the same written result as update()'
		);

	}//end testCreateDelegatesToUpdateAndStillWrites()

	/**
	 * The write must not be skipped when the submission is empty.
	 *
	 * An early return on an empty payload would look identical to a
	 * successful no-op write from the caller's side.
	 *
	 * @return void
	 */
	public function testEmptySubmissionStillReachesTheService(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with([])
			->willReturn(['unchanged' => true]);

		$response = $this->controller->update();

		$this->assertSame(
			[
				'success' => true,
				'config' => ['unchanged' => true],
			],
			$response->getData()
		);

	}//end testEmptySubmissionStillReachesTheService()

	/**
	 * Both write verbs must carry the app's admin posture.
	 *
	 * Nextcloud's SecurityMiddleware evaluates auth attributes on the
	 * DISPATCHED method only, so `create()` delegating to `update()` does NOT
	 * inherit `update()`'s posture — each needs its own attribute. Equally, a
	 * write must never pick up the posture of a sibling READ method.
	 *
	 * @return void
	 */
	public function testBothWriteVerbsAreAdminGuardedIndependently(): void {
		$inspected = 0;

		foreach (['update', 'create'] as $method) {
			$attributes = (new ReflectionMethod(SettingsController::class, $method))
				->getAttributes(AuthorizedAdminSetting::class);

			$this->assertCount(
				1,
				$attributes,
				sprintf(
					'SettingsController::%s() is a WRITE and must declare its own '
					. '#[AuthorizedAdminSetting] — the middleware only reads attributes '
					. 'on the dispatched method, so delegation does not inherit posture.',
					$method
				)
			);

			$this->assertSame(
				[Application::APP_ID],
				$attributes[0]->getArguments(),
				sprintf(
					'SettingsController::%s() must be bound to this app\'s admin setting, '
					. 'matching the posture of the existing settings write.',
					$method
				)
			);

			$inspected++;
		}//end foreach

		// Positive control: the loop above asserts nothing if it never ran.
		$this->assertSame(2, $inspected, 'Both write verbs must have been inspected');

	}//end testBothWriteVerbsAreAdminGuardedIndependently()

}//end class
