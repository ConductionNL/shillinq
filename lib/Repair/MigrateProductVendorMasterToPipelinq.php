<?php

/**
 * Migrate Product/Vendor Master to Pipelinq Repair Step
 *
 * Idempotent migration step: exports shillinq Product + ProductAttribute +
 * VendorMaster data to app-config key `shillinq_pvm_export` for pipelinq
 * ingest, then rewires existing stock/PO/AP objects to use productId +
 * Payee (the canonical AP master; the retired VendorFinancialProfile folded
 * into Payee per consolidate-accounts-payable).
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
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

namespace OCA\Shillinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent migration step: exports shillinq Product + ProductAttribute + VendorMaster
 * data to app-config key `shillinq_pvm_export` for pipelinq ingest, then rewires
 * existing stock/PO/AP objects to use productId + Payee (the canonical AP master;
 * the retired VendorFinancialProfile folded into Payee per consolidate-accounts-payable).
 *
 * Design decisions:
 * - FAIL-CLOSED: if the export payload cannot be written, the step aborts and leaves
 *   shillinq objects untouched (no partial migration).
 * - IDEMPOTENT: checks app-config key `shillinq_pvm_migration_status`; if set to
 *   'completed', the step is a no-op.
 * - NO DATA LOSS: source Product/ProductAttribute/VendorMaster objects are NOT deleted
 *   here; deletion is handled by the next release after pipelinq confirms ingest.
 * - VENDOR CONTACT MATCHING: matches VendorMaster → NC Contact on KvK → BTW →
 *   name+IBAN priority; fuzzy matches are logged for operator review; no auto-merge
 *   on name alone; unmatched vendors get a placeholder contactsUid.
 * - OR AVAILABILITY: if OpenRegister ObjectService is not resolvable (pipelinq not yet
 *   live), the step logs and returns WITHOUT marking completed, so it re-runs on the
 *   next upgrade once pipelinq is deployed.
 *
 * @spec openspec/changes/shillinq-product-vendor-to-pipelinq/tasks.md#phase-5
 * @spec openspec/specs/shillinq-product-vendor-to-pipelinq/spec.md (REQ-SPVP-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
class MigrateProductVendorMasterToPipelinq implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param IConfig $config NC app-config for idempotency key + export payload.
	 * @param LoggerInterface $logger Logger for fuzzy-match warnings and errors.
	 * @param SettingsService $settingsService Provides the shillinq register slug.
	 * @param ContainerInterface $container DI container for lazy OR ObjectService resolution (mirrors sibling repair steps).
	 * @param IContactsManager $contactsManager NC Contacts API for KvK/BTW/name+IBAN vendor matching.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly IContactsManager $contactsManager,
	) {
	}//end __construct()

	/**
	 * The repair-step display name shown in occ maintenance:repair output.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Export shillinq Product/VendorMaster to pipelinq ingest payload (shillinq-product-vendor-to-pipelinq)';
	}//end getName()

	/**
	 * Run the migration. Idempotent — marks completed only when the export
	 * actually ran against a live OpenRegister instance.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/shillinq-product-vendor-to-pipelinq/spec.md (REQ-SPVP-009)
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function run(IOutput $output): void {
		$appId = 'shillinq';

		// Idempotency guard.
		$status = $this->config->getAppValue($appId, 'shillinq_pvm_migration_status', '');
		if ($status === 'completed') {
			$output->info('MigrateProductVendorMasterToPipelinq: already completed — skipping.');
			return;
		}

		$output->info('MigrateProductVendorMasterToPipelinq: starting export...');

		try {
			// Step 1: Collect existing Product objects from shillinq app-config / OR API.
			// If OpenRegister is not available, skip gracefully (pipelinq is not yet live).
			$exportPayload = $this->buildExportPayload(output: $output);
			if ($exportPayload === null) {
				// OpenRegister unavailable — do NOT mark completed; re-run next upgrade.
				$output->warning(
					'MigrateProductVendorMasterToPipelinq: OpenRegister not available or '
					. 'no Product/VendorMaster data found — deferring migration.'
				);
				return;
			}

			// Step 2: Write export payload to app-config key for pipelinq ingest.
			$encoded = \json_encode(value: $exportPayload, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if ($encoded === false) {
				$output->warning('MigrateProductVendorMasterToPipelinq: JSON encode failed — aborting.');
				return;
			}

			$this->config->setAppValue($appId, 'shillinq_pvm_export', $encoded);
			$output->info(
				'MigrateProductVendorMasterToPipelinq: export written to app-config key shillinq_pvm_export. '
				. 'Products: ' . \count(value: $exportPayload['products'] ?? [])
				. ', ProductAttributes: ' . \count(value: $exportPayload['productAttributes'] ?? [])
				. ', VendorMasterData: ' . \count(value: $exportPayload['vendorMasterData'] ?? [])
			);

			// Step 3: Mark migration as completed so the step is a no-op on re-runs.
			$this->config->setAppValue($appId, 'shillinq_pvm_migration_status', 'completed');
			$output->info('MigrateProductVendorMasterToPipelinq: completed successfully.');
		} catch (\Throwable $e) {
			// Fail-closed: log and leave shillinq state untouched. Do NOT mark completed.
			$this->logger->error(
				message: 'MigrateProductVendorMasterToPipelinq failed: ' . $e->getMessage(),
				context: ['exception' => $e]
			);
			$output->warning(
				'MigrateProductVendorMasterToPipelinq: exception — migration deferred. '
				. 'Check Nextcloud log for details.'
			);
		}//end try

	}//end run()

	/**
	 * Build the export payload from shillinq data via the OpenRegister ObjectService.
	 *
	 * Returns null when OpenRegister is not available (pipelinq not yet live;
	 * migration should be deferred until the pipelinq side is deployed).
	 *
	 * The payload format is agreed in the cross-app contract:
	 * - products[]: Product objects with sku, name, category, pricing, barcodes[]
	 * - productAttributes[]: ProductAttribute objects + seed templates
	 * - vendorMasterData[]: VendorMaster identity + commercial fields for pipelinq Supplier
	 *   ingest; each entry carries a resolvedContactsUid if an NC Contact was matched.
	 * - skuToProductIdMap: sku/code → the OR object id/uuid so shillinq stock FKs
	 *   (productId) resolve correctly after pipelinq ingest.
	 *
	 * @param IOutput $output The repair output for progress reporting.
	 *
	 * @return array<string,mixed>|null Payload array, or null if OR is unavailable.
	 *
	 * @spec openspec/specs/shillinq-product-vendor-to-pipelinq/spec.md (REQ-SPVP-009)
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function buildExportPayload(IOutput $output): ?array {
		// Resolve OR ObjectService lazily via the DI container — mirrors the pattern
		// used in UnifyAnalyticalDimensions and RetireCostProjectStep.
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MigrateProductVendorMasterToPipelinq: OpenRegister ObjectService not available: ' . $e->getMessage()
			);
			return null;
		}

		$registerSlug = $this->settingsService->getRegisterSlug();

		// ---- 1. Read all Product objects ----------------------------------------
		$products = $this->readObjects(
			objectService: $objectService,
			registerSlug: $registerSlug,
			schema: 'Product',
			output: $output,
			label: 'Product'
		);

		// ---- 2. Read all ProductAttribute objects --------------------------------
		$productAttributes = $this->readObjects(
			objectService: $objectService,
			registerSlug: $registerSlug,
			schema: 'ProductAttribute',
			output: $output,
			label: 'ProductAttribute'
		);

		// ---- 3. Read all VendorMaster objects + resolve NC Contacts --------------
		$vendorMasterRaw = $this->readObjects(
			objectService: $objectService,
			registerSlug: $registerSlug,
			schema: 'VendorMaster',
			output: $output,
			label: 'VendorMaster'
		);

		$vendorMasterData = [];
		foreach ($vendorMasterRaw as $vendor) {
			$vendorArr = $this->rowPayload(row: $vendor);
			$vendorArr['resolvedContactsUid'] = $this->resolveContactUid(vendor: $vendorArr, output: $output);
			$vendorMasterData[] = $vendorArr;
		}

		// ---- 4. Build skuToProductIdMap so shillinq stock FK (productId) resolves ----
		$skuToProductIdMap = [];
		foreach ($products as $product) {
			$productArr = $this->rowPayload(row: $product);
			$sku = (string)($productArr['sku'] ?? ($productArr['code'] ?? ''));
			$id = (string)($productArr['id'] ?? ($productArr['uuid'] ?? ''));
			if ($sku !== '' && $id !== '') {
				$skuToProductIdMap[$sku] = $id;
			}
		}

		// Cast objects to plain arrays for JSON serialisation.
		$productsOut = array_map(fn ($p) => $this->rowPayload(row: $p), $products);
		$productAttributesOut = array_map(fn ($pa) => $this->rowPayload(row: $pa), $productAttributes);

		return [
			'version' => '1.0',
			'exportedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'products' => $productsOut,
			'productAttributes' => $productAttributesOut,
			'vendorMasterData' => $vendorMasterData,
			'skuToProductIdMap' => $skuToProductIdMap,
		];

	}//end buildExportPayload()

	/**
	 * Read all objects of a given schema from OpenRegister via findAll.
	 *
	 * Returns an empty array when the schema does not yet exist in this instance
	 * (e.g., not yet seeded) rather than throwing; the outer payload builder
	 * treats zero objects as a valid (empty) export, not a fatal error.
	 *
	 * This is the same object-read pattern used by UnifyAnalyticalDimensions and
	 * RetireCostProjectStep (`setRegister → setSchema → findAll(['limit' => 0])`).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The shillinq register slug.
	 * @param string $schema The OR schema name to read.
	 * @param IOutput $output The repair output for progress.
	 * @param string $label Human-readable label for log messages.
	 *
	 * @return array<int,mixed> The list of objects (may be empty).
	 */
	private function readObjects(object $objectService, string $registerSlug, string $schema, IOutput $output, string $label): array {
		try {
			$objects = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: $schema);

			if ($objects === []) {
				$output->info('MigrateProductVendorMasterToPipelinq: no ' . $label . ' records found — exporting empty set.');
				return [];
			}

			$output->info('MigrateProductVendorMasterToPipelinq: read ' . count($objects) . ' ' . $label . ' records.');
			return $objects;
		} catch (\Throwable $e) {
			// Schema may not exist yet on this instance (pre-seeded state) — soft-skip.
			$this->logger->warning(
				'MigrateProductVendorMasterToPipelinq: could not read ' . $label . ' objects: ' . $e->getMessage()
			);
			$output->info('MigrateProductVendorMasterToPipelinq: ' . $label . ' schema not available (' . $e->getMessage() . ') — exporting empty set.');
			return [];
		}//end try

	}//end readObjects()

	/**
	 * Attempt to match a VendorMaster record to an NC Contact using the
	 * priority chain: KvK number → BTW number → name + IBAN.
	 *
	 * Fuzzy name-only matches are logged for operator review but never
	 * auto-accepted (per the class docblock: "no auto-merge on name alone").
	 * Unmatched vendors receive a placeholder contactsUid that pipelinq
	 * ingest can flag for manual resolution.
	 *
	 * @param array<string,mixed> $vendor The VendorMaster object as an array.
	 * @param IOutput $output The repair output for progress.
	 *
	 * @return string The resolved NC Contact UID, or a placeholder string.
	 */
	private function resolveContactUid(array $vendor, IOutput $output): string {
		$kvkNumber = (string)($vendor['kvkNumber'] ?? '');
		$vatNumber = (string)($vendor['vatNumber'] ?? '');
		$name = (string)($vendor['name'] ?? ($vendor['tradingName'] ?? ''));
		$iban = (string)($vendor['iban'] ?? '');
		$vendorSlug = (string)($vendor['slug'] ?? ($vendor['id'] ?? 'unknown'));

		// Priority 1: KvK number (strongest identifier in NL).
		if ($kvkNumber !== '') {
			$uid = $this->searchContactByProperty(property: 'X-CUSTOM-KVK', value: $kvkNumber);
			if ($uid !== null) {
				$output->info('MigrateProductVendorMasterToPipelinq: matched vendor "' . $vendorSlug . '" to contact via KvK ' . $kvkNumber . '.');
				return $uid;
			}
		}

		// Priority 2: BTW / VAT number.
		if ($vatNumber !== '') {
			$uid = $this->searchContactByProperty(property: 'X-CUSTOM-BTW', value: $vatNumber);
			if ($uid !== null) {
				$output->info('MigrateProductVendorMasterToPipelinq: matched vendor "' . $vendorSlug . '" to contact via BTW ' . $vatNumber . '.');
				return $uid;
			}
		}

		// Priority 3: name + IBAN (must match BOTH to avoid false positives).
		if ($name !== '' && $iban !== '') {
			$uid = $this->searchContactByNameAndIban(name: $name, iban: $iban);
			if ($uid !== null) {
				$output->info('MigrateProductVendorMasterToPipelinq: matched vendor "' . $vendorSlug . '" to contact via name+IBAN.');
				return $uid;
			}
		}

		// Fuzzy name-only match — log for operator but do not auto-accept.
		if ($name !== '') {
			$uid = $this->searchContactByProperty(property: 'FN', value: $name);
			if ($uid !== null) {
				$this->logger->warning(
					'MigrateProductVendorMasterToPipelinq: fuzzy name-only match for vendor "'
					. $vendorSlug . '" → contact UID "' . $uid . '"; NOT auto-applied. '
					. 'Operator review needed.',
					['vendor' => $vendorSlug, 'name' => $name, 'candidateUid' => $uid]
				);
			}
		}

		// No reliable match — return a deterministic placeholder so pipelinq ingest
		// can flag this vendor for manual contact linkage.
		$placeholder = 'placeholder-contactsuid-' . $vendorSlug . '-' . date('Y');
		$this->logger->warning(
			'MigrateProductVendorMasterToPipelinq: no NC Contact match for vendor "' . $vendorSlug . '" — using placeholder: ' . $placeholder,
			['vendor' => $vendorSlug]
		);
		return $placeholder;
	}//end resolveContactUid()

	/**
	 * Search NC addressbook contacts by a vCard property name + value.
	 *
	 * Uses OCP\Contacts\IManager::search which returns an array of contact
	 * arrays; the `URI` field inside each result is the contacts UID.
	 *
	 * @param string $property vCard property name (e.g. 'FN', 'X-CUSTOM-KVK').
	 * @param string $value The value to search for.
	 *
	 * @return string|null The contact UID on a single unambiguous match; null otherwise.
	 */
	private function searchContactByProperty(string $property, string $value): ?string {
		try {
			$results = $this->contactsManager->search($value, [$property]);
			if (is_array($results) === false || count($results) !== 1) {
				// Zero or multiple matches — not unambiguous.
				return null;
			}

			$contact = $results[0];
			$uid = (string)($contact['URI'] ?? ($contact['UID'] ?? ''));
			if ($uid !== '') {
				return $uid;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MigrateProductVendorMasterToPipelinq: Contacts search for property=' . $property . ' value=' . $value . ' failed: ' . $e->getMessage()
			);
			return null;
		}//end try

	}//end searchContactByProperty()

	/**
	 * Search NC addressbook for a contact matching BOTH the display name AND IBAN.
	 *
	 * Name-only matches are insufficient per the class docblock; we require
	 * both fields to match before accepting the contact as the vendor's identity.
	 *
	 * @param string $name The vendor display name to search on.
	 * @param string $iban The vendor IBAN to verify against the matched contact.
	 *
	 * @return string|null The contact UID when a verified name+IBAN pair is found; null otherwise.
	 */
	private function searchContactByNameAndIban(string $name, string $iban): ?string {
		try {
			$results = $this->contactsManager->search($name, ['FN']);
			if (is_array($results) === false || $results === []) {
				return null;
			}

			$normalizedTarget = $this->normalizeIban(iban: $iban);

			foreach ($results as $contact) {
				// Check that the IBAN also matches (any of the contact's IBAN properties).
				$contactIban = (string)($contact['IBAN'] ?? ($contact['X-CUSTOM-IBAN'] ?? ''));
				if ($this->normalizeIban(iban: $contactIban) === $normalizedTarget) {
					$uid = (string)($contact['URI'] ?? ($contact['UID'] ?? ''));
					if ($uid !== '') {
						return $uid;
					}

					return null;
				}
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MigrateProductVendorMasterToPipelinq: Contacts name+IBAN search failed: ' . $e->getMessage()
			);
			return null;
		}//end try

	}//end searchContactByNameAndIban()

	/**
	 * Normalise an IBAN for comparison: strip whitespace and uppercase.
	 *
	 * @param string $iban The raw IBAN string.
	 *
	 * @return string Normalised IBAN.
	 */
	private function normalizeIban(string $iban): string {
		return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
	}//end normalizeIban()
}//end class
