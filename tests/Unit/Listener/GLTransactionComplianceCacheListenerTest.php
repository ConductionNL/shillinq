<?php

/**
 * Unit tests for GLTransactionComplianceCacheListener.
 *
 * A create or update of a GL transaction or GL line clears the BBV compliance
 * cache; anything else, an object without a schema, and a failing resolver
 * leave it alone.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\EventDispatcher\Event;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GLTransactionComplianceCacheListener.
 */
#[CoversClass(GLTransactionComplianceCacheListener::class)]
#[UsesClass(ComplianceService::class)]
class GLTransactionComplianceCacheListenerTest extends TestCase {

	/**
	 * How often the compliance cache was cleared.
	 *
	 * @var integer
	 */
	private int $clears = 0;

	/**
	 * Build the listener around a resolver that answers $slug (or throws it).
	 *
	 * @param string|RuntimeException $slug   The schema slug, or what the resolver throws.
	 * @param LoggerInterface|null    $logger The listener's logger.
	 *
	 * @return GLTransactionComplianceCacheListener
	 */
	private function listener(string|RuntimeException $slug, ?LoggerInterface $logger = null): GLTransactionComplianceCacheListener {
		$cache = $this->createMock(ICache::class);
		$cache->method('clear')->willReturnCallback(
			function (): bool {
				$this->clears++;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$cacheFactory->method('createLocal')->with('shillinq-bbv-compliance')->willReturn($cache);

		$compliance = new ComplianceService(
			container: $this->createMock(ContainerInterface::class),
			registerLocation: $this->createMock(RegisterLocation::class),
			cacheFactory: $cacheFactory,
			logger: $this->createMock(LoggerInterface::class),
		);

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($slug instanceof RuntimeException) {
			$resolver->method('schemaSlug')->willThrowException($slug);
		} else {
			$resolver->method('schemaSlug')->willReturn($slug);
		}

		return new GLTransactionComplianceCacheListener(
			compliance: $compliance,
			schemaResolver: $resolver,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end listener()

	/**
	 * An object entity carrying a schema id.
	 *
	 * @param string|null $schema The raw schema.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?string $schema = '12'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		return $entity;
	}//end entity()

	/**
	 * Watched slugs, bare, padded, upper-case and path-qualified.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function watchedSlugs(): array {
		return [
			'transaction'       => ['gltransaction'],
			'line'              => ['glline'],
			'transaction line'  => ['gltransactionline'],
			'upper and padded'  => ['  GLTransaction '],
			'register-prefixed' => ['shillinq/glline'],
		];
	}//end watchedSlugs()

	/**
	 * A created or updated watched object clears the cache once.
	 *
	 * @param string $slug The resolved schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('watchedSlugs')]
	public function testAWatchedObjectClearsTheCache(string $slug): void {
		$this->listener($slug)->handle(new ObjectCreatedEvent($this->entity()));
		$this->assertSame(1, $this->clears);

		$this->listener($slug)->handle(new ObjectUpdatedEvent($this->entity()));
		$this->assertSame(2, $this->clears);
	}//end testAWatchedObjectClearsTheCache()

	/**
	 * Other schemas, including near misses, leave the cache alone.
	 *
	 * @return void
	 */
	public function testOtherSchemasLeaveTheCacheAlone(): void {
		foreach (['invoice', 'gltransactions', 'myglline', 'glline/extra', ''] as $slug) {
			$this->listener($slug)->handle(new ObjectCreatedEvent($this->entity()));
		}

		$this->assertSame(0, $this->clears);
	}//end testOtherSchemasLeaveTheCacheAlone()

	/**
	 * Other events, a missing object and an object without a schema are ignored.
	 *
	 * @return void
	 */
	public function testIrrelevantEventsAreIgnored(): void {
		$listener = $this->listener('gltransaction');

		$listener->handle(new Event());
		$listener->handle(new ObjectCreatedEvent(null));
		$listener->handle(new ObjectUpdatedEvent($this->entity(null)));

		$this->assertSame(0, $this->clears);
	}//end testIrrelevantEventsAreIgnored()

	/**
	 * A failing resolver is logged as a warning and does not reach the cache.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Shillinq compliance cache listener: invalidation failed',
			['exception' => 'mapper down']
		);

		$this->listener(new RuntimeException('mapper down'), $logger)->handle(new ObjectCreatedEvent($this->entity()));

		$this->assertSame(0, $this->clears);
	}//end testAFailureIsLoggedNotThrown()
}//end class
