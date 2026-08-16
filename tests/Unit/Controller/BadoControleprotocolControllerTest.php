<?php

/**
 * Unit tests for the ADR-005 tenant guard on BadoControleprotocolController.
 *
 * Both endpoints previously authorised nothing: `preg_match()` on the id is a
 * character-class test, not a membership check, so any authenticated user could
 * read another organisation's audit aggregation — including the proposed audit
 * opinion — or export its complete accountantsdossier. Measured live on a
 * two-account rig before #520: attacker HTTP 200 with `proposedOpinion`.
 *
 * A protocol that does not exist and a protocol the caller may not see answer
 * the SAME 404, deliberately: the endpoint must not confirm existence.
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BadoControleprotocolController;
use OCA\Shillinq\Service\AccountantsdossierExportService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the BADO controleprotocol tenant guard.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BadoControleprotocolControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock BADO service.
	 *
	 * @var BadoControleprotocolService&MockObject
	 */
	private BadoControleprotocolService&MockObject $service;

	/**
	 * Mock exporter.
	 *
	 * @var AccountantsdossierExportService&MockObject
	 */
	private AccountantsdossierExportService&MockObject $exporter;

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
	 * @var BadoControleprotocolController
	 */
	private BadoControleprotocolController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(BadoControleprotocolService::class);
		$this->exporter = $this->createMock(AccountantsdossierExportService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$session = $this->createMock(IUserSession::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return $key === 'protocol_id' ? 'proto-1' : $default;
			}
		);

		$this->controller = new BadoControleprotocolController(
			request: $this->request,
			service: $this->service,
			logger: new NullLogger(),
			userSession: $session,
			exporter: $this->exporter,
			context: $this->context,
		);

	}//end setUp()

	/**
	 * aggregation() masks a protocol belonging to a foreign organisation as 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAggregationMasksAForeignProtocolAs404(): void {
		$this->canAccess = false;
		$this->service->method('organisationIdFor')->willReturn('adm-9');
		$this->service->expects($this->never())->method('computeAggregation');

		$response = $this->controller->aggregation();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Controleprotocol not found', $response->getData()['error']);

	}//end testAggregationMasksAForeignProtocolAs404()

	/**
	 * A protocol with no organisationId at all is refused, not admitted.
	 *
	 * organisationIdFor() answers null for a protocol that does not exist; the
	 * guard must fail closed on it rather than skip the membership check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testATenantlessProtocolIsRefused(): void {
		$this->service->method('organisationIdFor')->willReturn(null);
		$this->service->expects($this->never())->method('computeAggregation');

		$response = $this->controller->aggregation();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Controleprotocol not found', $response->getData()['error']);

	}//end testATenantlessProtocolIsRefused()

	/**
	 * A failure while resolving the tenant refuses, it does not fall through.
	 *
	 * The resolver reaches OpenRegister, so it can throw. A guard that treated
	 * "the tenant could not be resolved" as "no objection" would open the
	 * endpoint on exactly the failure it is there to survive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAFailedTenantResolutionRefuses(): void {
		$this->service->method('organisationIdFor')
			->willThrowException(new \RuntimeException('OpenRegister unavailable'));
		$this->service->expects($this->never())->method('computeAggregation');

		$response = $this->controller->aggregation();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Controleprotocol not found', $response->getData()['error']);

	}//end testAFailedTenantResolutionRefuses()

	/**
	 * A member of the owning organisation still gets the aggregation.
	 *
	 * The owner-side arm is the half that a refusal-only suite cannot see: an
	 * endpoint that denies everyone passes every attacker probe.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAMemberStillGetsTheAggregation(): void {
		$this->service->method('organisationIdFor')->willReturn('adm-1');
		$this->service->expects($this->once())
			->method('computeAggregation')
			->willReturn(['proposedOpinion' => 'goedkeurend']);

		$response = $this->controller->aggregation();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('goedkeurend', $response->getData()['proposedOpinion']);

	}//end testAMemberStillGetsTheAggregation()

	/**
	 * exportAccountantsdossier() masks a foreign protocol as 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function testExportMasksAForeignProtocolAs404(): void {
		$this->canAccess = false;
		$this->service->method('organisationIdFor')->willReturn('adm-9');
		$this->exporter->expects($this->never())->method('exportDossier');

		$response = $this->controller->exportAccountantsdossier();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Controleprotocol not found', $response->getData()['error']);

	}//end testExportMasksAForeignProtocolAs404()

	/**
	 * A member of the owning organisation still gets the export envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function testAMemberStillGetsTheExport(): void {
		$this->service->method('organisationIdFor')->willReturn('adm-1');
		$this->exporter->expects($this->once())
			->method('exportDossier')
			->willReturn(['signaturePending' => true]);

		$response = $this->controller->exportAccountantsdossier();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['signaturePending']);

	}//end testAMemberStillGetsTheExport()
}//end class
