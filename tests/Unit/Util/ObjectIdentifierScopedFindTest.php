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
 * findOne() over a service that reports its register and schema, with a find()
 * that behaves like OpenRegister's: scoped only by its arguments, searching
 * every schema by uuid or slug without them, and retrying a uuid miss without
 * the schema.
 *
 * @covers \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ObjectIdentifierScopedFindTest extends TestCase {

	private const OWN = '11111111-1111-4111-8111-111111111111';

	private const OTHER = '22222222-2222-4222-8222-222222222222';

	/**
	 * A schema or register as OpenRegister answers getCurrent*Entity().
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 */
	private static function named(int $id, string $slug): object {
		return new class($id, $slug) {
			public function __construct(private int $id, private string $slug) {
			}
			public function getId(): int {
				return $this->id;
			}
			public function getUuid(): string {
				return 'uuid-' . $this->slug;
			}
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}

	/**
	 * The service, scoped to register 1 (shillinq), schema 7 (APTransaction).
	 *
	 * @param bool $reportsScope Whether it answers getCurrent*Entity().
	 */
	private static function service(bool $reportsScope = true): object {
		$rows = [
			['uuid' => self::OWN, 'slug' => null, 'schema' => '7', 'data' => ['name' => 'own']],
			['uuid' => self::OTHER, 'slug' => 'inv-1', 'schema' => '9', 'data' => ['name' => 'other']],
		];
		$register = $reportsScope ? self::named(1, 'shillinq') : null;
		$schema = $reportsScope ? self::named(7, 'APTransaction') : null;
		return new class($rows, $register, $schema) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];
			public function __construct(private array $rows, private ?object $register, private ?object $schema) {
			}
			public function getCurrentRegisterEntity(): ?object {
				return $this->register;
			}
			public function getCurrentSchemaEntity(): ?object {
				return $this->schema;
			}
			public function find(string $id, ?\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions $options = null, bool $_rbac = true): ObjectEntity {
				$register = $options?->register;
				$schema = $options?->schema;
				$this->calls[] = ['find', $id, $register?->getSlug(), $schema?->getSlug(), $_rbac];
				$hit = $this->lookup(id: $id, schema: $schema);
				if ($hit === null && $schema !== null && preg_match('/^[0-9a-f-]{36}$/', $id) === 1) {
					$hit = $this->lookup(id: $id, schema: null);
				}
				if ($hit === null) {
					throw new RuntimeException('DoesNotExist');
				}
				$entity = new ObjectEntity();
				$entity->setUuid($hit['uuid']);
				$entity->setSchema($hit['schema']);
				$entity->setObject($hit['data']);
				return $entity;
			}
			private function lookup(string $id, ?object $schema): ?array {
				foreach ($this->rows as $row) {
					if ($schema !== null && $row['schema'] !== (string)$schema->getId()) {
						continue;
					}
					if ($row['uuid'] === $id || $row['slug'] === $id) {
						return $row;
					}
				}
				return null;
			}
			public function findAll(array $config = [], bool $_rbac = true): array {
				$this->calls[] = ['findAll', $config, $_rbac];
				return [['name' => 'by property']];
			}
		};
	}

	public function testAnObjectOfTheScopedSchemaIsFound(): void {
		$service = self::service();
		self::assertSame(['name' => 'own'], ObjectIdentifier::findOne($service, self::OWN, null, ['_rbac' => false]));
		self::assertSame([['find', self::OWN, 'shillinq', 'APTransaction', false]], $service->calls);
	}

	public function testAnObjectOfAnotherSchemaIsNotFound(): void {
		self::assertNull(ObjectIdentifier::findOne(self::service(), self::OTHER));
		self::assertNull(ObjectIdentifier::findOne(self::service(), 'inv-1'));
	}

	public function testAnotherSchemasUuidFallsBackToThePropertyMatch(): void {
		$service = self::service();
		self::assertSame(['name' => 'by property'], ObjectIdentifier::findOne($service, self::OTHER, 'code'));
		self::assertSame('findAll', $service->calls[1][0]);
	}

	public function testAServiceWithoutAScopeKeepsTheUnscopedLookup(): void {
		$service = self::service(false);
		self::assertSame(['name' => 'other'], ObjectIdentifier::findOne($service, self::OTHER));
		self::assertSame([['find', self::OTHER, null, null, true]], $service->calls);
	}

	public function testAnAnswerWithoutASchemaIsNotTrustedWhenTheServiceIsScoped(): void {
		$service = new class() {
			public function getCurrentRegisterEntity(): object {
				return new \stdClass();
			}
			public function getCurrentSchemaEntity(): object {
				return new class() {
					public function getId(): int {
						return 7;
					}
				};
			}
			public function find(string $id, mixed ...$named): array {
				return ['name' => 'unchecked'];
			}
		};
		self::assertNull(ObjectIdentifier::findOne($service, self::OWN));
	}

	public function testASchemaWithoutAnyNameRefusesTheAnswer(): void {
		$service = new class() {
			public function getCurrentRegisterEntity(): object {
				return new \stdClass();
			}
			public function getCurrentSchemaEntity(): object {
				return new class() {
					public function getId(): string {
						return '';
					}
				};
			}
			public function find(string $id, mixed ...$named): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setSchema('');
				$entity->setObject(['name' => 'nameless']);
				return $entity;
			}
		};
		self::assertNull(ObjectIdentifier::findOne($service, self::OWN));
	}

	public function testAnAnswerThatNamesTheSchemaByUuidOrSlugIsAccepted(): void {
		foreach (['uuid-APTransaction', 'APTransaction'] as $stored) {
			$service = new class($stored) {
				public function __construct(private string $stored) {
				}
				public function getCurrentRegisterEntity(): object {
					return new \stdClass();
				}
				public function getCurrentSchemaEntity(): object {
					return new class() {
						public function getId(): int {
							return 7;
						}
						public function getUuid(): string {
							return 'uuid-APTransaction';
						}
						public function getSlug(): string {
							return 'APTransaction';
						}
					};
				}
				public function find(string $id, mixed ...$named): ObjectEntity {
					$entity = new ObjectEntity();
					$entity->setSchema($this->stored);
					$entity->setObject(['name' => 'by ' . $this->stored]);
					return $entity;
				}
			};
			self::assertSame(['name' => 'by ' . $stored], ObjectIdentifier::findOne($service, self::OWN));
		}
	}
}
