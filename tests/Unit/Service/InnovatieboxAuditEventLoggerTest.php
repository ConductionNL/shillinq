<?php

/**
 * Unit tests for InnovatieboxAuditEventLogger.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent fake recording every saveObject() call.
 */
final class FakeAuditObjectService {

	/**
	 * @var array<int, array{schema:string,payload:array<string,mixed>}>
	 */
	public array $saves = [];

	/**
	 * Configured Throwable for saveObject (null = succeed).
	 */
	private ?\Throwable $error = null;

	private string $schema = '';

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $payload Audit payload.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $payload): array {
		if ($this->error !== null) {
			throw $this->error;
		}

		$this->saves[] = ['schema' => $this->schema, 'payload' => $payload];

		return $payload;
	}//end saveObject()

	public function setError(?\Throwable $e): void {
		$this->error = $e;

	}//end setError()
}//end class

/**
 * Verifies REQ-IBA-008/009 append-only audit logging:
 * - happy path persists event_type, event_timestamp, administrationId, actor_uid
 * - optional fields (boekjaar, subject_*) are forwarded when set
 * - missing event_type or administrationId fail-fast w/ warning
 * - unauthenticated session: no actor_uid stamped
 * - OR write failure swallowed, logger warns, record() returns false
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 */
final class InnovatieboxAuditEventLoggerTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Construct the subject + wire the given FakeAuditObjectService.
	 *
	 * @param FakeAuditObjectService $fake Fake OR service.
	 *
	 * @return InnovatieboxAuditEventLogger
	 */
	private function svc(FakeAuditObjectService $fake): InnovatieboxAuditEventLogger {
		$this->container->method('get')->willReturn($fake);

		return new InnovatieboxAuditEventLogger(
			$this->container,
			$this->appConfig,
			$this->userSession,
			$this->logger
		);

	}//end svc()

	/**
	 * Arrange an authenticated user with the given UID.
	 *
	 * @param string $uid UID to expose.
	 *
	 * @return void
	 */
	private function arrangeUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end arrangeUser()

	/**
	 * Happy path: required fields + actor stamped, optional fields forwarded.
	 *
	 * @return void
	 */
	public function testRecordPersistsCanonicalEnvelope(): void {
		$this->arrangeUser('alice');

		$fake = new FakeAuditObjectService();

		$ok = $this->svc($fake)->record(
			[
				'event_type' => InnovatieboxAuditEventLogger::EVENT_NEXUS_CALCULATED,
				'administrationId' => 'admin-1',
				'financialYear' => 2025,
				'qualifying_asset_id' => 'qa-7',
				'subject_schema' => 'NexusCalculation',
				'subject_id' => 'nc-99',
				'reason' => 'recompute on input change',
				'details' => ['ratio' => 0.8],
			]
		);

		self::assertTrue($ok);
		self::assertCount(1, $fake->saves);

		$payload = $fake->saves[0]['payload'];
		self::assertSame('InnovatieboxAuditEvent', $fake->saves[0]['schema']);
		self::assertSame(InnovatieboxAuditEventLogger::EVENT_NEXUS_CALCULATED, $payload['event_type']);
		self::assertSame('admin-1', $payload['administrationId']);
		self::assertSame('alice', $payload['actor_uid']);
		self::assertSame(2025, $payload['financialYear']);
		self::assertSame('qa-7', $payload['qualifying_asset_id']);
		self::assertSame('NexusCalculation', $payload['subject_schema']);
		self::assertSame('nc-99', $payload['subject_id']);
		self::assertSame('recompute on input change', $payload['reason']);
		self::assertSame(['ratio' => 0.8], $payload['details']);
		self::assertArrayHasKey('event_timestamp', $payload);
		// ATOM format ends with timezone offset.
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['event_timestamp']);

	}//end testRecordPersistsCanonicalEnvelope()

	/**
	 * Missing event_type → warning + false, no OR write.
	 *
	 * @return void
	 */
	public function testMissingEventTypeFailsFast(): void {
		$fake = new FakeAuditObjectService();

		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('missing required event_type'));
		// ObjectService::get should never even be called.
		$this->container->expects(self::never())->method('get');

		$svc = new InnovatieboxAuditEventLogger(
			$this->container,
			$this->appConfig,
			$this->userSession,
			$this->logger
		);

		self::assertFalse($svc->record(['administrationId' => 'admin-1']));
		self::assertCount(0, $fake->saves);

	}//end testMissingEventTypeFailsFast()

	/**
	 * Missing administrationId → warning + false, no OR write.
	 *
	 * @return void
	 */
	public function testMissingAdministrationIdFailsFast(): void {
		$this->logger->expects(self::once())->method('warning');
		$this->container->expects(self::never())->method('get');

		$svc = new InnovatieboxAuditEventLogger(
			$this->container,
			$this->appConfig,
			$this->userSession,
			$this->logger
		);

		self::assertFalse($svc->record(['event_type' => 'X']));

	}//end testMissingAdministrationIdFailsFast()

	/**
	 * Unauthenticated session: no actor_uid key (legacy / system-trigger).
	 *
	 * @return void
	 */
	public function testUnauthenticatedSessionOmitsActorUid(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$fake = new FakeAuditObjectService();

		$ok = $this->svc($fake)->record(
			[
				'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_CREATED,
				'administrationId' => 'admin-1',
			]
		);

		self::assertTrue($ok);
		self::assertArrayNotHasKey('actor_uid', $fake->saves[0]['payload']);

	}//end testUnauthenticatedSessionOmitsActorUid()

	/**
	 * OR write failure → warning logged + record() returns false (fail soft —
	 * the subject mutation must not break because the audit log failed).
	 *
	 * @return void
	 */
	public function testFailedSaveIsFailSoftAndLogged(): void {
		$this->arrangeUser('bob');
		$fake = new FakeAuditObjectService();
		$fake->setError(new \RuntimeException('OR down'));

		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('failed to persist audit event'));

		$ok = $this->svc($fake)->record(
			[
				'event_type' => InnovatieboxAuditEventLogger::EVENT_PROFIT_AMENDMENT_BLOCKED,
				'administrationId' => 'admin-1',
			]
		);

		self::assertFalse($ok);

	}//end testFailedSaveIsFailSoftAndLogged()

	/**
	 * Optional fields explicitly set to null are NOT forwarded (avoid
	 * polluting the immutable record with sentinel nulls).
	 *
	 * @return void
	 */
	public function testNullOptionalFieldsAreNotForwarded(): void {
		$this->arrangeUser('carol');
		$fake = new FakeAuditObjectService();

		$this->svc($fake)->record(
			[
				'event_type' => InnovatieboxAuditEventLogger::EVENT_FORFAITAIR_CAP_APPLIED,
				'administrationId' => 'admin-1',
				'financialYear' => null,
				'reason' => null,
				'details' => null,
			]
		);

		$payload = $fake->saves[0]['payload'];
		self::assertArrayNotHasKey('financialYear', $payload);
		self::assertArrayNotHasKey('reason', $payload);
		self::assertArrayNotHasKey('details', $payload);

	}//end testNullOptionalFieldsAreNotForwarded()

}//end class
