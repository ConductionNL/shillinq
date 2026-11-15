<?php

/**
 * Unit tests for SchemaDefinitions: reading a schema's slug, title,
 * properties and required list through OpenRegister's schema mapper, the
 * per-instance cache, and the null answer when the schema cannot be read.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Platform
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

namespace OCA\Shillinq\Tests\Unit\Service\Platform;

use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Schema definitions read from OpenRegister.
 */
#[CoversClass(SchemaDefinitions::class)]
final class SchemaDefinitionsTest extends TestCase {

	/**
	 * Calls made to the mapper's find(), in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $finds = [];

	/**
	 * A schema entity double.
	 *
	 * @param mixed $properties The properties
	 * @param mixed $required The required list
	 * @param mixed $slug The slug
	 * @param mixed $title The title
	 *
	 * @return object
	 */
	private static function schema(mixed $properties, mixed $required, mixed $slug, mixed $title): object {
		return new class($properties, $required, $slug, $title) {
			/**
			 * Constructor.
			 *
			 * @param mixed $properties The properties
			 * @param mixed $required The required list
			 * @param mixed $slug The slug
			 * @param mixed $title The title
			 */
			public function __construct(
				private mixed $properties,
				private mixed $required,
				private mixed $slug,
				private mixed $title,
			) {
			}

			/**
			 * Properties.
			 *
			 * @return mixed
			 */
			public function getProperties(): mixed {
				return $this->properties;
			}

			/**
			 * Required fields.
			 *
			 * @return mixed
			 */
			public function getRequired(): mixed {
				return $this->required;
			}

			/**
			 * Slug.
			 *
			 * @return mixed
			 */
			public function getSlug(): mixed {
				return $this->slug;
			}

			/**
			 * Title.
			 *
			 * @return mixed
			 */
			public function getTitle(): mixed {
				return $this->title;
			}
		};

	}//end schema()

	/**
	 * Build the service over a mapper that answers from a map of schemas.
	 *
	 * @param array<string, object> $schemas Schema doubles by id
	 * @param LoggerInterface|null $logger Optional logger
	 *
	 * @return SchemaDefinitions
	 */
	private function service(array $schemas, ?LoggerInterface $logger = null): SchemaDefinitions {
		$finds = &$this->finds;
		$mapper = new class($schemas, $finds) {
			/**
			 * Constructor.
			 *
			 * @param array<string, object> $schemas Schemas by id
			 * @param array<int, mixed> $finds Recorded calls
			 */
			public function __construct(private array $schemas, private array &$finds) {
			}

			/**
			 * Find a schema.
			 *
			 * @param mixed ...$args The call arguments
			 *
			 * @return object
			 */
			public function find(mixed ...$args): object {
				$this->finds[] = $args;
				if (isset($this->schemas[$args[0]]) === false) {
					throw new RuntimeException('Schema ' . $args[0] . ' not found');
				}

				return $this->schemas[$args[0]];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);

		return new SchemaDefinitions(container: $container, logger: ($logger ?? $this->createMock(LoggerInterface::class)));

	}//end service()

	/**
	 * A schema is read into slug, title, properties and a reindexed list of required names.
	 *
	 * @return void
	 */
	public function testDefinitionIsReadFromTheMapper(): void {
		$service = $this->service(
			[
				'Invoice' => self::schema(
					['administrationId' => ['type' => 'string']],
					[3 => 'administrationId', 7 => 12],
					'invoice',
					'Invoice'
				),
			]
		);

		$this->assertSame(
			[
				'slug' => 'invoice',
				'title' => 'Invoice',
				'properties' => ['administrationId' => ['type' => 'string']],
				'required' => ['administrationId', '12'],
			],
			$service->definition('Invoice')
		);
		$this->assertSame([['Invoice', [], false, false]], $this->finds);

	}//end testDefinitionIsReadFromTheMapper()

	/**
	 * Without a title the slug is the title; null properties and required lists become empty.
	 *
	 * @return void
	 */
	public function testMissingTitleAndListsFallBack(): void {
		$service = $this->service(['Note' => self::schema(null, null, 'note', null)]);

		$this->assertSame(
			['slug' => 'note', 'title' => 'note', 'properties' => [], 'required' => []],
			$service->definition('Note')
		);

	}//end testMissingTitleAndListsFallBack()

	/**
	 * Each schema is read once per instance, also when it could not be read.
	 *
	 * @return void
	 */
	public function testDefinitionsAreCached(): void {
		$service = $this->service(['Invoice' => self::schema([], [], 'invoice', 'Invoice')]);

		$first = $service->definition('Invoice');
		$this->assertSame($first, $service->definition('Invoice'));
		$this->assertNull($service->definition('Missing'));
		$this->assertNull($service->definition('Missing'));

		$this->assertSame(['Invoice', 'Missing'], array_column($this->finds, 0));

	}//end testDefinitionsAreCached()

	/**
	 * An unreadable schema is logged at debug level and answers null.
	 *
	 * @return void
	 */
	public function testUnreadableSchemaIsLoggedAndNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with('Shillinq: no schema definition', ['schema' => 'Ghost', 'exception' => 'Schema Ghost not found']);

		$this->assertNull($this->service([], $logger)->definition('Ghost'));

	}//end testUnreadableSchemaIsLoggedAndNull()

	/**
	 * An empty schema name answers null without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testEmptySchemaIsNull(): void {
		$this->assertNull($this->service([])->definition(''));
		$this->assertSame([], $this->finds);

	}//end testEmptySchemaIsNull()
}//end class
