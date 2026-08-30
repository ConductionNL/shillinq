<?php

/**
 * Unit tests for AppointmentConfirmationService.
 *
 * These tests use an in-memory fake of OpenRegister's ObjectService (a fluent
 * setRegister/setSchema/findAll/saveObject store) so they assert the service's
 * real state transitions — token records actually persist, statuses actually
 * flip — rather than rigging mock return values.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AppointmentConfirmationService;
use OCA\Shillinq\Service\ConfirmationMailer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Behavioural tests for the appointment confirmation workflow.
 */
final class AppointmentConfirmationServiceTest extends TestCase {
	/**
	 * In-memory fake ObjectService shared with the service under test.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test.
	 *
	 * @var AppointmentConfirmationService
	 */
	private AppointmentConfirmationService $service;

	/**
	 * Build a fluent in-memory ObjectService fake.
	 *
	 * @return object
	 */
	private function makeStore(): object {
		return new class {
			/**
			 * Records keyed by schema then a synthetic id.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $records = ['Appointment' => [], 'ConfirmationToken' => []];

			/**
			 * Currently selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Select the register (no-op for the fake).
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * Select the schema.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Single-object lookup by uuid.
			 *
			 * ⚠️ THROWS on a miss — it does not return null. Real
			 * ObjectService raises DoesNotExistException for any identifier it
			 * cannot resolve, so a caller wanting a fallback must wrap this in
			 * its own try/catch. The double had no find() at all, which made it
			 * blind to the lookup path the service actually takes.
			 *
			 * @param string $id Object uuid.
			 *
			 * @return array<string,mixed>
			 *
			 * @throws DoesNotExistException When no object matches.
			 */
			public function find(string $id): array {
				foreach (($this->records[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				throw new DoesNotExistException(
					sprintf("Object with identifier '%s' not found in any magic table", $id)
				);
			}

			/**
			 * Filter the selected schema's records.
			 *
			 * ⚠️ `filters` addresses JSON PROPERTIES only — the ObjectEntity's
			 * `id` is its own column, so real OpenRegister matches ZERO rows
			 * for `['filters' => ['id' => …]]` at every value, silently.
			 * Mirrored here so this double cannot bless a lookup the engine
			 * would answer with nothing.
			 *
			 * @param array<string,mixed> $query Query with optional filters/limit.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $query = []): array {
				$filters = ($query['filters'] ?? []);
				if (array_key_exists('id', $filters) === true) {
					return [];
				}

				$rows = array_values($this->records[$this->schema] ?? []);
				$out = array_filter(
					$rows,
					static function ($row) use ($filters) {
						foreach ($filters as $k => $v) {
							if (($row[$k] ?? null) !== $v) {
								return false;
							}
						}
						return true;
					}
				);
				$out = array_values($out);
				if (isset($query['limit']) === true) {
					$out = array_slice($out, 0, (int)$query['limit']);
				}
				return $out;
			}

			/**
			 * Upsert a record by its id (or tokenId / appointmentNumber).
			 *
			 * @param array<string,mixed> $object The object to save.
			 * @param string $register Register slug.
			 * @param string $schema Schema name.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$schema = ($schema !== '' ? $schema : $this->schema);
				$key = (string)($object['id'] ?? ($object['tokenId'] ?? ($object['appointmentNumber'] ?? uniqid())));
				if (isset($object['id']) === false) {
					$object['id'] = $key;
				}
				$this->records[$schema][$key] = $object;
				return $object;
			}
		};
	}//end makeStore()

	/**
	 * Set up the service with the fake store wired through the container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->makeStore();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->store;
				}
				throw new \RuntimeException('not found: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		// Mailer is stubbed — email delivery has its own ConfirmationMailerTest.
		$mailer = $this->createMock(ConfirmationMailer::class);
		$mailer->method('send')->willReturn(true);

		$this->service = new AppointmentConfirmationService(
			container: $container,
			appConfig: $appConfig,
			mailer: $mailer,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Seed an appointment into the fake store.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed> The stored appointment.
	 */
	private function seedAppointment(array $overrides = []): array {
		$appt = array_merge(
			[
				'id' => 'apt-1',
				'appointmentNumber' => 'APT-2026-0001',
				'serviceName' => 'Consult',
				'customerEmail' => 'jan@example.nl',
				'customerTimezone' => 'Europe/Amsterdam',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
				'status' => AppointmentConfirmationService::STATUS_PENDING,
				'administrationId' => 'adm-1',
			],
			$overrides
		);
		$this->store->saveObject($appt, 'shillinq', 'Appointment');
		return $appt;
	}//end seedAppointment()

	/**
	 * issueConfirmation generates an active token for a pending appointment.
	 *
	 * @return void
	 */
	public function testIssueConfirmationGeneratesActiveToken(): void {
		$appt = $this->seedAppointment();
		$raw = $this->service->issueConfirmation($appt);

		self::assertIsString($raw);
		self::assertSame(32, strlen($raw));
		$tokens = $this->store->records['ConfirmationToken'];
		self::assertCount(1, $tokens);
		$token = array_values($tokens)[0];
		self::assertSame('active', $token['status']);
		self::assertSame('apt-1', $token['appointmentId']);
		// The raw token must never be persisted in clear text.
		self::assertNotSame($raw, $token['tokenString']);
	}//end testIssueConfirmationGeneratesActiveToken()

	/**
	 * Admin-created (confirmed) appointments skip the confirmation flow (REQ-BCF-010).
	 *
	 * @return void
	 */
	public function testConfirmedAppointmentSkipsTokenGeneration(): void {
		$appt = $this->seedAppointment(['status' => AppointmentConfirmationService::STATUS_CONFIRMED]);
		$raw = $this->service->issueConfirmation($appt);

		self::assertNull($raw);
		self::assertCount(0, $this->store->records['ConfirmationToken']);
	}//end testConfirmedAppointmentSkipsTokenGeneration()

	/**
	 * A valid token confirms the appointment and redeems the token (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testConfirmTransitionsAppointmentAndRedeemsToken(): void {
		$appt = $this->seedAppointment();
		$raw = $this->service->issueConfirmation($appt);

		$result = $this->service->confirm('apt-1', $raw);

		self::assertTrue($result['success']);
		self::assertSame(AppointmentConfirmationService::STATUS_CONFIRMED, $result['appointment']['status']);
		self::assertArrayHasKey('confirmedAt', $result['appointment']);

		$token = array_values($this->store->records['ConfirmationToken'])[0];
		self::assertSame('redeemed', $token['status']);
		self::assertArrayHasKey('redeemedAt', $token);
	}//end testConfirmTransitionsAppointmentAndRedeemsToken()

	/**
	 * A wrong token is rejected as invalid (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testConfirmRejectsWrongToken(): void {
		$this->seedAppointment();
		$this->service->issueConfirmation($this->store->records['Appointment']['apt-1']);

		$result = $this->service->confirm('apt-1', 'totally-wrong-token');

		self::assertFalse($result['success']);
		self::assertSame('invalid', $result['reason']);
		self::assertSame(
			AppointmentConfirmationService::STATUS_PENDING,
			$this->store->records['Appointment']['apt-1']['status']
		);
	}//end testConfirmRejectsWrongToken()

	/**
	 * An expired token is rejected and the appointment stays pending (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testConfirmRejectsExpiredToken(): void {
		$appt = $this->seedAppointment();
		$raw = $this->service->issueConfirmation($appt);

		// Force the token to be expired.
		foreach ($this->store->records['ConfirmationToken'] as $k => $tok) {
			$tok['expiresAt'] = '2000-01-01T00:00:00Z';
			$this->store->records['ConfirmationToken'][$k] = $tok;
		}

		$result = $this->service->confirm('apt-1', $raw);

		self::assertFalse($result['success']);
		self::assertSame('expired', $result['reason']);
	}//end testConfirmRejectsExpiredToken()

	/**
	 * A redeemed token cannot be reused (REQ-BCF-004).
	 *
	 * @return void
	 */
	public function testConfirmRejectsAlreadyRedeemedToken(): void {
		$appt = $this->seedAppointment();
		$raw = $this->service->issueConfirmation($appt);
		$this->service->confirm('apt-1', $raw);

		// Second attempt with the same token.
		$result = $this->service->confirm('apt-1', $raw);

		self::assertFalse($result['success']);
		self::assertSame('redeemed', $result['reason']);
	}//end testConfirmRejectsAlreadyRedeemedToken()

	/**
	 * Resend revokes the old token and issues a fresh active one (REQ-BCF-006).
	 *
	 * @return void
	 */
	public function testResendRevokesOldTokenAndIssuesNew(): void {
		$appt = $this->seedAppointment();
		$this->service->issueConfirmation($appt);

		$result = $this->service->resend('apt-1');

		self::assertTrue($result['success']);
		$statuses = array_column(array_values($this->store->records['ConfirmationToken']), 'status');
		sort($statuses);
		self::assertSame(['active', 'revoked'], $statuses);
	}//end testResendRevokesOldTokenAndIssuesNew()

	/**
	 * cancelExpired cancels pending appointments past their deadline (REQ-BCF-005).
	 *
	 * @return void
	 */
	public function testCancelExpiredCancelsPastDeadlinePending(): void {
		$this->seedAppointment(['id' => 'apt-1', 'confirmationDeadline' => '2000-01-01T00:00:00Z']);

		$count = $this->service->cancelExpired();

		self::assertSame(1, $count);
		self::assertSame(
			AppointmentConfirmationService::STATUS_CANCELLED,
			$this->store->records['Appointment']['apt-1']['status']
		);
		self::assertSame(
			'Confirmation deadline passed',
			$this->store->records['Appointment']['apt-1']['cancelledReason']
		);
	}//end testCancelExpiredCancelsPastDeadlinePending()

	/**
	 * cancelExpired leaves confirmed appointments untouched even past deadline (REQ-BCF-005).
	 *
	 * @return void
	 */
	public function testCancelExpiredLeavesConfirmedAppointments(): void {
		$this->seedAppointment(
			[
				'id' => 'apt-1',
				'status' => AppointmentConfirmationService::STATUS_CONFIRMED,
				'confirmationDeadline' => '2000-01-01T00:00:00Z',
			]
		);

		$count = $this->service->cancelExpired();

		self::assertSame(0, $count);
		self::assertSame(
			AppointmentConfirmationService::STATUS_CONFIRMED,
			$this->store->records['Appointment']['apt-1']['status']
		);
	}//end testCancelExpiredLeavesConfirmedAppointments()

	/**
	 * cancelExpired does not cancel pending appointments before their deadline.
	 *
	 * @return void
	 */
	public function testCancelExpiredKeepsFutureDeadlinePending(): void {
		$this->seedAppointment(['id' => 'apt-1', 'confirmationDeadline' => '2999-01-01T00:00:00Z']);

		$count = $this->service->cancelExpired();

		self::assertSame(0, $count);
		self::assertSame(
			AppointmentConfirmationService::STATUS_PENDING,
			$this->store->records['Appointment']['apt-1']['status']
		);
	}//end testCancelExpiredKeepsFutureDeadlinePending()

	/**
	 * validateToken is a dry-run: a valid token does not change any state.
	 *
	 * @return void
	 */
	public function testValidateTokenHasNoSideEffects(): void {
		$appt = $this->seedAppointment();
		$raw = $this->service->issueConfirmation($appt);

		$result = $this->service->validateToken('apt-1', $raw);

		self::assertTrue($result['valid']);
		self::assertSame(
			AppointmentConfirmationService::STATUS_PENDING,
			$this->store->records['Appointment']['apt-1']['status']
		);
		$token = array_values($this->store->records['ConfirmationToken'])[0];
		self::assertSame('active', $token['status']);
	}//end testValidateTokenHasNoSideEffects()
}//end class
