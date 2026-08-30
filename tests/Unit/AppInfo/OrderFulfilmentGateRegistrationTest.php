<?php

/**
 * Unit tests for OrderFulfilmentGateRegistration.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\AppInfo\OrderFulfilmentGateRegistration;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Listener\OrderFulfilmentEvidenceListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The REQ-004 bewijsstuk gate has to be registered on BOTH enforcement points.
 *
 * A direct create/update never triggers a lifecycle transition, so the
 * declarative `requires` guard alone does not see it — measured on a live
 * instance, POSTing an OrderFulfilment with `status: completed` and
 * `bewijsstukken: []` returned 201 Created. Registering only the transition
 * guard, or only the create event and not the update event, reopens that hole
 * silently, so this test pins the full set rather than the fact that
 * `register()` ran.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OrderFulfilmentGateRegistrationTest extends TestCase {
	/**
	 * The tag the OrderFulfilment `voltooien` transition declares.
	 *
	 * @var string
	 */
	private const GUARD_TAG = 'OCA\Shillinq\Lifecycle\OrderFulfilmentGuard::canVoltooien';

	/**
	 * Captured registerEventListener() calls as [event, listener] pairs.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $listeners = [];

	/**
	 * Captured registerService() factories, keyed by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $services = [];

	/**
	 * A registration context that records what was registered on it.
	 *
	 * @return IRegistrationContext The recording context.
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);

		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0): void {
				$this->listeners[] = [$event, $listener];
			}
		);

		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->services[$name] = $factory;
			}
		);

		return $context;
	}//end recordingContext()

	/**
	 * Both PRE-SAVE events are covered, not just create.
	 *
	 * @return void
	 */
	public function testBothPreSaveEventsGetTheBewijsstukListener(): void {
		(new OrderFulfilmentGateRegistration())->register($this->recordingContext());

		$events = array_column($this->listeners, 0);

		self::assertContains(
			ObjectCreatingEvent::class,
			$events,
			'A direct create with status=completed and no bewijsstuk must be gated.'
		);
		self::assertContains(
			ObjectUpdatingEvent::class,
			$events,
			'An update into status=completed must be gated too — covering create alone leaves the hole open.'
		);

		foreach ($this->listeners as [$event, $listener]) {
			self::assertSame(OrderFulfilmentEvidenceListener::class, $listener, 'for ' . $event);
		}

	}//end testBothPreSaveEventsGetTheBewijsstukListener()

	/**
	 * The transition half is registered under the exact literal tag.
	 *
	 * A tag containing `::` cannot autowire, so the registration has to name
	 * the literal string the register declares — a near-miss here means
	 * OpenRegister's container can never resolve the guard and the transition
	 * fails closed at runtime rather than at build time.
	 *
	 * @return void
	 */
	public function testTheTransitionGuardIsRegisteredUnderTheDeclaredLiteralTag(): void {
		(new OrderFulfilmentGateRegistration())->register($this->recordingContext());

		self::assertArrayHasKey(self::GUARD_TAG, $this->services);

	}//end testTheTransitionGuardIsRegisteredUnderTheDeclaredLiteralTag()

	/**
	 * The registered factory builds an adapter over the real guard method,
	 * and both enforcement points deny with identical wording.
	 *
	 * Divergent wording would let a caller distinguish the write path from
	 * the transition path, which is exactly what the shared constant exists
	 * to prevent.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsAnAdapterThatDeniesWithTheListenersWording(): void {
		(new OrderFulfilmentGateRegistration())->register($this->recordingContext());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				OrderFulfilmentGuard::class => $this->createMock(OrderFulfilmentGuard::class),
				LoggerInterface::class => $this->createMock(LoggerInterface::class),
				default => null,
			}
		);

		$adapter = ($this->services[self::GUARD_TAG])($container);

		self::assertInstanceOf(RegisterRequiresGuardAdapter::class, $adapter);

		$reflection = new \ReflectionClass($adapter);

		$method = $reflection->getProperty('method');
		$method->setAccessible(true);
		self::assertSame(
			'canVoltooien',
			$method->getValue($adapter),
			'The adapter must delegate to the method the register tag names.'
		);

		$denyMessage = $reflection->getProperty('denyMessage');
		$denyMessage->setAccessible(true);
		self::assertSame(
			OrderFulfilmentEvidenceListener::DENY_MESSAGE,
			$denyMessage->getValue($adapter),
			'Both enforcement points must deny with the same wording.'
		);

	}//end testTheFactoryBuildsAnAdapterThatDeniesWithTheListenersWording()
}//end class
