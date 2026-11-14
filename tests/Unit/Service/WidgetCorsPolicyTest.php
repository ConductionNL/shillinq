<?php

/**
 * Unit tests for WidgetCorsPolicy and the allowed origins of a widget key.
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
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetCorsPolicy;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Origins are matched exactly and stored only when every one is valid.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
 */
#[CoversClass(WidgetCorsPolicy::class)]
#[CoversClass(WidgetAuthService::class)]
#[UsesClass(RegisterLocation::class)]
final class WidgetCorsPolicyTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * A policy over a real WidgetAuthService and an in-memory store with the given keys.
	 *
	 * @param array<int, array<string, mixed>> $keys The WidgetAccessKey rows.
	 *
	 * @return WidgetCorsPolicy
	 */
	private function policy(array $keys): WidgetCorsPolicy {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('widgets');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1717000000);

		$this->store = new InMemoryObjectServiceStub(data: ['WidgetAccessKey' => $keys]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($this->store);

		return new WidgetCorsPolicy(
			keys: new WidgetAuthService(
				container: $container,
				settings: new RegisterLocation(appConfig: $appConfig, appManager: $appManager),
				cacheFactory: $this->createMock(ICacheFactory::class),
				time: $time,
				logger: $this->createMock(LoggerInterface::class),
			)
		);
	}//end policy()

	/**
	 * The objects saved on one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedOn(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end savedOn()

	/**
	 * Origins and their canonical form; null means refused.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function origins(): array {
		return [
			'plain https' => ['https://kapsalon.example', 'https://kapsalon.example'],
			'upper case, trailing slash' => [' HTTPS://Kapsalon.Example/ ', 'https://kapsalon.example'],
			'default port dropped' => ['https://kapsalon.example:443', 'https://kapsalon.example'],
			'other port kept' => ['http://localhost:8080', 'http://localhost:8080'],
			'wildcard' => ['*', null],
			'wildcard host' => ['https://*.example', null],
			'path' => ['https://kapsalon.example/booking', null],
			'query' => ['https://kapsalon.example?x=1', null],
			'other scheme' => ['ftp://kapsalon.example', null],
			'no scheme' => ['kapsalon.example', null],
			'null origin' => ['null', null],
			'port out of range' => ['https://kapsalon.example:70000', null],
			'credentials' => ['https://user@kapsalon.example', null],
		];
	}//end origins()

	/**
	 * Each origin normalises as expected.
	 *
	 * @param string      $origin   The origin.
	 * @param string|null $expected The canonical form.
	 *
	 * @return void
	 */
	#[DataProvider('origins')]
	public function testNormaliseOrigin(string $origin, ?string $expected): void {
		self::assertSame($expected, WidgetCorsPolicy::normaliseOrigin(origin: $origin));
	}//end testNormaliseOrigin()

	/**
	 * Only an origin on the active key of the business is allowed.
	 *
	 * @return void
	 */
	public function testOnlyAnOriginOnTheActiveKeyIsAllowed(): void {
		$policy = $this->policy(
			keys: [
				['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'active', 'allowedOrigins' => ['https://Kapsalon.example/', '*', 42]],
				['id' => 'k2', 'businessId' => 'salon-2', 'status' => 'revoked', 'allowedOrigins' => ['https://other.example']],
			]
		);

		self::assertSame('https://kapsalon.example', $policy->allowedOrigin(businessId: 'salon-1', origin: 'https://kapsalon.example'));
		self::assertNull($policy->allowedOrigin(businessId: 'salon-1', origin: 'https://evil.example'));
		self::assertNull($policy->allowedOrigin(businessId: 'salon-1', origin: '*'), 'a stored wildcard never matches');
		self::assertNull($policy->allowedOrigin(businessId: 'salon-2', origin: 'https://other.example'), 'a revoked key allows nothing');
		self::assertNull($policy->allowedOrigin(businessId: '', origin: 'https://kapsalon.example'));
	}//end testOnlyAnOriginOnTheActiveKeyIsAllowed()

	/**
	 * Valid origins are stored normalised and deduplicated on the active key, and the change is audited.
	 *
	 * @return void
	 */
	public function testSetAllowedOriginsStoresThemAndAudits(): void {
		$policy = $this->policy(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'administrationId' => 'adm', 'status' => 'active', 'allowedOrigins' => []]]);

		$result = $policy->setAllowedOrigins(
			businessId: 'salon-1',
			origins: ['https://Kapsalon.example/', 'https://kapsalon.example', 'http://localhost:8080'],
			actor: 'alice'
		);

		self::assertSame(['success' => true, 'businessId' => 'salon-1', 'allowedOrigins' => ['https://kapsalon.example', 'http://localhost:8080']], $result);
		$keys = $this->savedOn(schema: 'WidgetAccessKey');
		self::assertCount(1, $keys);
		self::assertSame('k1', $keys[0]['id']);
		self::assertSame(['https://kapsalon.example', 'http://localhost:8080'], $keys[0]['allowedOrigins']);
		$audit = $this->savedOn(schema: 'WidgetAccessKeyAuditEntry');
		self::assertSame(
			[['administrationId' => 'adm', 'businessId' => 'salon-1', 'action' => 'origins-changed', 'actor' => 'alice', 'occurredAt' => '2024-05-29T16:26:40+00:00']],
			array_map(static function (array $row): array {
				unset($row['id']);
				return $row;
			}, $audit)
		);
		self::assertSame('https://kapsalon.example', $policy->allowedOrigin(businessId: 'salon-1', origin: 'https://kapsalon.example'));
	}//end testSetAllowedOriginsStoresThemAndAudits()

	/**
	 * One invalid origin, too many origins, or no active key stores nothing.
	 *
	 * @return void
	 */
	public function testInvalidListsAndMissingKeysStoreNothing(): void {
		$policy = $this->policy(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'active', 'allowedOrigins' => ['https://kapsalon.example']]]);

		$wildcard = $policy->setAllowedOrigins(businessId: 'salon-1', origins: ['https://ok.example', '*'], actor: 'alice');
		self::assertFalse($wildcard['success']);
		self::assertStringContainsString('no path or wildcard', $wildcard['message']);

		self::assertFalse($policy->setAllowedOrigins(businessId: 'salon-1', origins: [['https://ok.example']], actor: 'alice')['success']);

		$many = array_map(static fn (int $i): string => 'https://s' . $i . '.example', range(1, WidgetCorsPolicy::MAX_ORIGINS + 1));
		self::assertSame(
			['success' => false, 'message' => 'A widget key allows at most 20 origins.'],
			$policy->setAllowedOrigins(businessId: 'salon-1', origins: $many, actor: 'alice')
		);

		self::assertSame(
			['success' => false, 'message' => 'No active key found for businessId.'],
			$policy->setAllowedOrigins(businessId: 'nobody', origins: ['https://ok.example'], actor: 'alice')
		);
		self::assertSame([], $this->store->saved);
	}//end testInvalidListsAndMissingKeysStoreNothing()

	/**
	 * An empty list closes the API to other websites again.
	 *
	 * @return void
	 */
	public function testAnEmptyListClearsTheOrigins(): void {
		$policy = $this->policy(keys: [['id' => 'k1', 'businessId' => 'salon-1', 'status' => 'active', 'allowedOrigins' => ['https://kapsalon.example']]]);

		self::assertTrue($policy->setAllowedOrigins(businessId: 'salon-1', origins: [], actor: 'alice')['success']);
		self::assertNull($policy->allowedOrigin(businessId: 'salon-1', origin: 'https://kapsalon.example'));
	}//end testAnEmptyListClearsTheOrigins()
}//end class
