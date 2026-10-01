<?php

/**
 * Unit tests for FieldRequirementController.
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
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FieldRequirementController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The field list of a requirement is served to people of its administration only.
 */
class FieldRequirementControllerTest extends TestCase {

	/**
	 * A controller for a caller of Gemeente Voorbeeld.
	 *
	 * @param string|null $userId The caller.
	 *
	 * @return FieldRequirementController
	 */
	private function controller(?string $userId = 'petra'): FieldRequirementController {
		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-gov-1');

		$requirements = $this->createStub(FieldRequirements::class);
		$requirements->method('find')->willReturnCallback(
			static fn (string $id): ?array => [
				'fr-1'  => ['id' => 'fr-1', 'administrationId' => 'adm-gov-1', 'schema' => 'SupplierInvoice', 'field' => 'costCenter'],
				'fr-vd' => ['id' => 'fr-vd', 'administrationId' => 'adm-consultancy-nl', 'schema' => 'CustomerMaster', 'field' => 'kvkNumber'],
			][$id] ?? null
		);
		$requirements->method('formFields')->willReturn([['field' => 'costCenter', 'title' => 'Cost Center', 'required' => 'administration']]);

		return new FieldRequirementController($this->createStub(IRequest::class), $context, $requirements);

	}//end controller()

	/**
	 * The requirement's own administration sees the field list.
	 *
	 * @return void
	 */
	public function testTheFieldListOfARequirement(): void {
		$response = $this->controller()->fields('fr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('administration', $response->getData()['rows'][0]['required']);

	}//end testTheFieldListOfARequirement()

	/**
	 * Another administration's requirement and an unknown one are masked as absent; no caller is 401.
	 *
	 * @return void
	 */
	public function testOtherAdministrationsAndAnonymousCallersAreRefused(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->fields('fr-vd')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->fields('fr-none')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->fields('fr-1')->getStatus());

	}//end testOtherAdministrationsAndAnonymousCallersAreRefused()
}//end class
