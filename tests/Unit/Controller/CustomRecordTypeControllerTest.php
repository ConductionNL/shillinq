<?php

/**
 * Unit tests for CustomRecordTypeController.
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
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\CustomRecordTypeController;
use OCA\Shillinq\Service\Platform\CustomRecordTypeService;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Read for every user, list and write for administrators only.
 */
#[CoversClass(CustomRecordTypeController::class)]
class CustomRecordTypeControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private CustomRecordTypeService&MockObject $service;

	private CustomRecordTypeController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(CustomRecordTypeService::class);
		$this->controller = new CustomRecordTypeController(request: $this->request, service: $this->service);
	}//end setUp()

	/**
	 * The read endpoint returns the shown types.
	 *
	 * @return void
	 */
	public function testIndexReturnsTheShownTypes(): void {
		$types = [['slug' => 'parkeerplaats', 'title' => 'Parkeerplaats', 'description' => '', 'icon' => null]];
		$this->service->expects($this->once())->method('shownTypes')->willReturn($types);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['types' => $types], $response->getData());
	}//end testIndexReturnsTheShownTypes()

	/**
	 * The admin listing returns every custom type with its switch state.
	 *
	 * @return void
	 */
	public function testAvailableReturnsEveryCustomType(): void {
		$types = [['slug' => 'voertuig', 'title' => 'Voertuig', 'description' => '', 'icon' => null, 'shown' => false]];
		$this->service->method('customTypesWithState')->willReturn($types);
		$this->service->method('shownMap')->willReturn(['voertuig' => false]);

		$data = $this->controller->available()->getData();

		$this->assertSame($types, $data['types']);
		$this->assertEquals((object)['voertuig' => false], $data['shown']);
	}//end testAvailableReturnsEveryCustomType()

	/**
	 * A valid list is saved and the shown types come back.
	 *
	 * @return void
	 */
	public function testUpdateSavesTheList(): void {
		$this->request->method('getParam')->with('shown')->willReturn(['a' => 'parkeerplaats']);
		$saved = [['slug' => 'parkeerplaats', 'title' => 'Parkeerplaats', 'description' => '', 'icon' => null]];
		$this->service->expects($this->once())->method('saveShown')->with(['parkeerplaats'])->willReturn($saved);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['types' => $saved], $response->getData());
	}//end testUpdateSavesTheList()

	/**
	 * A missing or non-list body is a 400 and saves nothing.
	 *
	 * @return void
	 */
	public function testUpdateRefusesANonList(): void {
		$this->request->method('getParam')->willReturn('parkeerplaats');
		$this->service->expects($this->never())->method('saveShown');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'shown must be a list of record type slugs'], $response->getData());
	}//end testUpdateRefusesANonList()

	/**
	 * A shipped or unknown slug comes back as a 400 with the service's reason.
	 *
	 * @return void
	 */
	public function testUpdatePassesTheRefusalOn(): void {
		$this->request->method('getParam')->willReturn(['Invoice']);
		$this->service->method('saveShown')->willThrowException(
			new InvalidArgumentException('Record type "Invoice" ships with shillinq and cannot be chosen')
		);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Record type "Invoice" ships with shillinq and cannot be chosen'], $response->getData());
	}//end testUpdatePassesTheRefusalOn()

	/**
	 * An empty shown map is still a JSON object, the shape the switch table reads.
	 *
	 * @return void
	 */
	public function testAnEmptyShownMapIsAnObject(): void {
		$this->service->method('customTypesWithState')->willReturn([]);
		$this->service->method('shownMap')->willReturn([]);

		$this->assertSame('{"types":[],"shown":{}}', $this->controller->available()->render());
	}//end testAnEmptyShownMapIsAnObject()

	/**
	 * One switch is passed to the service.
	 *
	 * @return void
	 */
	public function testToggleSwitchesOneType(): void {
		$this->request->method('getParam')->willReturnMap([['slug', null, 'voertuig'], ['shown', null, true]]);
		$this->service->expects($this->once())->method('switchShown')->with('voertuig', true)->willReturn([]);

		$response = $this->controller->toggle();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['types' => []], $response->getData());
	}//end testToggleSwitchesOneType()

	/**
	 * A non-boolean switch or a missing slug is a 400.
	 *
	 * @return void
	 */
	public function testToggleRefusesABadBody(): void {
		$this->request->method('getParam')->willReturnMap([['slug', null, 'voertuig'], ['shown', null, 'yes']]);
		$this->service->expects($this->never())->method('switchShown');

		$response = $this->controller->toggle();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'slug must be a record type slug and shown a boolean'], $response->getData());
	}//end testToggleRefusesABadBody()

	/**
	 * A shipped slug is refused with the service's reason.
	 *
	 * @return void
	 */
	public function testTogglePassesTheRefusalOn(): void {
		$this->request->method('getParam')->willReturnMap([['slug', null, 'Invoice'], ['shown', null, true]]);
		$this->service->method('switchShown')->willThrowException(new InvalidArgumentException('Record type "Invoice" ships with shillinq and cannot be chosen'));

		$response = $this->controller->toggle();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Record type "Invoice" ships with shillinq and cannot be chosen'], $response->getData());
	}//end testTogglePassesTheRefusalOn()

	/**
	 * Only the read endpoint is open to non-admins; listing and writing need the admin setting.
	 *
	 * @return void
	 */
	public function testAuthPostureOfEachEndpoint(): void {
		$index = new ReflectionMethod(CustomRecordTypeController::class, 'index');
		$this->assertCount(1, $index->getAttributes(NoAdminRequired::class));
		$this->assertCount(0, $index->getAttributes(AuthorizedAdminSetting::class));

		foreach (['available', 'update', 'toggle'] as $name) {
			$method = new ReflectionMethod(CustomRecordTypeController::class, $name);
			$this->assertCount(0, $method->getAttributes(NoAdminRequired::class), $name);
			$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $name);
			$this->assertSame(['settings' => AdminSettings::class], $attributes[0]->getArguments(), $name);
		}
	}//end testAuthPostureOfEachEndpoint()
}//end class
