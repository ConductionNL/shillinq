<?php

/**
 * Unit tests for ConfirmationTokenService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
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

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use DateTime;
use DateTimeZone;
use OCA\Shillinq\Service\Booking\ConfirmationTokenService;
use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCA\Shillinq\Service\IcsService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\TokenValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Issuing, validating, redeeming, revoking and sending appointment
 * confirmation tokens over a fake OpenRegister store. Only a bcrypt hash is
 * stored; the plaintext goes out in the link alone. "Now" comes from the
 * mocked time factory.
 *
 * @covers \OCA\Shillinq\Service\Booking\ConfirmationTokenService
 * @uses   \OCA\Shillinq\Util\TokenValidator
 * @uses   \OCA\Shillinq\Service\IcsService
 * @uses   \OCA\Shillinq\Service\Booking\TimezoneResolver
 * @uses   \OCA\Shillinq\Support\FleetAppId
 */
class ConfirmationTokenServiceTest extends TestCase {

	/**
	 * The time factory's now.
	 */
	private const NOW = '2026-05-16T10:00:00Z';

	/**
	 * A bcrypt hash of "secret-token", computed once.
	 *
	 * @var string|null
	 */
	private static ?string $hash = null;

	/**
	 * Rows the fake findAll() answers.
	 *
	 * @var array<int, mixed>
	 */
	public array $found = [];

	/**
	 * Every findAll() call, as [register, schema, config, rbac].
	 *
	 * @var array<int, array>
	 */
	public array $finds = [];

	/**
	 * Every saveObject() call, as [object, register, schema, rbac].
	 *
	 * @var array<int, array>
	 */
	public array $saves = [];

	/**
	 * What saveObject() answers; null to echo the object.
	 *
	 * @var mixed
	 */
	public mixed $saveAnswer = null;

	/**
	 * Whether the store throws.
	 *
	 * @var boolean
	 */
	public bool $storeFails = false;

	/**
	 * Whether OpenRegister is available.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * The notification dispatcher integriq offers; null when absent.
	 *
	 * @var object|null
	 */
	private ?object $dispatcher = null;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the logger and the shared hash.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		self::$hash ??= TokenValidator::hash('secret-token');
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ConfirmationTokenService
	 */
	private function service(): ConfirmationTokenService {
		$store     = $this->store();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				if ($id === 'OCA\\Integriq\\Service\\NotificationDispatcher' && $this->dispatcher !== null) {
					return $this->dispatcher;
				}

				throw new RuntimeException('not registered: '.$id);
			}
		);

		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturnCallback(fn (): bool => $this->openRegister);
		$location->method('getRegisterSlug')->willReturn('afspraken');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-05-16 12:00:00', new DateTimeZone('Europe/Amsterdam')));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->with('shillinq.dashboard.page')->willReturn('/apps/shillinq/');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $relative): string => 'https://cloud.example'.$relative);

		$ics = new IcsService(new TimezoneResolver($this->createMock(IConfig::class), $this->createMock(LoggerInterface::class)), $this->createMock(LoggerInterface::class));

		return new ConfirmationTokenService($container, $location, $ics, $time, $urls, $this->logger);
	}//end service()

	/**
	 * The fake OpenRegister object service.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class($this) {
			/**
			 * @var string
			 */
			private string $register = '';

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param ConfirmationTokenServiceTest $test The test.
			 */
			public function __construct(private readonly ConfirmationTokenServiceTest $test) {
			}

			/**
			 * @param string $register The register.
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array   $config The query.
			 * @param boolean $_rbac  Whether RBAC applies.
			 * @return array
			 */
			public function findAll(array $config, bool $_rbac=true): array {
				if ($this->test->storeFails === true) {
					throw new RuntimeException('store down');
				}

				$this->test->finds[] = [$this->register, $this->schema, $config, $_rbac];
				return $this->test->found;
			}

			/**
			 * @param array   $object   The object.
			 * @param string  $register The register.
			 * @param string  $schema   The schema.
			 * @param boolean $_rbac    Whether RBAC applies.
			 * @return mixed
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac=true): mixed {
				if ($this->test->storeFails === true) {
					throw new RuntimeException('store down');
				}

				$this->test->saves[] = [$object, $register, $schema, $_rbac];
				return ($this->test->saveAnswer ?? $object);
			}
		};
	}//end store()

	/**
	 * A stored token for appointment a-1.
	 *
	 * @param array $overrides Fields to change.
	 *
	 * @return array
	 */
	private function token(array $overrides=[]): array {
		return array_merge(
			['tokenId' => 'tok-1', 'appointmentId' => 'a-1', 'tokenString' => self::$hash, 'expiresAt' => '2026-05-20T00:00:00Z', 'status' => 'active'],
			$overrides
		);
	}//end token()

	/**
	 * Issuing stores only a hash of the plaintext, valid for a week, outside RBAC.
	 *
	 * @return void
	 */
	public function testIssuingStoresOnlyAHash(): void {
		$issued = $this->service()->issueToken(appointmentId: 'a-1', createdBy: 'alice');

		[$saved, $register, $schema, $rbac] = $this->saves[0];
		$this->assertSame(['afspraken', 'ConfirmationToken', false], [$register, $schema, $rbac]);
		$this->assertSame(TokenValidator::TOKEN_LENGTH, strlen($issued['plaintext']));
		$this->assertNotSame($issued['plaintext'], $saved['tokenString']);
		$this->assertTrue(password_verify($issued['plaintext'], $saved['tokenString']));
		$this->assertSame(
			['appointmentId' => 'a-1', 'expiresAt' => '2026-05-23T10:00:00Z', 'status' => 'active', 'createdAt' => '2026-05-16T10:00:00Z', 'createdBy' => 'alice'],
			array_intersect_key($saved, array_flip(['appointmentId', 'expiresAt', 'status', 'createdAt', 'createdBy']))
		);
		$this->assertMatchesRegularExpression('/^tok-\d{14}-[0-9a-f]{4}$/', $issued['tokenId']);
		$this->assertSame($issued['tokenId'], $saved['tokenId']);
		$this->assertSame($saved, $issued['record']);
	}//end testIssuingStoresOnlyAHash()

	/**
	 * Without OpenRegister the token is answered unsaved; a failing save is logged and answered unsaved.
	 *
	 * @return void
	 */
	public function testAnUnsavedTokenIsStillAnswered(): void {
		$this->openRegister = false;
		$issued             = $this->service()->issueToken(appointmentId: 'a-1');
		$this->assertSame([], $this->saves);
		$this->assertSame('system', $issued['record']['createdBy']);

		$this->openRegister = true;
		$this->storeFails   = true;
		$this->logger->expects($this->once())
			->method('error')
			->with('ConfirmationTokenService: save failed', $this->callback(static fn (array $c): bool => $c['exception'] === 'store down' && str_starts_with($c['tokenId'], 'tok-')));
		$issued = $this->service()->issueToken(appointmentId: 'a-1');
		$this->assertSame('a-1', $issued['record']['appointmentId']);
	}//end testAnUnsavedTokenIsStillAnswered()

	/**
	 * A saved entity is read back through jsonSerialize().
	 *
	 * @return void
	 */
	public function testASavedEntityIsReadBack(): void {
		$this->saveAnswer = new class implements \JsonSerializable {
			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['tokenId' => 'from-store'];
			}
		};

		$this->assertSame(['tokenId' => 'from-store'], $this->service()->issueToken(appointmentId: 'a-1')['record']);
	}//end testASavedEntityIsReadBack()

	/**
	 * The active token is looked up by appointment and status, outside RBAC; entities are read back.
	 *
	 * @return void
	 */
	public function testTheActiveTokenIsLookedUp(): void {
		$entity = new class {
			/**
			 * @return array
			 */
			public function getObject(): array {
				return ['tokenId' => 'tok-entity'];
			}
		};
		$this->found = [$entity, ['tokenId' => 'second']];

		$this->assertSame(['tokenId' => 'tok-entity'], $this->service()->findActiveTokenForAppointment(appointmentId: 'a-1'));
		$this->assertSame(['afspraken', 'ConfirmationToken', ['filters' => ['appointmentId' => 'a-1', 'status' => 'active'], 'limit' => 5], false], $this->finds[0]);
	}//end testTheActiveTokenIsLookedUp()

	/**
	 * No store, no row or a failing lookup all mean no token; the failure is logged.
	 *
	 * @return void
	 */
	public function testNoActiveToken(): void {
		$this->assertNull($this->service()->findActiveTokenForAppointment(appointmentId: 'a-1'));

		$this->openRegister = false;
		$this->assertNull($this->service()->findActiveTokenForAppointment(appointmentId: 'a-1'));

		$this->openRegister = true;
		$this->storeFails   = true;
		$this->logger->expects($this->once())->method('error')->with('ConfirmationTokenService: lookup failed', ['exception' => 'store down', 'appointmentId' => 'a-2']);
		$this->assertNull($this->service()->findActiveTokenForAppointment(appointmentId: 'a-2'));
	}//end testNoActiveToken()

	/**
	 * Every refusal reason, and the one way through.
	 *
	 * @return void
	 */
	public function testValidation(): void {
		$service = $this->service();
		$this->assertSame(['ok' => false, 'reason' => 'invalid_input'], $service->validate('', 'secret-token'));
		$this->assertSame(['ok' => false, 'reason' => 'invalid_input'], $service->validate('a-1', ''));
		$this->assertSame(['ok' => false, 'reason' => 'not_found'], $service->validate('a-1', 'secret-token'));

		$cases = [
			'already_redeemed' => $this->token(['status' => 'redeemed']),
			'revoked'          => $this->token(['status' => 'revoked']),
			'expired'          => $this->token(['status' => 'expired']),
		];
		foreach ($cases as $reason => $token) {
			$this->found = [$token];
			$this->assertSame(['ok' => false, 'reason' => $reason, 'token' => $token], $service->validate('a-1', 'secret-token'), $reason);
		}

		foreach (['2026-05-16T10:00:00Z', '2026-05-01T00:00:00Z', 'not-a-date'] as $expiresAt) {
			$this->found = [$this->token(['expiresAt' => $expiresAt])];
			$this->assertSame('expired', $service->validate('a-1', 'secret-token')['reason'], $expiresAt);
		}

		$this->found = [$this->token()];
		$this->assertSame('invalid', $service->validate('a-1', 'wrong-token')['reason']);
		$this->found = [$this->token(['tokenString' => ''])];
		$this->assertSame('invalid', $service->validate('a-1', 'secret-token')['reason']);

		$this->found = [$this->token(['expiresAt' => '2026-05-16T10:00:01Z'])];
		$this->assertSame(['ok' => true, 'reason' => 'ok', 'token' => $this->token(['expiresAt' => '2026-05-16T10:00:01Z'])], $service->validate('a-1', 'secret-token'));
	}//end testValidation()

	/**
	 * Redeeming marks the token and stamps the time.
	 *
	 * @return void
	 */
	public function testRedeemingMarksTheToken(): void {
		$saved = $this->service()->markRedeemed(token: $this->token());

		$this->assertSame('redeemed', $saved['status']);
		$this->assertSame('2026-05-16T10:00:00Z', $saved['redeemedAt']);
		$this->assertSame('tok-1', $this->saves[0][0]['tokenId']);
	}//end testRedeemingMarksTheToken()

	/**
	 * Revoking marks the active token revoked; without one nothing is saved.
	 *
	 * @return void
	 */
	public function testRevokingTheActiveToken(): void {
		$service = $this->service();
		$this->assertNull($service->revokeActiveForAppointment(appointmentId: 'a-1'));
		$this->assertSame([], $this->saves);

		$this->found = [$this->token()];
		$this->assertSame('revoked', $service->revokeActiveForAppointment(appointmentId: 'a-1')['status']);
		$this->assertSame('revoked', $this->saves[0][0]['status']);
	}//end testRevokingTheActiveToken()

	/**
	 * The confirm link carries the encoded appointment and plaintext, absolute.
	 *
	 * @return void
	 */
	public function testTheConfirmUrl(): void {
		$this->assertSame(
			'https://cloud.example/apps/shillinq/confirm/a%2F1?token=t%26k%3D',
			$this->service()->buildConfirmUrl(appointmentId: 'a/1', plaintext: 't&k=')
		);
	}//end testTheConfirmUrl()

	/**
	 * Without an appointment id nothing is issued or sent.
	 *
	 * @return void
	 */
	public function testNothingIsSentWithoutAnAppointment(): void {
		$service = $this->service();

		$this->assertSame(['tokenId' => '', 'sent' => false], $service->issueAndSend([], ['email' => 'x@example.org']));
		$this->assertSame(['tokenId' => '', 'sent' => false], $service->resend([], ['email' => 'x@example.org']));
		$this->assertSame([], $this->saves);
	}//end testNothingIsSentWithoutAnAppointment()

	/**
	 * The email goes through integriq's dispatcher with the link and the ICS attached.
	 *
	 * @return void
	 */
	public function testTheEmailIsDispatched(): void {
		$this->dispatcher = new class {
			/**
			 * @var array<int, array>
			 */
			public array $sent = [];

			/**
			 * @param array $payload The email.
			 * @return void
			 */
			public function dispatch(array $payload): void {
				$this->sent[] = $payload;
			}
		};
		$appointment = ['appointmentId' => 'a-1', 'serviceName' => 'Paspoort', 'startTime' => '2026-05-20T09:00:00Z', 'endTime' => '2026-05-20T09:30:00Z', 'location' => 'Stadhuis'];

		$result = $this->service()->issueAndSend($appointment, ['email' => 'jan@example.org', 'name' => 'Jan']);

		$this->assertTrue($result['sent']);
		$this->assertSame($this->saves[0][0]['tokenId'], $result['tokenId']);
		$payload = $this->dispatcher->sent[0];
		$this->assertSame('jan@example.org', $payload['to']);
		$this->assertSame('Confirmation needed: Paspoort on 2026-05-20T09:00:00Z', $payload['subject']);
		$this->assertSame('BookingConfirmationTemplate', $payload['template']);
		$this->assertSame(['customerName' => 'Jan', 'appointmentDate' => '2026-05-20T09:00:00Z', 'serviceName' => 'Paspoort', 'location' => 'Stadhuis'], array_diff_key($payload['variables'], ['confirmUrl' => true]));
		$this->assertStringStartsWith('https://cloud.example/apps/shillinq/confirm/a-1?token=', $payload['variables']['confirmUrl']);
		$plaintext = substr($payload['variables']['confirmUrl'], strlen('https://cloud.example/apps/shillinq/confirm/a-1?token='));
		$this->assertTrue(password_verify($plaintext, $this->saves[0][0]['tokenString']));
		$this->assertSame(['filename' => 'appointment.ics', 'contentType' => 'text/calendar; charset=utf-8'], array_diff_key($payload['attachments'][0], ['content' => true]));
		$this->assertStringContainsString('BEGIN:VCALENDAR', $payload['attachments'][0]['content']);
	}//end testTheEmailIsDispatched()

	/**
	 * Without a customer email nothing is dispatched, and that is logged.
	 *
	 * @return void
	 */
	public function testNoEmailMeansNoDispatch(): void {
		$this->logger->expects($this->once())->method('warning')->with('ConfirmationTokenService: no customer email; skipping dispatch.', ['appointmentId' => 'a-1']);

		$result = $this->service()->issueAndSend(['appointmentId' => 'a-1'], []);

		$this->assertFalse($result['sent']);
		$this->assertCount(1, $this->saves);
	}//end testNoEmailMeansNoDispatch()

	/**
	 * Without a dispatcher the email is only prepared; a failing dispatcher is logged too.
	 *
	 * @return void
	 */
	public function testAnUnavailableDispatcherSendsNothing(): void {
		$this->logger->expects($this->once())->method('info')->with('ConfirmationTokenService: openconnector unavailable; prepared email payload only.', ['to' => 'jan@example.org', 'subject' => 'Confirmation needed: your appointment on ']);
		$this->assertFalse($this->service()->issueAndSend(['appointmentId' => 'a-1'], ['email' => 'jan@example.org'])['sent']);
	}//end testAnUnavailableDispatcherSendsNothing()

	/**
	 * A dispatcher that throws is logged and nothing counts as sent.
	 *
	 * @return void
	 */
	public function testAFailingDispatcherIsLogged(): void {
		$this->dispatcher = new class {
			/**
			 * @return never
			 */
			public function dispatch(): never {
				throw new RuntimeException('smtp down');
			}
		};
		$this->logger->expects($this->once())->method('error')->with('ConfirmationTokenService: openconnector dispatch failed', ['exception' => 'smtp down']);
		$this->logger->expects($this->once())->method('info');

		$this->assertFalse($this->service()->issueAndSend(['appointmentId' => 'a-1'], ['email' => 'jan@example.org'])['sent']);
	}//end testAFailingDispatcherIsLogged()

	/**
	 * Resending revokes the active token first and issues a new one by the actor.
	 *
	 * @return void
	 */
	public function testResendRevokesThenIssues(): void {
		$this->found = [$this->token()];

		$result = $this->service()->resend(['appointmentId' => 'a-1'], [], 'bob');

		$this->assertSame('revoked', $this->saves[0][0]['status']);
		$this->assertSame('tok-1', $this->saves[0][0]['tokenId']);
		$this->assertSame('active', $this->saves[1][0]['status']);
		$this->assertSame('bob', $this->saves[1][0]['createdBy']);
		$this->assertSame(['tokenId' => $this->saves[1][0]['tokenId'], 'sent' => false], $result);
	}//end testResendRevokesThenIssues()
}//end class
