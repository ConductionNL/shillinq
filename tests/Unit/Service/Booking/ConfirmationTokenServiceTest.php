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
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
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
	 * What the mailer was given: recipients, subject, body, attachments and how many sends.
	 *
	 * @var array<string, mixed>
	 */
	private array $mail = ['to' => null, 'subject' => null, 'body' => null, 'attachments' => [], 'created' => [], 'sends' => 0];

	/**
	 * What the mailer's send() does: the refused recipients it answers, or a Throwable it throws.
	 *
	 * @var array<int, string>|\Throwable
	 */
	private array|\Throwable $sendOutcome = [];

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

		return new ConfirmationTokenService($container, $location, $ics, $time, $urls, $this->logger, $this->mailer());
	}//end service()

	/**
	 * A mailer double that records the message it is given into $this->mail.
	 *
	 * @return IMailer
	 */
	private function mailer(): IMailer {
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
			$this->mail['to'] = $to;
			return $message;
		});
		$message->method('setSubject')->willReturnCallback(function (string $subject) use ($message): IMessage {
			$this->mail['subject'] = $subject;
			return $message;
		});
		$message->method('setPlainBody')->willReturnCallback(function (string $body) use ($message): IMessage {
			$this->mail['body'] = $body;
			return $message;
		});
		$message->method('attach')->willReturnCallback(function (IAttachment $attachment) use ($message): IMessage {
			$this->mail['attachments'][] = $attachment;
			return $message;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createAttachment')->willReturnCallback(function (string $data, string $name, string $type): IAttachment {
			$this->mail['created'][] = ['data' => $data, 'name' => $name, 'type' => $type];
			return $this->createMock(IAttachment::class);
		});
		$mailer->method('send')->willReturnCallback(function (): array {
			$this->mail['sends']++;
			if ($this->sendOutcome instanceof \Throwable) {
				throw $this->sendOutcome;
			}

			return $this->sendOutcome;
		});

		return $mailer;
	}//end mailer()

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
	 * The request goes through Nextcloud's mailer with the confirm link in the body and the ICS attached.
	 *
	 * @return void
	 */
	public function testTheRequestIsMailed(): void {
		$appointment = ['appointmentId' => 'a-1', 'serviceName' => 'Paspoort', 'startTime' => '2026-05-20T09:00:00Z', 'endTime' => '2026-05-20T09:30:00Z', 'location' => 'Stadhuis'];

		$result = $this->service()->issueAndSend($appointment, ['email' => 'jan@example.org', 'name' => 'Jan']);

		$this->assertTrue($result['sent']);
		$this->assertSame($this->saves[0][0]['tokenId'], $result['tokenId']);
		$this->assertSame(1, $this->mail['sends']);
		$this->assertSame(['jan@example.org' => 'Jan'], $this->mail['to']);
		$this->assertSame('Confirmation needed: Paspoort on 2026-05-20T09:00:00Z', $this->mail['subject']);
		$this->assertStringContainsString("Hello Jan,", $this->mail['body']);
		$this->assertStringContainsString("Where: Stadhuis", $this->mail['body']);
		$this->assertSame(1, preg_match('#Confirm here: (https://cloud\.example/apps/shillinq/confirm/a-1\?token=(\S+))#', $this->mail['body'], $link));
		$this->assertTrue(password_verify($link[2], $this->saves[0][0]['tokenString']), 'the link carries the plaintext of the stored hash');
		$this->assertCount(1, $this->mail['attachments']);
		$this->assertCount(1, $this->mail['created']);
		$attachment = $this->mail['created'][0];
		$this->assertSame(['name' => 'appointment.ics', 'type' => 'text/calendar; charset=utf-8'], array_diff_key($attachment, ['data' => true]));
		$this->assertStringContainsString('BEGIN:VCALENDAR', $attachment['data']);
	}//end testTheRequestIsMailed()

	/**
	 * Without a customer email nothing is sent, and that is logged.
	 *
	 * @return void
	 */
	public function testNoEmailMeansNoDispatch(): void {
		$this->logger->expects($this->once())->method('warning')->with('ConfirmationTokenService: no customer email; skipping dispatch.', ['appointmentId' => 'a-1']);

		$result = $this->service()->issueAndSend(['appointmentId' => 'a-1'], []);

		$this->assertFalse($result['sent']);
		$this->assertSame(0, $this->mail['sends']);
		$this->assertCount(1, $this->saves);
	}//end testNoEmailMeansNoDispatch()

	/**
	 * A refused recipient counts as not sent and is logged.
	 *
	 * @return void
	 */
	public function testARefusedRecipientIsNotSent(): void {
		$this->sendOutcome = ['jan@example.org'];
		$this->logger->expects($this->once())->method('warning')->with('ConfirmationTokenService: the mail server refused the confirmation request', ['appointmentId' => 'a-1']);

		$result = $this->service()->issueAndSend(['appointmentId' => 'a-1'], ['email' => 'jan@example.org']);

		$this->assertFalse($result['sent']);
		$this->assertSame(['jan@example.org' => 'jan@example.org'], $this->mail['to'], 'without a name the address names itself');
		$this->assertSame('Confirmation needed: your appointment on ', $this->mail['subject']);
	}//end testARefusedRecipientIsNotSent()

	/**
	 * A mailer that throws is logged and nothing counts as sent.
	 *
	 * @return void
	 */
	public function testAFailingMailerIsLogged(): void {
		$this->sendOutcome = new RuntimeException('smtp down');
		$this->logger->expects($this->once())->method('error')->with('ConfirmationTokenService: the confirmation request could not be sent', ['appointmentId' => 'a-1', 'exception' => 'smtp down']);

		$this->assertFalse($this->service()->issueAndSend(['appointmentId' => 'a-1'], ['email' => 'jan@example.org'])['sent']);
	}//end testAFailingMailerIsLogged()

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
