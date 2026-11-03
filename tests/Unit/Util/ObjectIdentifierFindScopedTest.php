<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * findScoped() answers with the entity of the service's register and schema,
 * over the OpenRegister-like service of Rw124FindOneProbeTest (magic-accessor
 * schema, find() scoped only by its arguments, uuid miss retried without the
 * schema).
 *
 * @covers \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ObjectIdentifierFindScopedTest extends TestCase {

	public function testTheScopedSchemasEntityIsReturned(): void {
		$service = Rw124FindOneProbeTest::service(true);
		$found = ObjectIdentifier::findScoped($service, Rw124FindOneProbeTest::OWN, ['_rbac' => false]);
		self::assertInstanceOf(ObjectEntity::class, $found);
		self::assertSame('own', $found->getObject()['name']);
		self::assertSame([['find', Rw124FindOneProbeTest::OWN, 3, 40, false]], $service->calls);
	}

	public function testAnotherSchemasEntityIsNull(): void {
		self::assertNull(ObjectIdentifier::findScoped(Rw124FindOneProbeTest::service(true), Rw124FindOneProbeTest::OTHER));
		self::assertNull(ObjectIdentifier::findScoped(Rw124FindOneProbeTest::service(false), Rw124FindOneProbeTest::OTHER));
	}

	public function testAMissStillThrows(): void {
		$this->expectException(RuntimeException::class);
		ObjectIdentifier::findScoped(Rw124FindOneProbeTest::service(true), 'nope');
	}

	public function testAnotherSchemasSlugIsAMissInTheScopedSchema(): void {
		$this->expectException(RuntimeException::class);
		ObjectIdentifier::findScoped(Rw124FindOneProbeTest::service(true), 'inv-1');
	}

	public function testAnUnscopedServiceGetsFindsAnswerUnchanged(): void {
		$service = new class() {
			public array $calls = [];
			public function find(string $id, mixed ...$named): array {
				$this->calls[] = [$id, $named];
				return ['id' => $id];
			}
		};
		self::assertSame(['id' => 'x'], ObjectIdentifier::findScoped($service, 'x', ['_rbac' => false]));
		self::assertSame([['x', ['_rbac' => false]]], $service->calls);
	}
}
