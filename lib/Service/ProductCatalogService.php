<?php

/**
 * Product Catalog Service
 *
 * The read side of shillinq's inventory product catalog (#860), and the first
 * implementation of the `pipelinq-product-master` integration contract declared
 * in `lib/Settings/register.d/shillinq-pipelinq-product-vendor-integration.json`.
 *
 * ## Why this reads pipelinq instead of a local `Product` register
 *
 * `inventory-product-catalog` (REQ-IPC-001 / REQ-IPC-003) originally put a
 * `Product` and a `ProductAttribute` register in shillinq, and commit
 * `726249f4` genuinely built them. `shillinq-product-vendor-to-pipelinq`
 * (REQ-SPVP-004) then deliberately DELETED them in commit `4a1d3275`, moved
 * product-definition ownership to pipelinq, and rewired every inventory schema
 * from `productSku` to a `productId` FK resolved through the ADR-019
 * integration registry. That rewire is live: `InventoryStock`'s uniqueness key
 * is `[productId, locationCode, administrationId]` today.
 *
 * REQ-SPVP-004 spells out the obligation that was NOT built, and it is exactly
 * the one the e2e suite fails on:
 *
 *   > their page ids MUST be added to `src/menu-layout.json` `removals` so the
 *   > routes stay deep-linkable […] a saved deep link to its former route MUST
 *   > still resolve (read-only / redirect) so e2e and bookmarks do not 404.
 *
 * So the catalog is rebuilt as a READ-ONLY surface over the pipelinq master.
 * Re-declaring `Product` in shillinq would satisfy the e2e assertions and the
 * route while re-introducing the duplicate master-data ownership a shipped
 * change removed, and while orphaning every `productId` FK (those ids are
 * pipelinq's, not a local register's). Nothing here authors, writes or
 * persists a product definition.
 *
 * ## What it shows when pipelinq is not installed
 *
 * The integration contract already declares the answer — `"fallback":
 * "localCache"`, "shillinq renders local denormalised caches (productId +
 * unitCost) and flags them stale". {@see listProducts()} implements that
 * literally: it projects the distinct `productId`s this administration's own
 * `InventoryStock` and `Barcode` rows reference, carrying ONLY the fields
 * shillinq is authoritative for, and marks the result non-authoritative.
 *
 * That distinction is the point of the class. A page bound straight to
 * `register: pipelinq, schema: product` would render, answer 404 under the
 * hood, show an empty table and pass every e2e assertion on an install where it
 * can never list a row — the catalog equivalent of shipping a `text/plain`
 * document under a `.pdf` filename.
 *
 * ## `filters` addresses JSON properties
 *
 * Every query below filters on a declared schema property (`administrationId`)
 * or on nothing at all — never on `id`. `findAll(['filters' => ['id' => …]])`
 * matches NOTHING for every value, silently.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only projection of the pipelinq product master for shillinq's inventory.
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */
class ProductCatalogService {
	/**
	 * Register slug the product master lives in (pipelinq owns it per REQ-SPVP-001).
	 *
	 * @var string
	 */
	public const MASTER_REGISTER = 'pipelinq';

	/**
	 * Schema slug of the pipelinq product master.
	 *
	 * @var string
	 */
	public const MASTER_SCHEMA_PRODUCT = 'product';

	/**
	 * Schema slug of the pipelinq product-category master.
	 *
	 * @var string
	 */
	public const MASTER_SCHEMA_CATEGORY = 'productCategory';

	/**
	 * Local schema carrying stock rows that reference a product.
	 *
	 * @var string
	 */
	public const LOCAL_SCHEMA_STOCK = 'InventoryStock';

	/**
	 * Local schema carrying barcodes that reference a product.
	 *
	 * @var string
	 */
	public const LOCAL_SCHEMA_BARCODE = 'Barcode';

	/**
	 * Source marker: rows came from the pipelinq product master.
	 *
	 * @var string
	 */
	public const SOURCE_MASTER = 'pipelinq';

	/**
	 * Source marker: rows were projected from shillinq's own inventory caches.
	 *
	 * @var string
	 */
	public const SOURCE_LOCAL_CACHE = 'local-cache';

	/**
	 * Source marker: the contract's declared field set, used when nothing else resolves.
	 *
	 * @var string
	 */
	public const SOURCE_CONTRACT = 'contract';

	/**
	 * Construct the catalog service.
	 *
	 * @param IAppConfig $appConfig App config (local OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param AdministrationContextService $administrationContext Membership guard (REQ-MA-001).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
	) {

	}//end __construct()

	/**
	 * List the product catalog visible to the authenticated caller (REQ-IPC-008).
	 *
	 * Resolution order is the one the integration contract declares: the
	 * pipelinq master first, its local denormalised cache second.
	 *
	 * REFUSES a caller holding no valid AdministrationMembership (REQ-MA-001)
	 * by answering null — BEFORE the master is consulted. Without that check
	 * the local-cache branch would be scoped and the MASTER branch would not,
	 * so a user with no administration at all would be handed the whole
	 * product master. The refusal is the guard; there is no caller-supplied
	 * identifier anywhere in this class, so scoping to the caller is the only
	 * authorisation decision it has to make.
	 *
	 * @return array{source:string,authoritative:bool,masterAvailable:bool,masterApp:string,total:int,products:list<array<string,mixed>>}|null
	 *         The catalog envelope, or null when the caller holds no
	 *         administration membership. `authoritative` is false whenever the
	 *         rows did not come from the owning app — the UI must say so.
	 *
	 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
	 */
	public function listProducts(): ?array {
		$administrationIds = $this->administrationContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return null;
		}

		$master = $this->query(register: self::MASTER_REGISTER, schema: self::MASTER_SCHEMA_PRODUCT, filters: []);
		if ($master !== []) {
			$categories = $this->categoryNames();
			$products = [];
			foreach ($master as $row) {
				$products[] = $this->mapMasterProduct(row: $row, categories: $categories);
			}

			return [
				'source' => self::SOURCE_MASTER,
				'authoritative' => true,
				'masterAvailable' => true,
				'masterApp' => self::MASTER_REGISTER,
				'total' => count($products),
				'products' => $products,
			];
		}

		$products = $this->projectLocalReferences(administrationIds: $administrationIds);

		return [
			'source' => self::SOURCE_LOCAL_CACHE,
			'authoritative' => false,
			'masterAvailable' => false,
			'masterApp' => self::MASTER_REGISTER,
			'total' => count($products),
			'products' => $products,
		];

	}//end listProducts()

	/**
	 * List the attribute definitions the catalog can serve (REQ-IPC-004).
	 *
	 * When the master is reachable the list is the union of the attribute names
	 * its products actually carry on `variants[]` / `modifierGroups[]`, which is
	 * the only place pipelinq models per-product attributes. When it is not, the
	 * list is the field set the `getProduct` resolver publishes plus the fields
	 * shillinq holds locally — so the page always answers the operator's real
	 * question ("which product attributes does this installation have, and who
	 * owns each one?") instead of rendering an empty table.
	 *
	 * Carries the same membership refusal as {@see listProducts()}, and for the
	 * same reason: when the master IS reachable these rows are derived from its
	 * products' own attribute names, which is data, not documentation.
	 *
	 * @return array{source:string,authoritative:bool,masterAvailable:bool,masterApp:string,total:int,attributes:list<array<string,mixed>>}|null
	 *         The attribute-definition envelope, or null when the caller holds
	 *         no administration membership.
	 *
	 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
	 */
	public function listAttributes(): ?array {
		if ($this->administrationContext->accessibleAdministrationIds() === []) {
			return null;
		}

		$master = $this->query(register: self::MASTER_REGISTER, schema: self::MASTER_SCHEMA_PRODUCT, filters: []);
		if ($master !== []) {
			$attributes = $this->attributesFromMaster(rows: $master);
			if ($attributes !== []) {
				return [
					'source' => self::SOURCE_MASTER,
					'authoritative' => true,
					'masterAvailable' => true,
					'masterApp' => self::MASTER_REGISTER,
					'total' => count($attributes),
					'attributes' => $attributes,
				];
			}
		}

		$attributes = $this->contractAttributes(masterAvailable: ($master !== []));

		return [
			'source' => self::SOURCE_CONTRACT,
			'authoritative' => false,
			'masterAvailable' => ($master !== []),
			'masterApp' => self::MASTER_REGISTER,
			'total' => count($attributes),
			'attributes' => $attributes,
		];

	}//end listAttributes()

	/**
	 * Map one pipelinq product-master row onto the catalog row shape.
	 *
	 * @param array<string,mixed> $row        The master record.
	 * @param array<string,string> $categories Category uuid => name, for the uuid `category` FK.
	 *
	 * @return array<string,mixed> One catalog row.
	 */
	private function mapMasterProduct(array $row, array $categories): array {
		$category = (string)($row['category'] ?? '');

		return [
			'productId' => $this->identifierOf(row: $row),
			'sku' => $this->stringOrNull(value: ($row['sku'] ?? null)),
			'name' => $this->stringOrNull(value: ($row['name'] ?? null)),
			'category' => ($categories[$category] ?? $this->stringOrNull(value: $category)),
			'unitPrice' => $this->numberOrNull(value: ($row['unitPrice'] ?? null)),
			'currency' => 'EUR',
			'taxRate' => $this->numberOrNull(value: ($row['taxRate'] ?? null)),
			'unitCode' => $this->stringOrNull(value: ($row['unit'] ?? null)),
			'primaryBarcode' => $this->stringOrNull(value: ($row['barcode'] ?? null)),
			'status' => $this->stringOrNull(value: ($row['status'] ?? null)),
			'stockLocations' => 0,
			'quantityOnHand' => null,
			'unitCost' => $this->numberOrNull(value: ($row['cost'] ?? null)),
			'source' => self::SOURCE_MASTER,
		];

	}//end mapMasterProduct()

	/**
	 * Resolve pipelinq category uuids to their names.
	 *
	 * @return array<string,string> Uuid => category name. Empty when unreachable.
	 */
	private function categoryNames(): array {
		$names = [];
		foreach ($this->query(register: self::MASTER_REGISTER, schema: self::MASTER_SCHEMA_CATEGORY, filters: []) as $row) {
			$identifier = $this->identifierOf(row: $row);
			$name = $this->stringOrNull(value: ($row['name'] ?? null));
			if ($identifier !== null && $name !== null) {
				$names[$identifier] = $name;
			}
		}

		return $names;

	}//end categoryNames()

	/**
	 * Project the products this administration's own inventory rows reference.
	 *
	 * This is the contract's declared `localCache` fallback. Identity fields
	 * (`name`, `category`, `unitPrice`) are deliberately left null rather than
	 * invented: shillinq does not hold them any more, and filling them with a
	 * placeholder would make an unowned field look owned.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 *
	 * @return list<array<string,mixed>> One row per distinct referenced productId.
	 */
	private function projectLocalReferences(array $administrationIds): array {
		$byProduct = [];

		foreach ($administrationIds as $administrationId) {
			$stockRows = $this->query(
				register: $this->register(),
				schema: self::LOCAL_SCHEMA_STOCK,
				filters: ['administrationId' => $administrationId]
			);

			foreach ($stockRows as $stock) {
				$productId = $this->stringOrNull(value: ($stock['productId'] ?? null));
				if ($productId === null) {
					continue;
				}

				$entry = ($byProduct[$productId] ?? $this->emptyLocalRow(productId: $productId));
				$entry['sku'] = ($entry['sku'] ?? $this->stringOrNull(value: ($stock['productSku'] ?? null)));
				$entry['status'] = ($entry['status'] ?? $this->stringOrNull(value: ($stock['status'] ?? null)));
				$entry['stockLocations'] = ((int)$entry['stockLocations'] + 1);
				$entry['quantityOnHand'] = ((float)($entry['quantityOnHand'] ?? 0.0) + (float)($stock['quantityOnHand'] ?? 0.0));

				$unitCost = $this->numberOrNull(value: ($stock['unitCost'] ?? null));
				if ($unitCost !== null) {
					$entry['unitCost'] = $unitCost;
				}

				$byProduct[$productId] = $entry;
			}
		}

		// The `Barcode` query carries NO `administrationId` filter, and that is
		// not an omission: `20-inventory-barcode-sku.json` does not declare the
		// property, so there is nothing to filter on — a filter naming it would
		// address a non-property and match nothing for every value, silently.
		// Barcodes are already an unscoped register in this app (the
		// `/inventory/barcodes` index lists them the same way), so this adds no
		// exposure; it does mean a product may appear here with a barcode and
		// no stock. If `Barcode` ever gains an `administrationId`, this call
		// must gain the filter with it.
		foreach ($this->query(register: $this->register(), schema: self::LOCAL_SCHEMA_BARCODE, filters: []) as $barcode) {
			$productId = $this->stringOrNull(value: ($barcode['productId'] ?? null));
			if ($productId === null) {
				continue;
			}

			$entry = ($byProduct[$productId] ?? $this->emptyLocalRow(productId: $productId));
			$entry['sku'] = ($entry['sku'] ?? $this->stringOrNull(value: ($barcode['productSku'] ?? null)));
			if ($entry['primaryBarcode'] === null || ($barcode['isDefault'] ?? false) === true) {
				$entry['primaryBarcode'] = $this->stringOrNull(value: ($barcode['barcode'] ?? null));
			}

			$byProduct[$productId] = $entry;
		}

		$rows = array_values($byProduct);
		usort(
			$rows,
			static function (array $left, array $right): int {
				return strcmp((string)$left['productId'], (string)$right['productId']);
			}
		);

		return $rows;

	}//end projectLocalReferences()

	/**
	 * The shape of a locally-projected catalog row before any field is filled.
	 *
	 * @param string $productId The pipelinq product identifier this row is keyed by.
	 *
	 * @return array<string,mixed> The zero row.
	 */
	private function emptyLocalRow(string $productId): array {
		return [
			'productId' => $productId,
			'sku' => null,
			'name' => null,
			'category' => null,
			'unitPrice' => null,
			'currency' => null,
			'taxRate' => null,
			'unitCode' => null,
			'primaryBarcode' => null,
			'status' => null,
			'stockLocations' => 0,
			'quantityOnHand' => 0.0,
			'unitCost' => null,
			'source' => self::SOURCE_LOCAL_CACHE,
		];

	}//end emptyLocalRow()

	/**
	 * Derive attribute definitions from the attribute names the master's products carry.
	 *
	 * @param list<array<string,mixed>> $rows The pipelinq product-master rows.
	 *
	 * @return list<array<string,mixed>> One definition per distinct attribute name.
	 */
	private function attributesFromMaster(array $rows): array {
		$seen = [];
		foreach ($rows as $row) {
			foreach ($this->attributeNamesOf(row: $row) as $name => $dataType) {
				if (isset($seen[$name]) === false) {
					$seen[$name] = $dataType;
				}
			}
		}

		ksort($seen);

		$definitions = [];
		$order = 10;
		foreach ($seen as $name => $dataType) {
			$definitions[] = [
				'name' => $name,
				'dataType' => $dataType,
				'applicableToCategories' => 'all',
				'isRequired' => false,
				'displayOrder' => $order,
				'validationRule' => null,
				'status' => 'active',
				'ownedBy' => self::MASTER_REGISTER,
				'source' => self::SOURCE_MASTER,
			];
			$order = ($order + 10);
		}

		return $definitions;

	}//end attributesFromMaster()

	/**
	 * Collect the attribute names one master product declares.
	 *
	 * Pipelinq models per-product attributes on `variants[]` (a size × colour
	 * style matrix, each entry carrying its own attribute map) and on
	 * `modifierGroups[]` (configurable add-ons prompted at checkout). Those are
	 * the only two places an attribute NAME appears, so they are the only two
	 * read here.
	 *
	 * @param array<string,mixed> $row One master product.
	 *
	 * @return array<string,string> Attribute name => dataType.
	 */
	private function attributeNamesOf(array $row): array {
		$names = [];

		$variants = ($row['variants'] ?? []);
		if (is_array($variants) === true) {
			foreach ($variants as $variant) {
				if (is_array($variant) === false) {
					continue;
				}

				$attributes = ($variant['attributes'] ?? []);
				if (is_array($attributes) === false) {
					continue;
				}

				foreach (array_keys($attributes) as $name) {
					$names[(string)$name] = 'text';
				}
			}
		}

		$groups = ($row['modifierGroups'] ?? []);
		if (is_array($groups) === true) {
			foreach ($groups as $group) {
				if (is_array($group) === false) {
					continue;
				}

				$name = $this->stringOrNull(value: ($group['name'] ?? null));
				if ($name !== null) {
					$names[$name] = 'enum';
				}
			}
		}

		return $names;

	}//end attributeNamesOf()

	/**
	 * The attribute definitions the ADR-019 contract itself publishes.
	 *
	 * Used when the master is unreachable, or reachable but carrying no
	 * per-product attribute names yet. Every entry is real: each is a field the
	 * `getProduct` resolver declares, or a field shillinq holds locally, and
	 * `ownedBy` says which. Rendering nothing here would hide the fact that the
	 * catalog HAS a defined attribute surface and only its values are remote.
	 *
	 * @param boolean $masterAvailable Whether the pipelinq master answered.
	 *
	 * @return list<array<string,mixed>> The contract's attribute definitions.
	 */
	private function contractAttributes(bool $masterAvailable): array {
		$remote = self::SOURCE_CONTRACT;
		if ($masterAvailable === true) {
			$remote = self::SOURCE_MASTER;
		}

		$local = self::SOURCE_LOCAL_CACHE;
		$master = self::MASTER_REGISTER;
		$app = Application::APP_ID;

		$rows = [
			['sku', 'text', 'Stock keeping unit resolved from the product master.', $master, $remote],
			['name', 'text', 'Human-readable product name resolved from the product master.', $master, $remote],
			['category', 'text', 'Product category resolved from the product master.', $master, $remote],
			['unitPrice', 'number', 'Selling price per unit resolved from the product master.', $master, $remote],
			['taxRate', 'number', 'VAT percentage resolved from the product master.', $master, $remote],
			['unitCode', 'text', 'UN/CEFACT unit of measure resolved from the product master.', $master, $remote],
			['primaryBarcode', 'text', 'Default scanned barcode. Held locally on Barcode, mirrored from the master.', $app, $local],
			['unitCost', 'number', 'Valuation unit cost. Authored and owned by shillinq (REQ-SPVP-001).', $app, $local],
			['quantityOnHand', 'number', 'Physical on-hand quantity. Authored and owned by shillinq.', $app, $local],
			['status', 'enum', 'active | discontinued. Mirrored from the master onto local stock rows.', $master, $remote],
		];

		$definitions = [];
		$order = 10;
		foreach ($rows as [$name, $dataType, $rule, $owner, $source]) {
			$definitions[] = [
				'name' => $name,
				'dataType' => $dataType,
				'applicableToCategories' => 'all',
				'isRequired' => in_array($name, ['sku', 'name'], true),
				'displayOrder' => $order,
				'validationRule' => $rule,
				'status' => 'active',
				'ownedBy' => $owner,
				'source' => $source,
			];
			$order = ($order + 10);
		}

		return $definitions;

	}//end contractAttributes()

	/**
	 * Read an object's identifier from the serialised row.
	 *
	 * OpenRegister merges `id`/`uuid` into the serialised output after the
	 * property bag, so both are read here rather than filtered on — a filter on
	 * `id` matches nothing.
	 *
	 * @param array<string,mixed> $row The serialised object.
	 *
	 * @return string|null The identifier, or null when the row carries none.
	 */
	private function identifierOf(array $row): ?string {
		foreach (['id', 'uuid'] as $key) {
			$value = $this->stringOrNull(value: ($row[$key] ?? null));
			if ($value !== null) {
				return $value;
			}
		}

		return null;

	}//end identifierOf()

	/**
	 * Normalise a scalar to a non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed string, or null when absent/blank.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false && is_numeric($value) === false) {
			return null;
		}

		$string = trim((string)$value);
		if ($string === '') {
			return null;
		}

		return $string;

	}//end stringOrNull()

	/**
	 * Normalise a scalar to a float, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return float|null The number, or null when absent/non-numeric.
	 */
	private function numberOrNull(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;

	}//end numberOrNull()

	/**
	 * Run one query against a register/schema pair.
	 *
	 * A failure is logged at DEBUG and answered as an empty result set: the
	 * pipelinq register is legitimately absent on most installs, and an install
	 * without it must fall through to the local cache rather than 500.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $register, string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ProductCatalogService: register/schema not readable',
				['register' => $register, 'schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;

	}//end query()

	/**
	 * Resolve shillinq's own OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
