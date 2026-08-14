<?php

/**
 * Unit tests for ConfirmationApiController.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ConfirmationApiController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies HTTP status mapping, input validation and appointment masking
 * against the post-redesign 7-dep ConfirmationApiController constructor.
 */
final class ConfirmationApiControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock DI container (lazy OR ObjectService).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock settings.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock administration context (IDOR guard).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock token service.
	 *
	 * @var ConfirmationTokenService&MockObject
	 */
	private ConfirmationTokenService&MockObject $tokens;

	/**
	 * Mock time factory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var ConfirmationApiController
	 */
	private ConfirmationApiController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->tokens = $this->createMock(ConfirmationTokenService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// OR is always available unless a specific test overrides it.
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		// Deterministic clock for confirmedAt assertions. ITimeFactory's
		// contract returns mutable \DateTime; using \DateTimeImmutable here
		// triggers MockObject's strict return-type check.
		$this->time->method('getDateTime')->willReturn(
			new \DateTime('2026-05-22T12:30:00Z')
		);

		$this->controller = new ConfirmationApiController(
			$this->request,
			$this->container,
			$this->settings,
			$this->context,
			$this->tokens,
			$this->time,
			$this->createMock(IThrottler::class),
			$this->logger,
		);
	}//end setUp()

	/**
	 * lookupByToken without `token` query param yields a 400 without
	 * touching the token service.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingParams(): void {
		$this->request->method('getParam')->willReturn('');
		$this->tokens->expects($this->never())->method('validate');

		$response = $this->controller->lookupByToken();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testValidateRejectsMissingParams()

	/**
	 * A valid token returns 200 with a portal-safe appointment projection
	 * (no administrationId, no confirmationTokenId).
	 *
	 * @return void
	 */
	public function testValidateReturnsMaskedAppointment(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['token', '', 'rawtoken'],
				['appointmentId', '', 'apt-1'],
			]
		);
		$this->tokens->method('validate')->willReturn(
			['ok' => true, 'reason' => 'ok', 'token' => ['tokenId' => 'tok-1']]
		);

		$objectService = $this->makeObjectService(
			[
				[
					'appointmentId' => 'APT-1',
					'serviceName' => 'Consult',
					'startTime' => '2026-05-22T12:30:00Z',
					'administrationId' => 'adm-1',
					'confirmationTokenId' => 'tok-1',
				],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->controller->lookupByToken();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame('APT-1', $data['appointment']['appointmentId']);
		// Internal fields must not leak through the portal projection.
		self::assertArrayNotHasKey('administrationId', $data['appointment']);
		self::assertArrayNotHasKey('confirmationTokenId', $data['appointment']);
	}//end testValidateReturnsMaskedAppointment()

	/**
	 * An expired token surfaces in the dry-run body as `{ok:false,reason:expired}`
	 * on a HTTP 200 (so the portal can render a localised message). The
	 * status-mapping for confirm is covered separately by testConfirmRedeemedMapsTo403.
	 *
	 * @return void
	 */
	public function testValidateExpiredMapsTo403(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['token', '', 'rawtoken'],
				['appointmentId', '', 'apt-1'],
			]
		);
		$this->tokens->method('validate')->willReturn(
			['ok' => false, 'reason' => 'expired']
		);

		$response = $this->controller->lookupByToken();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('expired', $data['reason']);
	}//end testValidateExpiredMapsTo403()

	/**
	 * An invalid token surfaces in the dry-run body as `{ok:false,reason:invalid}`
	 * on a HTTP 200.
	 *
	 * @return void
	 */
	public function testValidateInvalidMapsTo401(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['token', '', 'rawtoken'],
				['appointmentId', '', 'apt-1'],
			]
		);
		$this->tokens->method('validate')->willReturn(
			['ok' => false, 'reason' => 'invalid']
		);

		$response = $this->controller->lookupByToken();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['ok']);
		self::assertSame('invalid', $data['reason']);
	}//end testValidateInvalidMapsTo401()

	/**
	 * A successful confirm returns 200 with the confirmed appointment.
	 *
	 * @return void
	 */
	public function testConfirmSuccess(): void {
		$this->request->method('getParam')->willReturn('rawtoken');
		$this->tokens->method('validate')->willReturn(
			['ok' => true, 'reason' => 'ok', 'token' => ['tokenId' => 'tok-1']]
		);

		$objectService = $this->makeObjectService(
			[['appointmentId' => 'APT-1', 'status' => 'pending_confirmation']]
		);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->controller->confirm('apt-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('confirmed', $data['appointment']['status']);
	}//end testConfirmSuccess()

	/**
	 * Confirming with an already-redeemed token maps to HTTP 403.
	 *
	 * @return void
	 */
	public function testConfirmRedeemedMapsTo403(): void {
		$this->request->method('getParam')->willReturn('rawtoken');
		$this->tokens->method('validate')->willReturn(
			['ok' => false, 'reason' => 'already_redeemed']
		);

		$response = $this->controller->confirm('apt-1');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testConfirmRedeemedMapsTo403()

	/**
	 * Resend success returns a 200 with `{ok:true,tokenId,sent}`. Authenticated
	 * customer of the appointment is the canonical happy path.
	 *
	 * @return void
	 */
	public function testResendSuccess(): void {
		$this->context->method('currentUserId')->willReturn('alice');

		$objectService = $this->makeObjectService(
			[
				[
					'appointmentId' => 'APT-1',
					'customerUserId' => 'alice',
					'customerEmail' => 'alice@example.nl',
				],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$this->tokens->method('resend')->willReturn(
			['tokenId' => 'tok-2', 'sent' => true]
		);

		$response = $this->controller->resend('apt-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['ok']);
		self::assertSame('tok-2', $data['tokenId']);
		self::assertTrue($data['sent']);
	}//end testResendSuccess()

	/**
	 * Resend for a missing appointment maps to HTTP 404.
	 *
	 * @return void
	 */
	public function testResendNotFoundMapsTo404(): void {
		$this->context->method('currentUserId')->willReturn('alice');

		// findAll returns no records → controller surfaces 404.
		$objectService = $this->makeObjectService([]);
		$this->container->method('get')->willReturn($objectService);

		$response = $this->controller->resend('apt-x');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testResendNotFoundMapsTo404()

	/**
	 * Build a stub of OCA\OpenRegister\Service\ObjectService that returns a
	 * canned list from setRegister/setSchema/findAll, and is happy with
	 * saveObject() calls (returning whatever it was passed).
	 *
	 * @param array<int,array<string,mixed>> $records Records to return.
	 *
	 * @return object
	 */
	private function makeObjectService(array $records): object {
		return new class($records) {
			/**
			 * Canned records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $records;

			/**
			 * @param array<int,array<string,mixed>> $records Canned records.
			 */
			public function __construct(array $records) {
				$this->records = $records;
			}

			/**
			 * @param string $_register Ignored.
			 *
			 * @return self
			 */
			public function setRegister(string $_register): self {
				return $this;
			}

			/**
			 * @param string $_schema Ignored.
			 *
			 * @return self
			 */
			public function setSchema(string $_schema): self {
				return $this;
			}

			/**
			 * @param array<string,mixed> $_filters Ignored.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $_filters = []): array {
				return $this->records;
			}

			/**
			 * Echo back the object as the controller expects a savable result.
			 *
			 * @param array<string,mixed> $object Object payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				return $object;
			}
		};
	}//end makeObjectService()
}//end class
