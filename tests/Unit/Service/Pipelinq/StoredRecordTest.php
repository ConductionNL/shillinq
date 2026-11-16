<?php

/**
 * Unit tests for StoredRecord.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\StoredRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The id and array form of a record the object service hands back.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(StoredRecord::class)]
final class StoredRecordTest extends TestCase {

	/**
	 * An entity double with getObject() and getUuid().
	 *
	 * @param array<string,mixed> $object The getObject() answer.
	 * @param string              $uuid   The getUuid() answer.
	 *
	 * @return object The double.
	 */
	private function entity(array $object, string $uuid): object {
		return new class($object, $uuid) {
			/**
			 * @param array<string,mixed> $object The payload.
			 * @param string              $uuid   The uuid.
			 */
			public function __construct(private array $object, private string $uuid) {
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->object;
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}

	/**
	 * An array's id is read, and an empty or missing one is no id.
	 *
	 * @return void
	 */
	public function testTheIdOfAnArray(): void {
		self::assertSame('e-1', (new StoredRecord())->idOf(['id' => 'e-1']));
		self::assertNull((new StoredRecord())->idOf(['id' => '']));
		self::assertNull((new StoredRecord())->idOf([]));
		self::assertNull((new StoredRecord())->idOf('e-1'));
	}

	/**
	 * An entity's id comes from getUuid(), else its payload, else getId().
	 *
	 * @return void
	 */
	public function testTheIdOfAnEntityPrefersTheUuidThenThePayloadThenGetId(): void {
		$uuid = new class {
			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'u-1';
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return 7;
			}
		};
		$payload = new class implements \JsonSerializable {
			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'p-1']];
			}
		};
		$plain = new class {
			/**
			 * @return int
			 */
			public function getId(): int {
				return 7;
			}
		};

		self::assertSame('u-1', (new StoredRecord())->idOf($uuid));
		self::assertSame('p-1', (new StoredRecord())->idOf($payload));
		self::assertSame('7', (new StoredRecord())->idOf($plain));
		self::assertNull((new StoredRecord())->idOf(new \stdClass()));
	}

	/**
	 * Arrays pass through, null and scalars are no record, entities are flattened.
	 *
	 * @return void
	 */
	public function testTheArrayFormOfARecord(): void {
		$serialisable = new class implements \JsonSerializable {
			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 's-1'];
			}
		};
		$inner = $this->entity(['name' => 'x'], 'u-2');
		$innerWithId = $this->entity(['id' => 'own'], 'u-3');

		self::assertSame(['a' => 1], (new StoredRecord())->toArray(['a' => 1]));
		self::assertNull((new StoredRecord())->toArray(null));
		self::assertNull((new StoredRecord())->toArray('x'));
		self::assertSame(['id' => 's-1'], (new StoredRecord())->toArray($serialisable));
		self::assertSame(['name' => 'x', 'id' => 'u-2'], (new StoredRecord())->toArray($inner));
		self::assertSame(['id' => 'own'], (new StoredRecord())->toArray($innerWithId));
	}
}
