<?php

/**
 * FleetAppId — class and service lookup across a renamed app's namespaces.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Support;

use OCA\Shillinq\Support\FleetAppId;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * resolveClass() returns the first candidate class or interface that exists,
 * and getService() returns the first candidate the container yields as an
 * object, skipping names that throw or yield a non-object.
 *
 * @covers \OCA\Shillinq\Support\FleetAppId
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FleetAppIdServiceLookupTest extends TestCase {

	/**
	 * Declare a class or interface once, by its fully qualified name.
	 *
	 * @param string $fqcn The class name to declare.
	 * @param string $kind 'class' or 'interface'.
	 *
	 * @return void
	 */
	private static function declare(string $fqcn, string $kind='class'): void {
		if (class_exists($fqcn) === true || interface_exists($fqcn) === true) {
			return;
		}

		$pos = strrpos($fqcn, '\\');
		eval('namespace '.substr($fqcn, 0, $pos).'; '.$kind.' '.substr($fqcn, ($pos + 1)).' {}');

	}//end declare()

	/**
	 * With both namespaces present, the new name wins.
	 *
	 * @return void
	 */
	public function testResolveClassPrefersTheNewNamespace(): void {
		self::declare('OCA\Larpinq\Service\FleetAppIdLookupProbe');
		self::declare('OCA\LarpingApp\Service\FleetAppIdLookupProbe');

		$this->assertSame(
			'OCA\Larpinq\Service\FleetAppIdLookupProbe',
			FleetAppId::resolveClass('larpinq', '\Service\FleetAppIdLookupProbe')
		);

	}//end testResolveClassPrefersTheNewNamespace()

	/**
	 * An instance on the old release resolves to the retired namespace, and an
	 * interface counts as well as a class.
	 *
	 * @return void
	 */
	public function testResolveClassFallsBackToTheRetiredNamespaceAndFindsInterfaces(): void {
		self::declare('OCA\Procest\Service\FleetAppIdLookupContract', 'interface');

		$this->assertSame(
			'OCA\Procest\Service\FleetAppIdLookupContract',
			FleetAppId::resolveClass('dossiq', 'Service\FleetAppIdLookupContract')
		);

	}//end testResolveClassFallsBackToTheRetiredNamespaceAndFindsInterfaces()

	/**
	 * The first registered candidate is returned and later ones are not asked for.
	 *
	 * @return void
	 */
	public function testGetServiceReturnsTheFirstRegisteredCandidate(): void {
		$service   = new stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\Filinq\Service\SigningService')
			->willReturn($service);

		$this->assertSame($service, FleetAppId::getService($container, 'filinq', 'Service\SigningService'));

	}//end testGetServiceReturnsTheFirstRegisteredCandidate()

	/**
	 * A candidate that throws is skipped and the retired name is tried next.
	 *
	 * @return void
	 */
	public function testGetServiceSkipsACandidateThatThrows(): void {
		$service = new stdClass();
		$asked   = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(
				static function (string $id) use ($service, &$asked): object {
					$asked[] = $id;
					if ($id === 'OCA\Filinq\Service\SigningService') {
						throw new RuntimeException('not registered');
					}

					return $service;
				}
			);

		$this->assertSame($service, FleetAppId::getService($container, 'filinq', 'Service\SigningService'));
		$this->assertSame(['OCA\Filinq\Service\SigningService', 'OCA\DocuDesk\Service\SigningService'], $asked);

	}//end testGetServiceSkipsACandidateThatThrows()

	/**
	 * A non-object answer counts as absent; with no candidate left the result is null.
	 *
	 * @return void
	 */
	public function testGetServiceReturnsNullWhenNoCandidateYieldsAnObject(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(
				static function (string $id): string {
					if ($id === 'OCA\Keepiq\Service\VaultService') {
						return 'not-an-object';
					}

					throw new RuntimeException('not registered');
				}
			);

		$this->assertNull(FleetAppId::getService($container, 'keepiq', 'Service\VaultService'));

	}//end testGetServiceReturnsNullWhenNoCandidateYieldsAnObject()

	/**
	 * An app without a namespace map is never looked up.
	 *
	 * @return void
	 */
	public function testGetServiceForAnUnknownAppAsksNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertNull(FleetAppId::getService($container, 'no-such-app', 'Service\Anything'));

	}//end testGetServiceForAnUnknownAppAsksNothing()
}//end class
