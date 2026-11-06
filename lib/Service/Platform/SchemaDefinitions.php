<?php

/**
 * SchemaDefinitions: a schema's slug, title, properties and shipped required list, as OpenRegister holds them.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Platform
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

namespace OCA\Shillinq\Service\Platform;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a schema through OpenRegister's schema mapper, by id or slug, once per request.
 *
 * The listener resolves the slug here rather than through ListenerSchemaResolver::schemaSlug(),
 * which answers the raw schema id while `listener_slug_contract` is off (the default), so a
 * check built on it would never run on a default instance.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
class SchemaDefinitions {

	/**
	 * OpenRegister's schema mapper, resolved by name so shillinq loads without OpenRegister.
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Definitions read in this request, by the id or slug asked for.
	 *
	 * @var array<string, array{slug: string, title: string, properties: array<string, mixed>, required: list<string>}|null>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * A schema by id or slug, or null when there is none.
	 *
	 * @param string $schema The schema id or slug.
	 *
	 * @return array{slug: string, title: string, properties: array<string, mixed>, required: list<string>}|null
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function definition(string $schema): ?array {
		if ($schema === '') {
			return null;
		}

		if (array_key_exists($schema, $this->cache) === false) {
			$this->cache[$schema] = $this->read(schema: $schema);
		}

		return $this->cache[$schema];

	}//end definition()

	/**
	 * Read one schema from the mapper.
	 *
	 * @param string $schema The schema id or slug.
	 *
	 * @return array{slug: string, title: string, properties: array<string, mixed>, required: list<string>}|null
	 */
	private function read(string $schema): ?array {
		try {
			$found = $this->container->get(self::SCHEMA_MAPPER)->find($schema, [], false, false);
			$properties = $found->getProperties();
			$required = $found->getRequired();
			$slug = (string)$found->getSlug();

			return [
				'slug'       => $slug,
				'title'      => (string)($found->getTitle() ?? $slug),
				'properties' => (array)$properties,
				'required'   => array_values(array_map('strval', (array)$required)),
			];
		} catch (Throwable $e) {
			$this->logger->debug('Shillinq: no schema definition', ['schema' => $schema, 'exception' => $e->getMessage()]);
		}

		return null;

	}//end read()
}//end class
