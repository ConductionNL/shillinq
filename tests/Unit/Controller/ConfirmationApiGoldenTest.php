<?php

/**
 * Golden tests for ConfirmationApiController: every answer, throttle and call.
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
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused answers of the token confirmation, the
 * lookup and the resend, with every throttle registration, token, transition,
 * lookup and log call in order, before the actions are split into named steps.
 */
final class ConfirmationApiGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Request params.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Whether OpenRegister is available.
	 *
	 * @var bool
	 */
	private bool $openRegister = true;

	/**
	 * Appointment records findAll returns, or null to throw.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $records = [];

	/**
	 * What validate() answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $validation = ['ok' => true, 'token' => ['tokenId' => 't-1']];

	/**
	 * What runAsSystem() answers, or a Throwable it throws.
	 *
	 * @var mixed
	 */
	private mixed $transition = ['appointmentId' => 'a-1', 'status' => 'confirmed', 'secret' => 'x'];

	/**
	 * The current user id (null = anonymous).
	 *
	 * @var string|null
	 */
	private ?string $user = 'u-1';

	/**
	 * canAccess() answer.
	 *
	 * @var bool
	 */
	private bool $canAccess = false;

	/**
	 * Whether the throttler fails.
	 *
	 * @var bool
	 */
	private bool $throttlerFails = false;

	/**
	 * Build the controller over the current fixture state.
	 *
	 * @return ConfirmationApiController
	 */
	private function controller(): ConfirmationApiController {
		$params = $this->params;
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('10.0.0.1');

		$test = $this;
		$records = $this->records;
		$objects = new class($test, $records) {
			/**
			 * @param ConfirmationApiGoldenTest $test    The test.
			 * @param array<int,mixed>|null     $records The records.
			 */
			public function __construct(
				private $test,
				private ?array $records,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config The query.
			 * @param bool                $_rbac  The RBAC flag.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true): array {
				$this->test->calls[] = 'findAll:' . json_encode($config) . ':' . var_export($_rbac, true);
				if ($this->records === null) {
					throw new \RuntimeException('store down');
				}

				return $this->records;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($this->openRegister);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($this->user);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->calls[] = 'canAccess:' . $administrationId;
				return $this->canAccess;
			}
		);

		$tokens = $this->createMock(ConfirmationTokenService::class);
		$tokens->method('validate')->willReturnCallback(
			function (string $appointmentId, string $token): array {
				$this->calls[] = 'validate:' . $appointmentId . ':' . $token;
				return $this->validation;
			}
		);
		$tokens->method('markRedeemed')->willReturnCallback(
			function (array $token): array {
				$this->calls[] = 'redeemed:' . json_encode($token);
				return $token;
			}
		);
		$tokens->method('resend')->willReturnCallback(
			function (array $appointment, array $customer, string $actor): array {
				$this->calls[] = 'resend:' . json_encode($customer) . ':' . $actor;
				return ['tokenId' => 't-9', 'sent' => true];
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-05-22T14:30:00+02:00'));

		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willReturnCallback(
			function (string $action, string $ip): void {
				$this->calls[] = 'throttle:' . $action . ':' . $ip;
				if ($this->throttlerFails === true) {
					throw new \RuntimeException('cache down');
				}
			}
		);

		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$transitions->method('runAsSystem')->willReturnCallback(
			function (string $objectId, string $action, array $data): mixed {
				$this->calls[] = 'transition:' . $objectId . ':' . $action . ':' . json_encode($data);
				if ($this->transition instanceof \Throwable) {
					throw $this->transition;
				}

				return $this->transition;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		return new ConfirmationApiController($request, $container, $settings, $context, $tokens, $time, $throttler, $transitions, $logger);
	}//end controller()

	/**
	 * Call an action and return status and body.
	 *
	 * @param string $action        confirm, resend or lookup.
	 * @param string $appointmentId The route appointment id.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function answer(string $action, string $appointmentId = ''): array {
		$controller = $this->controller();
		$response = match ($action) {
			'confirm' => $controller->confirm($appointmentId),
			'resend' => $controller->resend($appointmentId),
			default => $controller->lookupByToken(),
		};
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * The presented (portal) view of the fixture appointment.
	 *
	 * @return array<string, string>
	 */
	private static function presented(): array {
		return [
			'appointmentId' => 'a-1',
			'serviceName' => '',
			'startTime' => '',
			'endTime' => '',
			'location' => '',
			'status' => 'confirmed',
			'confirmationDeadline' => '',
			'notes' => '',
		];
	}//end presented()

	/**
	 * Confirm: missing input, then each refused token reason; only a token that
	 * was never real is throttled, and a failing throttler is logged.
	 *
	 * @return void
	 */
	public function testConfirmRefusals(): void {
		$this->params = ['token' => ' '];
		$this->assertSame([400, ['error' => 'appointmentId and token are required']], $this->answer('confirm', 'a-1'));
		$this->params = ['token' => 'tok'];
		$this->assertSame([400, ['error' => 'appointmentId and token are required']], $this->answer('confirm', ' '));
		$this->assertSame([], $this->calls);

		$reasons = [
			'expired' => [403, 'Token has expired'],
			'already_redeemed' => [403, 'Token has already been used'],
			'revoked' => [403, 'Token is no longer valid; request a new confirmation email'],
			'invalid' => [401, 'Invalid token'],
			'not_found' => [404, 'Token not found'],
			'other' => [400, 'Confirmation rejected'],
		];
		foreach ($reasons as $reason => [$status, $error]) {
			$this->calls = [];
			$this->validation = ['ok' => false, 'reason' => $reason];
			$this->assertSame([$status, ['error' => $error]], $this->answer('confirm', ' a-1 '));
			$throttled = in_array($reason, ['invalid', 'not_found'], true);
			$expected = ['validate:a-1:tok'];
			if ($throttled === true) {
				$expected[] = 'throttle:shillinq_confirmation_token:10.0.0.1';
			}

			$this->assertSame($expected, $this->calls, $reason);
		}

		$this->calls = [];
		$this->throttlerFails = true;
		$this->validation = ['ok' => false, 'reason' => 'invalid'];
		$this->assertSame([401, ['error' => 'Invalid token']], $this->answer('confirm', 'a-1'));
		$this->assertSame(
			['validate:a-1:tok', 'throttle:shillinq_confirmation_token:10.0.0.1', 'warning:ConfirmationApiController: registerAttempt failed: cache down[]'],
			$this->calls
		);
	}//end testConfirmRefusals()

	/**
	 * Confirm with a valid token: OpenRegister down, a failed or empty lookup,
	 * a record without uuid, a refused and a failed transition.
	 *
	 * @return void
	 */
	public function testConfirmFailures(): void {
		$this->params = ['token' => 'tok'];
		$this->openRegister = false;
		$this->assertSame([503, ['error' => 'OpenRegister unavailable']], $this->answer('confirm', 'a-1'));
		$this->openRegister = true;

		$this->records = null;
		$this->assertSame([404, ['error' => 'Appointment not found']], $this->answer('confirm', 'a-1'));
		$this->assertContains('error:ConfirmationApiController: appointment lookup failed{"exception":"store down","appointmentId":"a-1"}', $this->calls);
		$this->records = [];
		$this->assertSame([404, ['error' => 'Appointment not found']], $this->answer('confirm', 'a-1'));

		$this->calls = [];
		$this->records = [['appointmentId' => 'a-1']];
		$this->assertSame([409, ['error' => 'This appointment can no longer be confirmed']], $this->answer('confirm', 'a-1'));
		$this->assertSame(
			[
				'validate:a-1:tok',
				'findAll:{"filters":{"appointmentId":"a-1"},"limit":1}:false',
				'warning:ConfirmationApiController: confirm transition refused{"appointmentId":"a-1","exception":"Appointment record carries no uuid"}',
			],
			$this->calls
		);

		$this->records = [['id' => 'uuid-1', 'appointmentId' => 'a-1']];
		$this->transition = new \RuntimeException('not pending');
		$this->assertSame([409, ['error' => 'This appointment can no longer be confirmed']], $this->answer('confirm', 'a-1'));
		$this->transition = new \LogicException('db');
		$this->assertSame([500, ['error' => 'Confirmation failed']], $this->answer('confirm', 'a-1'));
		$this->assertSame('error:ConfirmationApiController: confirm failed{"appointmentId":"a-1","exception":"db"}', end($this->calls));
		$this->assertNotContains('redeemed:{"tokenId":"t-1"}', $this->calls);
	}//end testConfirmFailures()

	/**
	 * Confirm succeeds: the transition as system with the UTC time and token id,
	 * then the redemption and the log line; the saved record is presented,
	 * whether it is an array, a serialisable entity or an entity with getObject().
	 *
	 * @return void
	 */
	public function testConfirmSucceeds(): void {
		$this->params = ['token' => 'tok'];
		$this->records = [['@self' => ['id' => 'uuid-1'], 'id' => 'other', 'appointmentId' => 'a-1']];

		$this->assertSame([200, ['appointment' => self::presented()]], $this->answer('confirm', 'a-1'));
		$this->assertSame(
			[
				'validate:a-1:tok',
				'findAll:{"filters":{"appointmentId":"a-1"},"limit":1}:false',
				'transition:uuid-1:confirmViaToken:{"confirmedAt":"2026-05-22T12:30:00Z","confirmationTokenId":"t-1"}',
				'redeemed:{"tokenId":"t-1"}',
				'info:ConfirmationApiController: appointment confirmed{"appointmentId":"a-1","tokenId":"t-1"}',
			],
			$this->calls
		);

		$data = ['appointmentId' => 'a-1', 'status' => 'confirmed'];
		$this->transition = new class($data) implements \JsonSerializable {
			/**
			 * @param array<string,string> $data The data.
			 */
			public function __construct(
				private array $data,
			) {
			}//end __construct()

			/**
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
		$this->assertSame([200, ['appointment' => self::presented()]], $this->answer('confirm', 'a-1'));

		$this->transition = new class($data) {
			/**
			 * @param array<string,string> $data The data.
			 */
			public function __construct(
				private array $data,
			) {
			}//end __construct()

			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not an array';
			}//end jsonSerialize()

			/**
			 * @return array<string,string>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()
		};
		$this->assertSame([200, ['appointment' => self::presented()]], $this->answer('confirm', 'a-1'));

		$this->transition = 'scalar';
		$this->assertSame([200, ['appointment' => array_map(static fn (): string => '', self::presented())]], $this->answer('confirm', 'a-1'));
	}//end testConfirmSucceeds()

	/**
	 * Lookup: the refusals in their order, a refused token answered 200 without
	 * throttling, an unknown appointment and the presented appointment.
	 *
	 * @return void
	 */
	public function testLookup(): void {
		$this->openRegister = false;
		$this->assertSame([400, ['ok' => false, 'reason' => 'invalid_input']], $this->answer('lookup'));
		$this->params = ['token' => 'tok'];
		$this->assertSame([503, ['ok' => false, 'reason' => 'or_unavailable']], $this->answer('lookup'));
		$this->openRegister = true;
		$this->assertSame([400, ['ok' => false, 'reason' => 'missing_appointment_id']], $this->answer('lookup'));
		$this->assertSame([], $this->calls);

		$this->params = ['token' => ' tok ', 'appointmentId' => ' a-1 '];
		$this->validation = ['ok' => false, 'reason' => 'invalid'];
		$this->assertSame([200, ['ok' => false, 'reason' => 'invalid']], $this->answer('lookup'));
		$this->assertSame(['validate:a-1:tok'], $this->calls);

		$this->validation = ['ok' => true, 'token' => ['tokenId' => 't-1']];
		$this->assertSame([404, ['ok' => false, 'reason' => 'not_found']], $this->answer('lookup'));
		$this->records = [(object)['appointmentId' => 'a-1', 'status' => 'confirmed']];
		$this->assertSame([200, ['ok' => true, 'appointment' => self::presented()]], $this->answer('lookup'));
	}//end testLookup()

	/**
	 * Resend: the refusals in order, every way a caller is authorised, and the
	 * resend with the customer, actor, log line and body.
	 *
	 * @return void
	 */
	public function testResend(): void {
		$this->user = null;
		$this->assertSame([401, ['error' => 'Not authenticated']], $this->answer('resend', 'a-1'));
		$this->user = 'u-1';
		$this->assertSame([400, ['error' => 'appointmentId is required']], $this->answer('resend', ' '));
		$this->openRegister = false;
		$this->assertSame([503, ['error' => 'OpenRegister unavailable']], $this->answer('resend', 'a-1'));
		$this->openRegister = true;
		$this->assertSame([404, ['error' => 'Appointment not found']], $this->answer('resend', 'a-1'));

		$this->calls = [];
		$this->records = [['appointmentId' => 'a-1', 'administrationId' => 'adm-1', 'customerUserId' => 'other']];
		$this->assertSame([403, ['error' => 'Forbidden']], $this->answer('resend', 'a-1'));
		$this->assertSame(['findAll:{"filters":{"appointmentId":"a-1"},"limit":1}:false', 'canAccess:adm-1'], $this->calls);

		$ok = [200, ['ok' => true, 'tokenId' => 't-9', 'sent' => true, 'message' => 'Confirmation email resent']];
		foreach (['customerUserId', 'customerId', 'createdBy'] as $field) {
			$this->records = [['appointmentId' => 'a-1', $field => 'u-1']];
			$this->assertSame($ok, $this->answer('resend', 'a-1'), $field);
		}

		$this->calls = [];
		$this->canAccess = true;
		$this->records = [['appointmentId' => 'a-1', 'administrationId' => 'adm-1', 'customerName' => 'Ann', 'customerEmail' => 'a@x']];
		$this->assertSame($ok, $this->answer('resend', ' a-1 '));
		$this->assertSame(
			[
				'findAll:{"filters":{"appointmentId":"a-1"},"limit":1}:false',
				'canAccess:adm-1',
				'resend:{"id":"","userId":"u-1","name":"Ann","email":"a@x"}:u-1',
				'info:ConfirmationApiController: confirmation email resent{"appointmentId":"a-1","tokenId":"t-9"}',
			],
			$this->calls
		);
	}//end testResend()
}//end class
