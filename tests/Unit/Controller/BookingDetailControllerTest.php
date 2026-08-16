<?php

/**
 * Unit tests for BookingDetailController — pipelinq customer-bridge slice 05.
 *
 * Exercises the three behavioural scenarios the spec delta calls out:
 *  - linked booking → profile + history are hydrated from the adapter.
 *  - unlinked booking → `notLinkedToPipelinq` flag set, adapter never
 *    called.
 *  - adapter failure → `contactError` sanitised, booking detail still
 *    renders 200.
 *
 * Also covers the dev/test cache-bust path (`?nocache=1` clears the
 * slice-03 contact cache) and the IDOR / 404 / 401 / 503 happy-path
 * statuses.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BookingDetailController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldResult;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldTransaction;
use OCA\Shillinq\Service\Pipelinq\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingDetailController (slice 05).
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 */
final class BookingDetailControllerTest extends TestCase {

	/**
	 * Mocked IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mocked context service.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mocked pipelinq adapter.
	 *
	 * @var PipelinqContactAdapter&MockObject
	 */
	private PipelinqContactAdapter&MockObject $pipelinq;

	/**
	 * In-memory OR ObjectService double.
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * Container resolving the fake ObjectService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Per-test query parameter overrides.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Build the fake stack for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->pipelinq = $this->createMock(PipelinqContactAdapter::class);

		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return ($this->params[$key] ?? $default);
			}
		);

		$this->fakeObjectService = $this->buildFakeObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->fakeObjectService);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return BookingDetailController
	 */
	private function buildController(): BookingDetailController {
		return new BookingDetailController(
			$this->request,
			$this->container,
			$this->settings,
			$this->context,
			$this->pipelinq,
			$this->createMock(LoggerInterface::class),
		);

	}//end buildController()

	/**
	 * Fake ObjectService that mimics the OR query-builder fluent API.
	 *
	 * @return object
	 */
	private function buildFakeObjectService(): object {
		return new class() {
			/**
			 * Canonical seed rows keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $records = [
				'Appointment' => [
					[
						'appointmentId' => 'apt-linked',
						'administrationId' => 'adm-1',
						'serviceId' => 'svc-1',
						'resourceId' => 'res-1',
						'customerId' => 'cus-1',
						'customerName' => 'Anna de Wit',
						'customerEmail' => 'anna@example.test',
						'startTime' => '2026-06-01T10:00:00Z',
						'endTime' => '2026-06-01T10:30:00Z',
						'status' => 'confirmed',
						'pipelinqContactId' => 'org-kvk-12345678',
					],
					[
						// An Appointment with NO owning administration. This is
						// the record shape that used to bypass the IDOR guard
						// entirely: `?? ''` turned absent/null into '', and the
						// caller's `!== ''` short-circuit then skipped canAccess()
						// altogether, making the row readable cross-tenant.
						'appointmentId' => 'apt-orphan',
						'administrationId' => '',
						'serviceId' => 'svc-1',
						'resourceId' => 'res-1',
						'customerId' => 'cus-9',
						'customerName' => 'Orphan Record',
						'startTime' => '2026-06-01T12:00:00Z',
						'endTime' => '2026-06-01T12:30:00Z',
						'status' => 'confirmed',
						'pipelinqContactId' => null,
					],
					[
						'appointmentId' => 'apt-unlinked',
						'administrationId' => 'adm-1',
						'serviceId' => 'svc-1',
						'resourceId' => 'res-1',
						'customerId' => 'cus-2',
						'customerName' => 'Kees Bakker',
						'startTime' => '2026-06-01T11:00:00Z',
						'endTime' => '2026-06-01T11:30:00Z',
						'status' => 'pending',
						'pipelinqContactId' => null,
					],
				],
			];

			private string $currentSchema = '';

			public function setRegister(string $_): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			public function findAll(array $opts = []): array {
				$rows = ($this->records[$this->currentSchema] ?? []);
				$filters = ($opts['filters'] ?? []);
				$matched = [];
				foreach ($rows as $row) {
					$ok = true;
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$ok = false;
							break;
						}
					}

					if ($ok === true) {
						$matched[] = $row;
					}
				}

				return $matched;
			}
		};
	}//end buildFakeObjectService()

	/**
	 * REQ-CB05-01 — a linked booking hydrates profile + history from the
	 * slice 03/04 adapter and returns 200.
	 *
	 * @return void
	 */
	public function testLinkedBookingHydratesProfileAndHistory(): void {
		$this->pipelinq
			->expects(self::once())
			->method('getContact')
			->with('org-kvk-12345678')
			->willReturn(
				new PipelinqContact(
					externalId: 'org-kvk-12345678',
					legalName: 'Acme B.V.',
					email: 'billing@acme.example',
					phone: '+31 20 555 0123',
					address: 'Hoofdweg 1, Amsterdam',
					kvkNumber: '12345678',
					found: true,
				)
			);

		$this->pipelinq
			->expects(self::once())
			->method('fetchKlantbeeld')
			->with('org-kvk-12345678', 5, 0)
			->willReturn(
				KlantbeeldResult::available(
					transactions: [
						new KlantbeeldTransaction(
							date: '2026-05-20',
							description: 'Invoice #INV-001',
							amount: 125.00,
							currency: 'EUR',
							status: 'paid'
						),
					],
					limit: 5,
					offset: 0
				)
			);

		$response = $this->buildController()->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertFalse($data['notLinkedToPipelinq']);
		self::assertNull($data['contactError']);
		self::assertSame('apt-linked', $data['booking']['appointmentId']);
		self::assertSame('org-kvk-12345678', $data['booking']['pipelinqContactId']);
		self::assertSame('Acme B.V.', $data['contact']['legalName']);
		self::assertTrue($data['contact']['found']);
		self::assertSame(5, $data['klantbeeld']['limit']);
		self::assertSame(0, $data['klantbeeld']['offset']);
		self::assertFalse($data['klantbeeld']['unavailable']);
		self::assertFalse($data['klantbeeld']['empty']);
		self::assertCount(1, $data['klantbeeld']['transactions']);

	}//end testLinkedBookingHydratesProfileAndHistory()

	/**
	 * REQ-CB05-02 — an unlinked booking sets the "not linked" flag and
	 * never calls the adapter.
	 *
	 * @return void
	 */
	public function testUnlinkedBookingSetsNotLinkedFlag(): void {
		$this->pipelinq->expects(self::never())->method('getContact');
		$this->pipelinq->expects(self::never())->method('fetchKlantbeeld');

		$response = $this->buildController()->show(id: 'apt-unlinked');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertTrue($data['notLinkedToPipelinq']);
		self::assertNull($data['contact']);
		self::assertNull($data['klantbeeld']);
		self::assertNull($data['contactError']);
		self::assertSame('apt-unlinked', $data['booking']['appointmentId']);
		self::assertNull($data['booking']['pipelinqContactId']);

	}//end testUnlinkedBookingSetsNotLinkedFlag()

	/**
	 * REQ-CB05-03 — adapter failure on Contact lookup sets
	 * `contactError` (sanitised) and the booking detail still renders
	 * 200. Klantbeeld is skipped because there is no profile to attach
	 * history to.
	 *
	 * @return void
	 */
	public function testAdapterFailureDegradesGracefully(): void {
		$this->pipelinq
			->expects(self::once())
			->method('getContact')
			->with('org-kvk-12345678')
			->willThrowException(
				new PipelinqTransportException(
					message: 'pipelinq /contacts/org-kvk-12345678 returned 502',
					statusCode: 502
				)
			);

		$this->pipelinq->expects(self::never())->method('fetchKlantbeeld');

		$response = $this->buildController()->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertNotNull($data['contactError']);
		self::assertStringContainsString('temporarily unavailable', $data['contactError']);
		// The sanitised message MUST NOT echo the raw upstream body.
		self::assertStringNotContainsString('502', $data['contactError']);
		self::assertStringNotContainsString('/contacts/', $data['contactError']);
		self::assertSame('apt-linked', $data['booking']['appointmentId']);
		self::assertFalse($data['notLinkedToPipelinq']);
		self::assertNull($data['contact']);

	}//end testAdapterFailureDegradesGracefully()

	/**
	 * Klantbeeld envelope returning "unavailable" still renders the
	 * profile + booking; the controller surfaces the unavailable marker
	 * to the UI so slice 06 can keep the profile card and hide history.
	 *
	 * @return void
	 */
	public function testKlantbeeldUnavailableKeepsProfile(): void {
		$this->pipelinq
			->method('getContact')
			->willReturn(
				new PipelinqContact(
					externalId: 'org-kvk-12345678',
					legalName: 'Acme B.V.',
					found: true
				)
			);

		$this->pipelinq
			->method('fetchKlantbeeld')
			->willReturn(KlantbeeldResult::unavailable(limit: 5, offset: 0));

		$response = $this->buildController()->show(id: 'apt-linked');
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Acme B.V.', $data['contact']['legalName']);
		self::assertTrue($data['klantbeeld']['unavailable']);
		self::assertFalse($data['klantbeeld']['empty']);
		self::assertNull($data['contactError']);

	}//end testKlantbeeldUnavailableKeepsProfile()

	/**
	 * `?nocache=1` triggers a slice-03 contact cache wipe before the
	 * adapter is re-queried — dev/test cache-bust hook.
	 *
	 * @return void
	 */
	public function testNocacheClearsContactCache(): void {
		$this->params['nocache'] = '1';

		$this->pipelinq->expects(self::once())->method('clearCache');
		$this->pipelinq
			->method('getContact')
			->willReturn(
				new PipelinqContact(externalId: 'org-kvk-12345678', found: true)
			);
		$this->pipelinq
			->method('fetchKlantbeeld')
			->willReturn(KlantbeeldResult::available(transactions: [], limit: 5, offset: 0));

		$response = $this->buildController()->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testNocacheClearsContactCache()

	/**
	 * Missing path id returns 400 BAD REQUEST and never touches the
	 * adapter or the ObjectService.
	 *
	 * @return void
	 */
	public function testEmptyIdReturnsBadRequest(): void {
		$this->pipelinq->expects(self::never())->method('getContact');

		$response = $this->buildController()->show(id: '');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testEmptyIdReturnsBadRequest()

	/**
	 * Unknown appointment id returns 404 NOT FOUND.
	 *
	 * @return void
	 */
	public function testUnknownBookingReturnsNotFound(): void {
		$response = $this->buildController()->show(id: 'apt-does-not-exist');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownBookingReturnsNotFound()

	/**
	 * Out-of-tenant caller is masked as 404 per ADR-005 IDOR rules.
	 *
	 * @return void
	 */
	public function testOutOfTenantCallerIsMaskedAsNotFound(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('mallory');
		$context->method('canAccess')->willReturn(false);

		$controller = new BookingDetailController(
			$this->request,
			$this->container,
			$this->settings,
			$context,
			$this->pipelinq,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testOutOfTenantCallerIsMaskedAsNotFound()

	/**
	 * An Appointment with an EMPTY administrationId must still be guarded.
	 *
	 * This is the regression this test exists for. The guard used to read
	 * `if ($administrationId !== '' && $this->context->canAccess(...) === false)`.
	 * Because `?? ''` normalises an absent or null administrationId to '',
	 * the `!== ''` term made the whole condition false and canAccess() was
	 * NEVER CALLED — so a record with no owning administration was readable
	 * by any authenticated user, across tenants, in a bookkeeping app.
	 *
	 * The assertion that matters is `expects($this->once())` on canAccess:
	 * asserting only on the 404 status would also pass if the controller
	 * happened to 404 for some unrelated reason. What is being pinned is
	 * that the guard RUNS, with the empty value, and is allowed to refuse.
	 *
	 * @return void
	 */
	public function testAppointmentWithEmptyAdministrationIdIsMaskedAsNotFound(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('alice');

		// The real AdministrationContextService::canAccess() returns false for
		// '' on its own first line — it already fails closed. The bug was that
		// the caller never invoked it. Pin the invocation, with the empty value.
		$context->expects($this->once())
			->method('canAccess')
			->with('')
			->willReturn(false);

		$controller = new BookingDetailController(
			$this->request,
			$this->container,
			$this->settings,
			$context,
			$this->pipelinq,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->show(id: 'apt-orphan');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAppointmentWithEmptyAdministrationIdIsMaskedAsNotFound()

	/**
	 * Anonymous (unauthenticated) caller is rejected with 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRejected(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn(null);

		$controller = new BookingDetailController(
			$this->request,
			$this->container,
			$this->settings,
			$context,
			$this->pipelinq,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedCallerIsRejected()

	/**
	 * OpenRegister unavailable returns 503 SERVICE UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testOrUnavailableReturns503(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$controller = new BookingDetailController(
			$this->request,
			$this->container,
			$settings,
			$this->context,
			$this->pipelinq,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->show(id: 'apt-linked');
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testOrUnavailableReturns503()

}//end class
