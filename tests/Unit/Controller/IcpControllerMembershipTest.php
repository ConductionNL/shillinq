<?php

/**
 * IcpController refuses a caller who is not a member of the administration.
 *
 * Built over the real AdministrationContextService (memberships read from the
 * register) and the real ViesService (over an in-memory register), so the
 * refusal and the "nothing is stored" claim are measured on the classes that
 * run in production, not on a stubbed canAccess().
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

use OCA\Shillinq\Controller\IcpController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-ICP-001: the administration scope is the caller's own, never any id they send.
 */
class IcpControllerMembershipTest extends TestCase {

	private InMemoryObjectServiceStub $store;

	private IClient $client;

	private IcpService $icp;

	private IcpFilingService $filing;

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
				'ViesValidation'           => [],
			]
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"valid": true, "name": "Kunstverlag Müller GmbH", "requestIdentifier": "WAPIAAAAX"}');
		$this->client = $this->createMock(IClient::class);
		$this->client->method('get')->willReturn($response);
		$this->icp    = $this->createMock(IcpService::class);
		$this->filing = $this->createMock(IcpFilingService::class);

	}//end setUp()

	/**
	 * A user who is not a member of the administration is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAStrangerCannotStoreAVatCheckInSomeoneElsesAdministration(): void {
		$this->client->expects($this->never())->method('get');

		$response = $this->controller(user: 'stranger', params: ['vat_id' => 'DE000000000', 'administration_id' => 'adm-1'])->lookupVatId();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([], $this->store->setSchema('ViesValidation')->findAll(['filters' => ['administrationId' => 'adm-1']]));
		$this->assertSame(0, $this->store->setSchema('ViesValidation')->count());

	}//end testAStrangerCannotStoreAVatCheckInSomeoneElsesAdministration()

	/**
	 * A member still checks a number, and the evidence lands in their administration.
	 *
	 * @return void
	 */
	public function testAMemberStillChecksANumber(): void {
		$response = $this->controller(user: 'bookkeeper', params: ['vat_id' => 'DE000000000', 'administration_id' => 'adm-1'])->lookupVatId();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
		$stored = $this->store->setSchema('ViesValidation')->findAll(['filters' => ['administrationId' => 'adm-1']]);
		$this->assertCount(1, $stored);

	}//end testAMemberStillChecksANumber()

	/**
	 * Every other ICP endpoint takes the same administration_id and refuses a stranger too.
	 *
	 * @return void
	 */
	public function testEveryIcpEndpointRefusesAStranger(): void {
		$this->icp->expects($this->never())->method($this->anything());
		$this->filing->expects($this->never())->method($this->anything());

		$params = [
			'period_id'         => '2026-Q1',
			'quarter'           => '2026-Q1',
			'corrects_period'   => '2026-Q1',
			'invoice_id'        => 'inv-1',
			'administration_id' => 'adm-1',
		];
		$controller = $this->controller(user: 'stranger', params: $params);

		$this->assertSame(404, $controller->ledger()->getStatus(), 'ledger');
		$this->assertSame(404, $controller->reconcile()->getStatus(), 'reconcile');
		$this->assertSame(404, $controller->periodicity()->getStatus(), 'periodicity');
		$this->assertSame(404, $controller->correction()->getStatus(), 'correction');
		$this->assertSame(404, $controller->auditExport()->getStatus(), 'auditExport');
		$this->assertSame(404, $controller->renderInvoicePdf()->getStatus(), 'renderInvoicePdf');

	}//end testEveryIcpEndpointRefusesAStranger()

	/**
	 * Build the controller over the real membership service and the real VIES service.
	 *
	 * @param string               $user   The signed-in user.
	 * @param array<string,string> $params The request parameters.
	 *
	 * @return IcpController
	 */
	private function controller(string $user, array $params): IcpController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$signedIn = $this->createMock(IUser::class);
		$signedIn->method('getUID')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);
		$logger = $this->createMock(LoggerInterface::class);

		return new IcpController(
			request: $request,
			icpService: $this->icp,
			filingService: $this->filing,
			viesService: new ViesService($config, $clients, $logger, $this->store),
			pdfRenderer: $this->createMock(ArInvoiceIcpPdfRenderer::class),
			userSession: $session,
			logger: $logger,
			objectService: $this->store,
			administrations: new AdministrationContextService($container, $session, $config, $logger),
		);

	}//end controller()
}//end class
