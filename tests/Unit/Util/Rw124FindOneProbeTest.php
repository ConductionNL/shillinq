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
 * RW124 probe (reviewer-only): findOne over an OpenRegister-faithful service.
 *
 * - getCurrentRegisterEntity()/getCurrentSchemaEntity() answer entities whose
 *   accessors are MAGIC, like OR's Schema/Register over OCP\AppFramework\Db\Entity
 *   (`public $id`, everything through __call; method_exists() is false).
 * - find(): scoped only by its register/schema arguments; uuid miss retried with
 *   the register kept and the schema dropped; slug and numeric id lookups; the
 *   ObjectEntity carries the schema as its numeric id string.
 * - findAll(): scoped by the service's current schema (OR uses currentSchema).
 *
 * @covers \OCA\Shillinq\Util\ObjectIdentifier
 */
final class Rw124FindOneProbeTest extends TestCase {

	public const OWN = '11111111-1111-4111-8111-111111111111';
	public const OTHER = '22222222-2222-4222-8222-222222222222';
	public const OTHER_REG = '33333333-3333-4333-8333-333333333333';

	/** Like OR's Schema: accessors only through __call, as NC Entity serves them. */
	public static function magicEntity(int $id, string $slug): object {
		return new class($id, $slug) {
			public $id;
			protected $slug;
			protected $uuid;
			public function __construct(int $id, string $slug) {
				$this->id = $id;
				$this->slug = $slug;
				$this->uuid = 'uuid-' . $slug;
			}
			public function __call(string $name, array $args) {
				if (str_starts_with($name, 'get') === true) {
					$p = lcfirst(substr($name, 3));
					return $this->$p;
				}
				throw new \BadFunctionCallException($name);
			}
		};
	}

	public static function service(bool $magic = true): object {
		return new class($magic) {
			public array $calls = [];
			private object $register;
			private object $schema;
			private array $rows = [
				['id' => 1, 'uuid' => Rw124FindOneProbeTest::OWN, 'slug' => 'own-slug', 'register' => 3, 'schema' => 40, 'data' => ['name' => 'own', 'code' => 'C-1']],
				['id' => 2, 'uuid' => Rw124FindOneProbeTest::OTHER, 'slug' => 'inv-1', 'register' => 3, 'schema' => 41, 'data' => ['name' => 'other', 'code' => 'C-1']],
				['id' => 3, 'uuid' => Rw124FindOneProbeTest::OTHER_REG, 'slug' => null, 'register' => 9, 'schema' => 90, 'data' => ['name' => 'foreign register', 'code' => 'C-1']],
			];
			public function __construct(bool $magic) {
				if ($magic === true) {
					$this->register = Rw124FindOneProbeTest::magicEntity(3, 'shillinq');
					$this->schema = Rw124FindOneProbeTest::magicEntity(40, 'APTransaction');
				} else {
					$this->register = new class() {
						public function getId(): int { return 3; }
						public function getSlug(): string { return 'shillinq'; }
					};
					$this->schema = new class() {
						public function getId(): int { return 40; }
						public function getUuid(): string { return 'uuid-APTransaction'; }
						public function getSlug(): string { return 'APTransaction'; }
					};
				}
			}
			public function getCurrentRegisterEntity(): ?object {
				return $this->register;
			}
			public function getCurrentSchemaEntity(): ?object {
				return $this->schema;
			}
			private function get($id, ?int $register, ?int $schema): ObjectEntity {
				foreach ($this->rows as $r) {
					if (($register !== null && $r['register'] !== $register) || ($schema !== null && $r['schema'] !== $schema)) {
						continue;
					}
					if ($r['uuid'] === (string)$id || ($r['slug'] !== null && $r['slug'] === (string)$id) || (is_numeric($id) && (int)$id === $r['id'])) {
						$e = new ObjectEntity();
						$e->setUuid($r['uuid']);
						$e->setRegister((string)$r['register']);
						$e->setSchema((string)$r['schema']);
						$e->setObject($r['data']);
						return $e;
					}
				}
				throw new RuntimeException('DoesNotExist');
			}
			public function find(int|string $id, ?\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				$register = $options?->register;
				$schema = $options?->schema;
				$reg = $register === null ? null : (int)$register->getId();
				$sch = $schema === null ? null : (int)$schema->getId();
				$this->calls[] = ['find', (string)$id, $reg, $sch, $_rbac];
				try {
					return $this->get($id, $reg, $sch);
				} catch (RuntimeException $e) {
					if (($reg === null && $sch === null) || preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/', (string)$id) !== 1) {
						throw $e;
					}
					return $this->get($id, $reg, null);
				}
			}
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['findAll', $config, $_rbac];
				$out = [];
				foreach ($this->rows as $r) {
					if ($r['schema'] !== 40) {
						continue;
					}
					foreach (($config['filters'] ?? []) as $k => $v) {
						if (($r['data'][$k] ?? null) !== $v) {
							continue 2;
						}
					}
					$e = new ObjectEntity();
					$e->setUuid($r['uuid']);
					$e->setSchema((string)$r['schema']);
					$e->setObject($r['data']);
					$out[] = $e;
				}
				return array_slice($out, 0, (int)($config['limit'] ?? 100));
			}
		};
	}

	// ---- legitimate lookups against a REAL-shaped (magic-accessor) schema ----
	public function testOwnUuidIsFoundWithRealShapedSchema(): void {
		$r = ObjectIdentifier::findOne(self::service(true), self::OWN);
		self::assertSame('own', $r['name'] ?? null, 'FINDING L1: own-schema uuid not found when the schema entity has magic accessors (real OR)');
	}

	public function testOwnNumericIdIsFoundWithRealShapedSchema(): void {
		$r = ObjectIdentifier::findOne(self::service(true), '1');
		self::assertSame('own', $r['name'] ?? null, 'FINDING L1b');
	}

	public function testOwnUuidRbacOffIsFoundWithRealShapedSchema(): void {
		$r = ObjectIdentifier::findOne(self::service(true), self::OWN, null, ['_rbac' => false]);
		self::assertSame('own', $r['name'] ?? null, 'FINDING L1c');
	}

	// ---- same with declared accessors (the PR's double shape) ----
	public function testOwnUuidIsFoundWithDeclaredAccessors(): void {
		self::assertSame('own', ObjectIdentifier::findOne(self::service(false), self::OWN)['name'] ?? null);
	}

	// ---- wrong schema / register never answers ----
	public function testOtherSchemaUuidIsRefused(): void {
		self::assertNull(ObjectIdentifier::findOne(self::service(true), self::OTHER));
		self::assertNull(ObjectIdentifier::findOne(self::service(false), self::OTHER));
	}

	public function testOtherSchemaSlugIsRefused(): void {
		self::assertNull(ObjectIdentifier::findOne(self::service(true), 'inv-1'));
		self::assertNull(ObjectIdentifier::findOne(self::service(false), 'inv-1'));
	}

	public function testOtherSchemaNumericIdIsRefused(): void {
		self::assertNull(ObjectIdentifier::findOne(self::service(false), '2'));
	}

	public function testOtherRegisterUuidIsRefused(): void {
		self::assertNull(ObjectIdentifier::findOne(self::service(false), self::OTHER_REG));
		self::assertNull(ObjectIdentifier::findOne(self::service(true), self::OTHER_REG));
	}

	// ---- the fallback stays in the current schema ----
	public function testFallbackAfterOtherSchemaUuidStaysInSchema(): void {
		$r = ObjectIdentifier::findOne(self::service(false), self::OTHER, 'code');
		self::assertNull($r, 'fallback must not match: the filter is code=<uuid>, which nothing carries');
		$r = ObjectIdentifier::findOne(self::service(false), 'C-1', 'code');
		self::assertSame('own', $r['name'] ?? null, 'fallback answers from the current schema only');
	}

	public function testLookupCarriesRegisterAndSchemaArguments(): void {
		$s = self::service(false);
		ObjectIdentifier::findOne($s, self::OWN, null, ['_rbac' => false]);
		self::assertSame(['find', self::OWN, 3, 40, false], $s->calls[0]);
	}
}
