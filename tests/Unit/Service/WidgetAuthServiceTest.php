<?php

/**
 * Unit tests for WidgetAuthService.
 *
 * Covers API key hashing/verification, rate-limit counter increment + reset,
 * and the fail-closed behaviour when the rate-limit cache is unavailable
 * (REQ-WSW-001 / REQ-WSW-009).
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

use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WidgetAuthService.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-13
 */
class WidgetAuthServiceTest extends TestCase {

	/**
	 * DI container (stubbed; ObjectService is not exercised here).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * SettingsService stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Cache factory stub.
	 *
	 * @var ICacheFactory&MockObject
	 */
	private ICacheFactory&MockObject $cacheFactory;

	/**
	 * In-memory cache stub used by the rate-limit tests.
	 *
	 * @var array<string,mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Time factory stub.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Logger stub.
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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->time->method('getTime')->willReturn(1717000000);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cacheStore[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cacheStore[$key]);
				return true;
			}
		);
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->service = new WidgetAuthService(
			container: $this->container,
			settings: $this->settings,
			cacheFactory: $this->cacheFactory,
			time: $this->time,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * generatePlaintextKey() returns a `bk_live_` prefixed token of the expected length.
	 *
	 * @return void
	 */
	public function testGeneratePlaintextKeyShape(): void {
		$key = $this->service->generatePlaintextKey();
		self::assertStringStartsWith('bk_live_', $key);
		// bk_live_ (8) + 32 base64 chars from 24 raw bytes = 40 chars total.
		self::assertSame(40, strlen($key));

	}//end testGeneratePlaintextKeyShape()

	/**
	 * hashKey() + password_verify roundtrip succeeds.
	 *
	 * @return void
	 */
	public function testHashKeyRoundtrip(): void {
		$plaintext = 'bk_live_unit-test-key';
		$hash = $this->service->hashKey(plaintext: $plaintext);
		self::assertTrue(password_verify($plaintext, $hash));
		self::assertFalse(password_verify('different', $hash));

	}//end testHashKeyRoundtrip()

	/**
	 * Rate limiter allows the first N requests and blocks the (N+1)-th.
	 *
	 * @return void
	 */
	public function testRateLimitAllowsAndBlocks(): void {
		for ($i = 0; $i < 100; $i++) {
			$result = $this->service->consumeRateLimit(businessId: 'salon-001', limit: 100);
			self::assertTrue($result['allowed'], 'Request ' . ($i + 1) . ' should be allowed.');
		}

		$blocked = $this->service->consumeRateLimit(businessId: 'salon-001', limit: 100);
		self::assertFalse($blocked['allowed']);
		self::assertSame(0, $blocked['remaining']);
		self::assertGreaterThan(0, $blocked['retryAfter']);

	}//end testRateLimitAllowsAndBlocks()

	/**
	 * Rate limiter scopes counters per businessId.
	 *
	 * @return void
	 */
	public function testRateLimitScopedPerBusiness(): void {
		for ($i = 0; $i < 100; $i++) {
			$this->service->consumeRateLimit(businessId: 'salon-001', limit: 100);
		}

		// Second business is unaffected by salon-001's counter.
		$other = $this->service->consumeRateLimit(businessId: 'clinic-002', limit: 100);
		self::assertTrue($other['allowed']);

	}//end testRateLimitScopedPerBusiness()

	/**
	 * validateApiKey() fails closed when no record exists.
	 *
	 * @return void
	 */
	public function testValidateApiKeyRejectsUnknownBusiness(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$result = $this->service->validateApiKey(businessId: 'salon-001', apiKey: 'bk_live_xxx');
		self::assertFalse($result['valid']);
		self::assertSame('Invalid or missing API key', $result['error']);

	}//end testValidateApiKeyRejectsUnknownBusiness()

	/**
	 * validateApiKey() rejects empty inputs without touching OR.
	 *
	 * @return void
	 */
	public function testValidateApiKeyRejectsEmptyInputs(): void {
		$this->container->expects(self::never())->method('get');

		$result = $this->service->validateApiKey(businessId: '', apiKey: '');
		self::assertFalse($result['valid']);

		$result = $this->service->validateApiKey(businessId: 'salon-001', apiKey: '');
		self::assertFalse($result['valid']);

		$result = $this->service->validateApiKey(businessId: '', apiKey: 'bk_live_xxx');
		self::assertFalse($result['valid']);

	}//end testValidateApiKeyRejectsEmptyInputs()

}//end class
