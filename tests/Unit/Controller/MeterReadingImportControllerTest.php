<?php

/**
 * The meter reading import route: a member imports, a stranger is refused.
 *
 * Over the real AdministrationContextService (memberships in an in-memory
 * register) and the real MeterReadingImportService.
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

use OCA\Shillinq\Controller\MeterReadingImportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-USB-001 through the route.
 */
class MeterReadingImportControllerTest extends TestCase {

	private InMemoryObjectServiceStub $store;

	/**
	 * Seed one membership: the bookkeeper belongs to adm-1, the stranger to adm-2.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'AdministrationMembership' => [
					['id' => 'm-1', 'userId' => 'bookkeeper', 'administrationId' => 'adm-1', 'role' => 'bookkeeper'],
					['id' => 'm-2', 'userId' => 'stranger', 'administrationId' => 'adm-2', 'role' => 'bookkeeper'],
				],
				'MeterReading'             => [],
				'UsageRatePlan'            => [],
			],
			findAllRendersEntities: true
		);

	}//end setUp()

	/**
	 * A member imports two readings; a stranger, an anonymous caller and an empty file are refused.
	 *
	 * @return void
	 */
	public function testAMemberImportsAndOthersAreRefused(): void {
		$rows = [
			['customerId' => 'cust-hosting-noord', 'resourceType' => 'storage_gb', 'quantity' => '250', 'periodStart' => '2026-09-01', 'periodEnd' => '2026-09-30'],
			['customerId' => 'cust-hosting-noord', 'resourceType' => 'storage_gb', 'quantity' => '-5', 'periodStart' => '2026-09-01', 'periodEnd' => '2026-09-30'],
		];

		$this->assertSame(404, $this->controller(user: 'stranger', params: ['administrationId' => 'adm-1', 'rows' => $rows])->import()->getStatus());
		$this->assertSame(401, $this->controller(user: null, params: ['administrationId' => 'adm-1', 'rows' => $rows])->import()->getStatus());
		$this->assertSame(400, $this->controller(user: 'bookkeeper', params: ['administrationId' => 'adm-1', 'rows' => []])->import()->getStatus());
		$this->assertSame([], $this->store->setSchema('MeterReading')->findAll());

		$response = $this->controller(user: 'bookkeeper', params: ['administrationId' => 'adm-1', 'rows' => $rows])->import();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData()['created']);
		$this->assertSame([['row' => 2, 'reason' => 'The quantity is negative.']], $response->getData()['refused']);

	}//end testAMemberImportsAndOthersAreRefused()

	/**
	 * The controller over the real membership service and the real importer.
	 *
	 * @param string|null         $user   The signed-in user.
	 * @param array<string,mixed> $params The request body.
	 *
	 * @return MeterReadingImportController
	 */
	private function controller(?string $user, array $params): MeterReadingImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$signedIn = null;
		if ($user !== null) {
			$signedIn = $this->createMock(IUser::class);
			$signedIn->method('getUID')->willReturn($user);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new MeterReadingImportController(
			$request,
			new AdministrationContextService($container, $session, $config, $this->createMock(LoggerInterface::class)),
			new MeterReadingImportService($this->store, $settings, $l10n)
		);

	}//end controller()
}//end class
