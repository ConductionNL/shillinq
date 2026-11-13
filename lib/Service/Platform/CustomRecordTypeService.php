<?php

/**
 * Custom Record Type Service
 *
 * Tells the schemas shillinq ships from the ones an administrator added to
 * the shillinq register in Open Register, and keeps the administrator's
 * choice of which of those own record types shillinq shows. A schema is
 * shipped when its slug is a `components.schemas` key of
 * `lib/Settings/shillinq_register.json` or of any fragment in
 * `lib/Settings/register.d/`; every other schema of the register is custom.
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
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Platform;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shipped versus custom schemas of the shillinq register, and the shown list.
 */
class CustomRecordTypeService {

	/**
	 * App config key holding the JSON list of shown custom slugs.
	 */
	public const CONFIG_KEY = 'custom_record_types_shown';

	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Lowercased shipped slugs, read once per request.
	 *
	 * @var array<string,true>|null
	 */
	private ?array $shipped = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container        Resolves Open Register's mappers when installed
	 * @param RegisterLocation   $registerLocation The configured register slug
	 * @param IAppConfig         $appConfig        Holds the shown list
	 * @param LoggerInterface    $logger           Logs an unreadable register
	 * @param string|null        $settingsDir      Directory holding the shipped register files
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RegisterLocation $registerLocation,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?string $settingsDir = null,
	) {
	}//end __construct()

	/**
	 * The slugs of every schema shillinq ships, lowercased.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	public function shippedSlugs(): array {
		if ($this->shipped === null) {
			$this->shipped = [];
			$dir = $this->settingsDir ?? __DIR__ . '/../../Settings';
			$files = glob($dir . '/register.d/*.json');
			if ($files === false) {
				$files = [];
			}

			array_unshift($files, $dir . '/shillinq_register.json');
			foreach ($files as $file) {
				foreach ($this->schemaKeysOf(file: $file) as $slug) {
					$this->shipped[strtolower($slug)] = true;
				}
			}
		}

		return array_keys($this->shipped);
	}//end shippedSlugs()

	/**
	 * Whether a slug belongs to a schema shillinq ships.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
	 */
	public function isShipped(string $slug): bool {
		return in_array(strtolower($slug), $this->shippedSlugs(), true);
	}//end isShipped()

	/**
	 * The schemas of the shillinq register that the app did not ship.
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string}>
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	public function customTypes(): array {
		$types = [];
		foreach ($this->registerSchemas() as $schema) {
			if ($schema['slug'] === '' || $this->isShipped(slug: $schema['slug']) === true) {
				continue;
			}

			$types[strtolower($schema['slug'])] = $schema;
		}

		$types = array_values($types);
		usort($types, static fn (array $left, array $right): int => strcasecmp($left['title'], $right['title']));

		return $types;
	}//end customTypes()

	/**
	 * The custom types an administrator chose to show.
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string}>
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
	 */
	public function shownTypes(): array {
		$shown = array_map('strtolower', $this->storedSlugs());

		return array_values(
			array_filter(
				$this->customTypes(),
				static fn (array $type): bool => in_array(strtolower($type['slug']), $shown, true)
			)
		);
	}//end shownTypes()

	/**
	 * Every custom type with whether it is shown, for the admin settings.
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string,shown:bool}>
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	public function customTypesWithState(): array {
		$shown = array_map('strtolower', $this->storedSlugs());

		return array_map(
			static fn (array $type): array => $type + ['shown' => in_array(strtolower($type['slug']), $shown, true)],
			$this->customTypes()
		);
	}//end customTypesWithState()

	/**
	 * The shown state of every custom type as a map of slug to bool, the
	 * shape the admin settings' switch table reads.
	 *
	 * @return array<string,bool>
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-an-administrator-chooses-which-own-record-types-show-req-crt-001
	 */
	public function shownMap(): array {
		return array_column($this->customTypesWithState(), 'shown', 'slug');
	}//end shownMap()

	/**
	 * Switch one custom type on or off, keeping the others as stored.
	 *
	 * @param string $slug  The schema slug
	 * @param bool   $shown Whether to show it
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string}> The shown types after saving
	 *
	 * @throws InvalidArgumentException When the slug is shipped or does not exist
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
	 */
	public function switchShown(string $slug, bool $shown): array {
		$current = array_column($this->shownTypes(), 'slug');
		$others = array_values(
			array_filter($current, static fn (string $kept): bool => strcasecmp($kept, $slug) !== 0)
		);
		if ($shown === true) {
			$others[] = $slug;
		}

		// Refuse a shipped or unknown slug, also when switching it off.
		$this->canonicalSlugs(slugs: [$slug]);

		return $this->saveShown(slugs: $others);
	}//end switchShown()

	/**
	 * Store the shown list. Every slug must be a custom type of the register.
	 *
	 * @param array<mixed> $slugs The slugs to show
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string}> The shown types after saving
	 *
	 * @throws InvalidArgumentException When a slug is not a string, is shipped or does not exist
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
	 */
	public function saveShown(array $slugs): array {
		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			self::CONFIG_KEY,
			(string)json_encode($this->canonicalSlugs(slugs: $slugs))
		);

		return $this->shownTypes();
	}//end saveShown()

	/**
	 * The register's own spelling of each slug, once each, in the given order.
	 *
	 * @param array<mixed> $slugs The slugs to check
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When a slug is not a string, is shipped or does not exist
	 */
	private function canonicalSlugs(array $slugs): array {
		$custom = [];
		foreach ($this->customTypes() as $type) {
			$custom[strtolower($type['slug'])] = $type['slug'];
		}

		$canonical = [];
		foreach ($slugs as $slug) {
			if (is_string($slug) === false || $slug === '') {
				throw new InvalidArgumentException('Every record type must be a schema slug');
			}

			if ($this->isShipped(slug: $slug) === true) {
				throw new InvalidArgumentException('Record type "' . $slug . '" ships with shillinq and cannot be chosen');
			}

			if (isset($custom[strtolower($slug)]) === false) {
				throw new InvalidArgumentException('Record type "' . $slug . '" does not exist in the register');
			}

			$canonical[strtolower($slug)] = $custom[strtolower($slug)];
		}

		return array_values($canonical);
	}//end canonicalSlugs()

	/**
	 * The slugs stored as shown; anything unreadable counts as none.
	 *
	 * @return string[]
	 */
	private function storedSlugs(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '[]');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, static fn ($slug): bool => is_string($slug) === true && $slug !== ''));
	}//end storedSlugs()

	/**
	 * The `components.schemas` keys of one register file.
	 *
	 * @param string $file The register file path
	 *
	 * @return string[]
	 */
	private function schemaKeysOf(string $file): array {
		if (is_file($file) === false) {
			return [];
		}

		$data = json_decode((string)file_get_contents($file), true);
		$schemas = $data['components']['schemas'] ?? null;
		if (is_array($schemas) === false) {
			return [];
		}

		return array_map('strval', array_keys($schemas));
	}//end schemaKeysOf()

	/**
	 * Every schema of the configured register, or none when Open Register
	 * is absent or the register cannot be read.
	 *
	 * @return array<int,array{slug:string,title:string,description:string,icon:?string}>
	 */
	private function registerSchemas(): array {
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			return [];
		}

		try {
			$register = $this->container->get(self::REGISTER_MAPPER)->find($this->registerLocation->getRegisterSlug(), false, false);
			$schemaMapper = $this->container->get(self::SCHEMA_MAPPER);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: cannot read the register for custom record types', ['exception' => $e->getMessage()]);
			return [];
		}

		$schemas = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $schemaMapper->find($schemaId, [], false, false);
			} catch (Throwable $e) {
				continue;
			}

			$slug = (string)$schema->getSlug();
			$schemas[] = [
				'slug'        => $slug,
				'title'       => (string)($schema->getTitle() ?? $slug),
				'description' => (string)($schema->getDescription() ?? ''),
				'icon'        => $schema->getIcon(),
			];
		}

		return $schemas;
	}//end registerSchemas()
}//end class
