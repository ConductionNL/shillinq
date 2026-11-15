<?php

/**
 * Unit tests for WidgetAuthService key lifecycle (create / rotate / revoke) and lookups.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the WidgetAccessKey lifecycle against an in-memory ObjectService.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-13
 */
#[CoversClass(WidgetAuthService::class)]
#[UsesClass(RegisterLocation::class)]
final class WidgetAuthServiceKeysTest extends TestCase {

	/**
	 * Fixed clock (2024-05-29T16:26:40Z).
	 */
	private const NOW = 1717000000;

	/**
	 * Fixed clock rendered with gmdate('c').
	 */
	private const NOW_ISO = '2024-05-29T16:26:40+00:00';

	/**
	 * DI container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * App manager mock deciding OpenRegister availability.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Cache factory mock.
	 *
	 * @var ICacheFactory&MockObject
	 */
	private ICacheFactory&MockObject $cacheFactory;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var WidgetAuthService
	 */
	private WidgetAuthService $service;

	/**
	 * Build the service with a real RegisterLocation over mocked config + app manager.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('widgets');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new WidgetAuthService(
			container: $this->container,
			settings: new RegisterLocation(appConfig: $appConfig, appManager: $this->appManager),
			cacheFactory: $this->cacheFactory,
			time: $time,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Wire an in-memory store with the given WidgetAccessKey rows.
	 *
	 * @param array<int,array<string,mixed>> $keys     Seed rows.
	 * @param bool                           $entities Whether findAll answers entity objects.
	 *
	 * @return InMemoryObjectServiceStub
	 */
	private function store(array $keys, bool $entities = true): InMemoryObjectServiceStub {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$store = new InMemoryObjectServiceStub(data: ['WidgetAccessKey' => $keys], findAllRendersEntities: $entities);
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($store);

		return $store;
	}//end store()

	/**
	 * Saved rows for one schema.
	 *
	 * @param InMemoryObjectServiceStub $store  The store.
	 * @param string                    $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function savedFor(InMemoryObjectServiceStub $store, string $schema): array {
		return array_values(
			array_map(
				static fn (array $s): array => $s['object'],
				array_filter($store->saved, static fn (array $s): bool => $s['schema'] === $schema)
			)
		);
	}//end savedFor()

	/**
	 * createApiKey() rejects empty identifiers before touching anything.
	 *
	 * @return void
	 */
	public function testCreateRequiresIdentifiers(): void {
		$this->container->expects(self::never())->method('get');

		self::assertSame(
			['success' => false, 'message' => 'administrationId and businessId are required.'],
			$this->service->createApiKey(administrationId: '', businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame(
			['success' => false, 'message' => 'administrationId and businessId are required.'],
			$this->service->createApiKey(administrationId: 'adm', businessId: '', actor: 'admin')
		);

	}//end testCreateRequiresIdentifiers()

	/**
	 * createApiKey() refuses when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testCreateFailsWithoutOpenRegister(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		self::assertSame(
			['success' => false, 'message' => 'OpenRegister is not available.'],
			$this->service->createApiKey(administrationId: 'adm', businessId: 'salon-1', actor: 'admin')
		);
		self::assertNull($this->service->findActiveKeyByBusinessId(businessId: 'salon-1'));
		self::assertNull($this->service->findKeyByBusinessId(businessId: 'salon-1'));

	}//end testCreateFailsWithoutOpenRegister()

	/**
	 * createApiKey() refuses a second active key for the same business.
	 *
	 * @return void
	 */
	public function testCreateRefusesWhenActiveKeyExists(): void {
		$store = $this->store(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'active']]);

		self::assertSame(
			['success' => false, 'message' => 'An active key already exists for this businessId.'],
			$this->service->createApiKey(administrationId: 'adm', businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame([], $store->saved);

	}//end testCreateRefusesWhenActiveKeyExists()

	/**
	 * createApiKey() persists a hashed key and an audit entry, returning the plaintext once.
	 *
	 * @return void
	 */
	public function testCreatePersistsHashedKeyAndAudit(): void {
		$store = $this->store(keys: [['id' => 'old', 'businessId' => 'salon-1', 'status' => 'revoked']]);

		$result = $this->service->createApiKey(administrationId: 'adm', businessId: 'salon-1', actor: 'admin');

		self::assertTrue($result['success']);
		self::assertSame('salon-1', $result['businessId']);
		self::assertStringStartsWith('bk_live_', $result['apiKey']);

		$keys = $this->savedFor(store: $store, schema: 'WidgetAccessKey');
		self::assertCount(1, $keys);
		$key = $keys[0];
		self::assertTrue(password_verify($result['apiKey'], $key['apiKeyHash']));
		self::assertStringNotContainsString($result['apiKey'], json_encode($key));
		self::assertSame('bk_live_', $key['apiKeyPrefix']);
		self::assertSame('adm', $key['administrationId']);
		self::assertSame(100, $key['rateLimit']);
		self::assertSame([], $key['allowedOrigins']);
		self::assertSame(self::NOW_ISO, $key['createdAt']);
		self::assertSame('active', $key['status']);
		self::assertSame($key, $result['record']);

		self::assertSame(
			[
				[
					'administrationId' => 'adm',
					'businessId' => 'salon-1',
					'action' => 'created',
					'actor' => 'admin',
					'occurredAt' => self::NOW_ISO,
					'id' => 'obj-2',
				],
			],
			$this->savedFor(store: $store, schema: 'WidgetAccessKeyAuditEntry')
		);

	}//end testCreatePersistsHashedKeyAndAudit()

	/**
	 * createApiKey() reports a persistence failure when the ObjectService cannot be resolved.
	 *
	 * @return void
	 */
	public function testCreateReportsPersistFailure(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('no OR'));
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		self::assertSame(
			['success' => false, 'message' => 'Failed to persist WidgetAccessKey.'],
			$this->service->createApiKey(administrationId: 'adm', businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame(
			[
				['Shillinq widget auth: ObjectService unavailable', ['exception' => 'no OR']],
				['Shillinq widget auth: saveObject failed', ['schema' => 'WidgetAccessKey', 'exception' => 'no OR']],
			],
			$messages
		);

	}//end testCreateReportsPersistFailure()

	/**
	 * saveObject() returns null when OpenRegister disappears between lookup and save.
	 *
	 * @return void
	 */
	public function testCreateFailsWhenOpenRegisterDisappearsBeforeSave(): void {
		$this->appManager->method('isInstalled')->willReturnOnConsecutiveCalls(true, true, false);
		$store = new InMemoryObjectServiceStub(data: []);
		$this->container->method('get')->willReturn($store);

		self::assertSame(
			['success' => false, 'message' => 'Failed to persist WidgetAccessKey.'],
			$this->service->createApiKey(administrationId: 'adm', businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame([], $store->saved);

	}//end testCreateFailsWhenOpenRegisterDisappearsBeforeSave()

	/**
	 * rotateApiKey() fails when no active key exists.
	 *
	 * @return void
	 */
	public function testRotateWithoutActiveKey(): void {
		$this->store(keys: []);

		self::assertSame(
			['success' => false, 'message' => 'No active key found for businessId.'],
			$this->service->rotateApiKey(businessId: 'salon-1', actor: 'admin')
		);

	}//end testRotateWithoutActiveKey()

	/**
	 * rotateApiKey() fails when the active key carries no administrationId.
	 *
	 * @return void
	 */
	public function testRotateRequiresAdministrationId(): void {
		$store = $this->store(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'active']]);

		self::assertSame(
			['success' => false, 'message' => 'Active key has no administrationId.'],
			$this->service->rotateApiKey(businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame([], $store->saved);

	}//end testRotateRequiresAdministrationId()

	/**
	 * rotateApiKey() moves the predecessor to 'rotating' and mints an active successor.
	 *
	 * @return void
	 */
	public function testRotateMarksPredecessorAndMintsSuccessor(): void {
		$store = $this->store(
			keys: [
				[
					'id' => 'k1',
					'businessId' => 'salon-1',
					'administrationId' => 'adm',
					'status' => 'active',
					'rateLimit' => 250,
					'allowedOrigins' => ['https://salon.test'],
					'apiKeyHash' => 'old-hash',
				],
			]
		);

		$result = $this->service->rotateApiKey(businessId: 'salon-1', actor: 'alice');

		self::assertTrue($result['success']);
		self::assertSame('salon-1', $result['businessId']);

		$keys = $this->savedFor(store: $store, schema: 'WidgetAccessKey');
		self::assertCount(2, $keys);
		self::assertSame('k1', $keys[0]['id']);
		self::assertSame('rotating', $keys[0]['status']);
		self::assertSame(self::NOW_ISO, $keys[0]['rotatedAt']);
		self::assertSame('old-hash', $keys[0]['apiKeyHash']);

		self::assertSame('active', $keys[1]['status']);
		self::assertSame(250, $keys[1]['rateLimit']);
		self::assertSame(['https://salon.test'], $keys[1]['allowedOrigins']);
		self::assertSame('adm', $keys[1]['administrationId']);
		self::assertTrue(password_verify($result['apiKey'], $keys[1]['apiKeyHash']));

		$audit = $this->savedFor(store: $store, schema: 'WidgetAccessKeyAuditEntry');
		self::assertCount(1, $audit);
		self::assertSame('rotated', $audit[0]['action']);
		self::assertSame('alice', $audit[0]['actor']);

	}//end testRotateMarksPredecessorAndMintsSuccessor()

	/**
	 * rotateApiKey() without a predecessor id skips the update and still mints a key.
	 *
	 * @return void
	 */
	public function testRotateWithoutPredecessorIdSkipsUpdate(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->with('widgets')->willReturnSelf();
		$objectService->method('setSchema')->with('WidgetAccessKey')->willReturnSelf();
		$objectService->method('findAll')->willReturn(
			[(object)['businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'rotating']]
		);
		$saved = [];
		$objectService->expects(self::exactly(2))->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true) use (&$saved) {
				$saved[] = ['schema' => $schema, 'register' => $register, 'uuid' => $uuid, 'rbac' => $_rbac];
				return new ObjectEntityStub(payload: $object);
			}
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->rotateApiKey(businessId: 'salon-1', actor: 'alice');

		self::assertTrue($result['success']);
		self::assertSame(
			[
				['schema' => 'WidgetAccessKey', 'register' => 'widgets', 'uuid' => null, 'rbac' => false],
				['schema' => 'WidgetAccessKeyAuditEntry', 'register' => 'widgets', 'uuid' => null, 'rbac' => false],
			],
			$saved
		);

	}//end testRotateWithoutPredecessorIdSkipsUpdate()

	/**
	 * rotateApiKey() reports failure and logs both update and save errors when the store rejects writes.
	 *
	 * @return void
	 */
	public function testRotateReportsPersistFailure(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn(
			[['id' => 'k1', 'businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'active']]
		);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('read-only'));
		$this->container->method('get')->willReturn($objectService);

		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		self::assertSame(
			['success' => false, 'message' => 'Failed to persist rotated WidgetAccessKey.'],
			$this->service->rotateApiKey(businessId: 'salon-1', actor: 'alice')
		);
		self::assertSame(
			[
				['Shillinq widget auth: updateObject failed', ['schema' => 'WidgetAccessKey', 'id' => 'k1', 'exception' => 'read-only']],
				['Shillinq widget auth: saveObject failed', ['schema' => 'WidgetAccessKey', 'exception' => 'read-only']],
			],
			$messages
		);

	}//end testRotateReportsPersistFailure()

	/**
	 * updateObject() is skipped when OpenRegister disappears mid-rotation.
	 *
	 * @return void
	 */
	public function testRotateUpdateSkippedWhenOpenRegisterDisappears(): void {
		$this->appManager->method('isInstalled')->willReturnOnConsecutiveCalls(true, false, false);
		$store = new InMemoryObjectServiceStub(
			data: ['WidgetAccessKey' => [['id' => 'k1', 'businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'active']]]
		);
		$this->container->method('get')->willReturn($store);

		self::assertSame(
			['success' => false, 'message' => 'Failed to persist rotated WidgetAccessKey.'],
			$this->service->rotateApiKey(businessId: 'salon-1', actor: 'alice')
		);
		self::assertSame([], $store->saved);

	}//end testRotateUpdateSkippedWhenOpenRegisterDisappears()

	/**
	 * revokeApiKey() fails when no key exists at all.
	 *
	 * @return void
	 */
	public function testRevokeUnknownBusiness(): void {
		$this->store(keys: []);

		self::assertSame(
			['success' => false, 'message' => 'No WidgetAccessKey found for businessId.'],
			$this->service->revokeApiKey(businessId: 'salon-1', actor: 'admin')
		);

	}//end testRevokeUnknownBusiness()

	/**
	 * revokeApiKey() is idempotent for an already-revoked key.
	 *
	 * @return void
	 */
	public function testRevokeAlreadyRevokedIsNoop(): void {
		$store = $this->store(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'revoked']], entities: false);

		self::assertSame(
			['success' => true, 'message' => 'Already revoked.'],
			$this->service->revokeApiKey(businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame([], $store->saved);

	}//end testRevokeAlreadyRevokedIsNoop()

	/**
	 * revokeApiKey() refuses a record without an id.
	 *
	 * @return void
	 */
	public function testRevokeRequiresRecordId(): void {
		$store = $this->store(keys: [['businessId' => 'salon-1', 'status' => 'active']]);

		self::assertSame(
			['success' => false, 'message' => 'Record is missing id.'],
			$this->service->revokeApiKey(businessId: 'salon-1', actor: 'admin')
		);
		self::assertSame([], $store->saved);

	}//end testRevokeRequiresRecordId()

	/**
	 * revokeApiKey() marks the key revoked and writes a 'revoked' audit entry.
	 *
	 * @return void
	 */
	public function testRevokePersistsAndAudits(): void {
		$store = $this->store(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'active']]);

		self::assertSame(['success' => true], $this->service->revokeApiKey(businessId: 'salon-1', actor: 'bob'));

		$keys = $this->savedFor(store: $store, schema: 'WidgetAccessKey');
		self::assertSame(
			[['id' => 'k1', 'businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'revoked', 'revokedAt' => self::NOW_ISO]],
			$keys
		);
		$audit = $this->savedFor(store: $store, schema: 'WidgetAccessKeyAuditEntry');
		self::assertSame('revoked', $audit[0]['action']);
		self::assertSame('bob', $audit[0]['actor']);
		self::assertSame('adm', $audit[0]['administrationId']);
		self::assertNull($this->service->findActiveKeyByBusinessId(businessId: 'salon-1'));

	}//end testRevokePersistsAndAudits()

	/**
	 * findActiveKeyByBusinessId() skips revoked rows and honours a rotating predecessor.
	 *
	 * @return void
	 */
	public function testFindActiveSkipsRevokedAndHonoursRotating(): void {
		$this->store(
			keys: [
				['id' => 'k0', 'businessId' => 'salon-1', 'status' => 'revoked'],
				['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'rotating'],
				['id' => 'k2', 'businessId' => 'salon-2', 'status' => 'active'],
			]
		);

		self::assertSame('k1', $this->service->findActiveKeyByBusinessId(businessId: 'salon-1')['id']);
		self::assertSame('k0', $this->service->findKeyByBusinessId(businessId: 'salon-1')['id']);
		self::assertNull($this->service->findKeyByBusinessId(businessId: 'salon-9'));

	}//end testFindActiveSkipsRevokedAndHonoursRotating()

	/**
	 * A scalar row from the store normalises to an empty record and never counts as active.
	 *
	 * @return void
	 */
	public function testScalarRowsNormaliseToEmptyRecords(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([42]);
		$this->container->method('get')->willReturn($objectService);

		self::assertNull($this->service->findActiveKeyByBusinessId(businessId: 'salon-1'));
		self::assertSame([], $this->service->findKeyByBusinessId(businessId: 'salon-1'));

	}//end testScalarRowsNormaliseToEmptyRecords()

	/**
	 * Lookup failures inside findAll are logged and treated as "no key".
	 *
	 * @return void
	 */
	public function testLookupFailuresAreLoggedAndFailClosed(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willThrowException(new RuntimeException('db gone'));
		$this->container->method('get')->willReturn($objectService);
		$this->logger->expects(self::exactly(2))
			->method('error')
			->with('Shillinq widget auth: lookup failed', ['exception' => 'db gone']);

		self::assertNull($this->service->findActiveKeyByBusinessId(businessId: 'salon-1'));
		self::assertNull($this->service->findKeyByBusinessId(businessId: 'salon-1'));

	}//end testLookupFailuresAreLoggedAndFailClosed()

	/**
	 * validateApiKey() rejects a record without a hash and a non-matching token, and accepts a match.
	 *
	 * @return void
	 */
	public function testValidateApiKeyHashBranches(): void {
		$hash = password_hash('bk_live_secret', PASSWORD_BCRYPT, ['cost' => 4]);
		$this->store(
			keys: [
				['id' => 'k1', 'businessId' => 'nohash', 'status' => 'active'],
				['id' => 'k2', 'businessId' => 'salon-1', 'status' => 'active', 'apiKeyHash' => $hash],
			]
		);
		$this->logger->expects(self::once())
			->method('info')
			->with('Shillinq widget auth: bearer token does not match active hash', ['businessId' => 'salon-1']);

		$rejected = ['valid' => false, 'error' => 'Invalid or missing API key'];
		self::assertSame($rejected, $this->service->validateApiKey(businessId: 'nohash', apiKey: 'anything'));
		self::assertSame($rejected, $this->service->validateApiKey(businessId: 'salon-1', apiKey: 'bk_live_wrong'));

		$ok = $this->service->validateApiKey(businessId: 'salon-1', apiKey: 'bk_live_secret');
		self::assertTrue($ok['valid']);
		self::assertSame('k2', $ok['key']['id']);

	}//end testValidateApiKeyHashBranches()

	/**
	 * consumeRateLimit() fails closed when no local cache is available.
	 *
	 * @return void
	 */
	public function testRateLimitFailsClosedWithoutCache(): void {
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$this->cacheFactory->expects(self::never())->method('createLocal');
		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq widget auth: rate-limit cache unavailable, failing closed');

		self::assertSame(
			['allowed' => false, 'remaining' => 0, 'retryAfter' => 60],
			$this->service->consumeRateLimit(businessId: 'salon-1')
		);

	}//end testRateLimitFailsClosedWithoutCache()

	/**
	 * consumeRateLimit() fails closed when the cache factory throws.
	 *
	 * @return void
	 */
	public function testRateLimitFailsClosedWhenFactoryThrows(): void {
		$this->cacheFactory->method('isLocalCacheAvailable')->willThrowException(new RuntimeException('redis down'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		self::assertFalse($this->service->consumeRateLimit(businessId: 'salon-1')['allowed']);
		self::assertSame(
			[
				['Shillinq widget auth: cache factory threw', ['exception' => 'redis down']],
				['Shillinq widget auth: rate-limit cache unavailable, failing closed', []],
			],
			$warnings
		);

	}//end testRateLimitFailsClosedWhenFactoryThrows()
}//end class
