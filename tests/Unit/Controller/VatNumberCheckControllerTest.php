<?php

/**
 * VatNumberCheckController tests (tax-vat-number-check)
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatNumberCheckController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-TVNC-001 through the route.
 */
class VatNumberCheckControllerTest extends TestCase {

	private const MULLER = 'c0570000-0000-4000-8000-000000000001';

	private const NO_NUMBER = 'c0570000-0000-4000-8000-000000000009';

	private InMemoryObjectServiceStub $store;

	/**
	 * Seed two customers, one without a number.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster' => [
					['id' => self::MULLER, 'customerId' => 'DEB-0107', 'legalName' => 'Kunstverlag Müller GmbH', 'vatId' => 'DE000000000', 'administrationId' => 'adm-1'],
					['id' => self::NO_NUMBER, 'customerId' => 'DEB-0108', 'legalName' => 'Brightside Consulting Ltd', 'administrationId' => 'adm-1'],
				],
				'Payee'          => [],
				'ViesValidation' => [],
			]
		);

	}//end setUp()

	/**
	 * A member of the administration checks a customer; the record keeps the status.
	 *
	 * @return void
	 */
	public function testAMemberChecksACustomer(): void {
		$response = $this->controller(user: 'bookkeeper', access: true)->check('customer', self::MULLER);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('valid', $response->getData()['status']);
		$this->assertSame('valid', $this->store->find(id: self::MULLER, schema: 'CustomerMaster')->getObject()['vatIdValidationStatus']);

	}//end testAMemberChecksACustomer()

	/**
	 * Strangers, anonymous callers, unknown types and records without a number are refused.
	 *
	 * @return void
	 */
	public function testOtherAdministrationsAndBadRequestsAreRefused(): void {
		$this->assertSame(404, $this->controller(user: 'other', access: false)->check('customer', self::MULLER)->getStatus());
		$this->assertSame(401, $this->controller(user: null, access: true)->check('customer', self::MULLER)->getStatus());
		$this->assertSame(404, $this->controller(user: 'bookkeeper', access: true)->check('employee', self::MULLER)->getStatus());
		$this->assertSame(404, $this->controller(user: 'bookkeeper', access: true)->check('supplier', self::MULLER)->getStatus());
		$this->assertSame(422, $this->controller(user: 'bookkeeper', access: true)->check('customer', self::NO_NUMBER)->getStatus());
		$this->assertArrayNotHasKey('vatIdValidationStatus', $this->store->find(id: self::MULLER, schema: 'CustomerMaster')->getObject());

	}//end testOtherAdministrationsAndBadRequestsAreRefused()

	/**
	 * Build the controller over the real records helper and the real check.
	 *
	 * @param string|null $user   The caller.
	 * @param bool        $access Whether the caller may see adm-1.
	 *
	 * @return VatNumberCheckController
	 */
	private function controller(?string $user, bool $access): VatNumberCheckController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturn($access);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$records = new AssetRecords($this->store, new ObjectTransitionRunner(container: $this->createMock(ContainerInterface::class)), $settings);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"valid": true, "name": "Kunstverlag Müller GmbH"}');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);

		return new VatNumberCheckController(
			$this->createMock(IRequest::class),
			$context,
			$records,
			new VatNumberCheck(new ViesService($config, $clients, $logger, $this->store), $this->store, $settings, $logger)
		);

	}//end controller()
}//end class
