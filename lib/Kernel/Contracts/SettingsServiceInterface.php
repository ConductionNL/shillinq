<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * Single source of truth for the register slug — mirrors the dynamic slug
	 * resolution already used by DeepLinkRegistrationListener (L3/C3).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
	 */
	public function getRegisterSlug(): string;

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 */
	public function getSettings(): array;

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 */
	public function isOpenRegisterAvailable(): bool;

	/**
	 * Load configuration from shillinq_register.json via OpenRegister.
	 *
	 * Skips import when the register is already configured (idempotent).
	 * Use {@see self::loadConfigurationForced()} to bypass this guard.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-2
	 */
	public function loadConfiguration(): array;

	/**
	 * Force re-import of configuration from shillinq_register.json via OpenRegister.
	 *
	 * Bypasses the already-configured guard and always re-imports.
	 * Use {@see self::loadConfiguration()} for the idempotent variant.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-2
	 */
	public function loadConfigurationForced(): array;

	/**
	 * Seed example allocation rules from the bundled seed files, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
	 */
	public function seedAllocationRules(string $administrationId): array;

	/**
	 * Seed the default RGS → BBV account mapping for a municipal administration
	 *
	 * @param string $administrationId The administration id.
	 * @param string $administrationType The administration type.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	public function seedBbvAccountMappings(string $administrationId, string $administrationType): array;

	/**
	 * Seed the demo BBVProgramme records from
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBbvProgrammes(string $administrationId): array;

	/**
	 * Seed the BBV task_field catalogue from bbv-task_fields-2024.json, idempotently.
	 *
	 * Imports the canonical Besluit BBV bijlage IV task_fields into the BbvTaakveld
	 * schema. Deduplication key is code. Idempotent on re-run.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function seedBbvTaakvelden(): array;

	/**
	 * Seed statutory BTW tariffs from btw-tariffs-2026.json, idempotently.
	 *
	 * Imports the current Dutch VAT rates into the VatTariff schema. Deduplication
	 * key is code. Idempotent on re-run; operator-added rates are preserved.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function seedBtwTariffs(): array;

	/**
	 * Seed the demo BudgetBBVMapping records from
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBudgetBbvMappings(string $administrationId): array;

	/**
	 * Seed the default Administration on a fresh install, idempotently.
	 *
	 * Single-administratie installs need exactly one valid Administration record so
	 * every financial entity has a valid administrationId FK target (REQ-MA-001).
	 * The seed (lib/Settings/seeds/administraties/default.json) is matched on
	 * administrationCode, so re-runs create no duplicates and operator edits to the
	 * default administration are preserved (REQ-MA-007).
	 *
	 * The result carries `administrationCode` (read from the seed file, not
	 * guessed) alongside `success`/`seeded`/`skipped` so callers — notably
	 * SetupController's `init-administration` action — can persist the real
	 * identifier of the created/existing record as `administration_id`
	 * instead of hardcoding it.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped
	 *                             count, and administrationCode.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function seedDefaultAdministration(): array;

	/**
	 * Seed FixedAsset + DepreciationSchedule demo records, idempotently (REQ-FA-001..010).
	 *
	 * Reads `lib/Settings/seeds/fixed-assets-demo.json` and imports four
	 * realistic Dutch SMB scenarios (company vehicle, office building,
	 * computer equipment, retired asset) together with their 2026
	 * DepreciationSchedule records. Each FixedAsset carries both the
	 * canonical fields (assetCategory / usefulLifeMonths / lifecycleState)
	 * and the REQ-FA-002 operator-facing aliases (assetType / usefulLifeYears
	 * / status). Deduplication key for both schemas is `assetNumber` /
	 * `scheduleNumber` + `administrationId`; idempotent on re-run.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	public function seedFixedAssetsDemo(string $administrationId): array;

	/**
	 * Seed demo Barcode records from inventory-barcodes-demo.json, idempotently.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
	 */
	public function seedInventoryBarcodes(): array;

	/**
	 * Seed the default InventoryGLConfig for an administration, idempotently.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
	 */
	public function seedInventoryGLConfig(string $administrationId): array;

	/**
	 * Seed demo InventoryLot records from inventory-lots-demo.json, idempotently.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
	 */
	public function seedInventoryLots(): array;

	/**
	 * Seed the demo InventoryStock records, idempotently per administration.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function seedInventoryStockExamples(string $administrationId): array;

	/**
	 * Seed the demo InventoryValuation records, idempotently per administration.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
	 */
	public function seedInventoryValuationExamples(string $administrationId): array;

	/**
	 * Seed mandaat (signing-authority) templates from mandaat-templates.json, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.7
	 */
	public function seedMandateTemplates(string $administrationId): array;

	/**
	 * Seed default rate-card templates from rate-card-templates.json, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
	 */
	public function seedRateCardTemplates(string $administrationId): array;

	/**
	 * Seed the default Archiefwet retention policies, idempotently.
	 *

	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-archiefwet-retention/spec.md (Task 13, REQ-RET-012)
	 */
	public function seedRetentionPolicies(): array;

	/**
	 * Seed the chart of accounts from an RGS template file, idempotently.
	 *
	 * Reads the selected template from lib/Settings/seeds/ and imports Account
	 * records via OpenRegister's ObjectService. Already-existing records (matched
	 * by accountNumber + administrationId) are skipped, preserving operator edits.
	 *
	 * @param string $templateVariant Template to seed: 'mkb', 'zzp', or 'bbv'.
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *                                 Must be a non-empty tenant-specific identifier;
	 *                                 callers must always supply an explicit value (C2).
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/spec/tasks.md#task-11
	 */
	public function seedRgsTemplate(string $templateVariant, string $administrationId): array;

	/**
	 * Seed RJ-270 stages from the rj-270-stages.json seed file, idempotently.
	 *
	 * Imports the 4 canonical percentage-of-completion stage definitions.
	 * Deduplication key is stageId. Idempotent on re-run.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
	 */
	public function seedRj270Stages(): array;

	/**
	 * Seed retention rules from the Selectielijst Gemeenten 2020 seed file, idempotently.
	 *

	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	public function seedSelectielijst(): array;

	/**
	 * Import the three RJ 270 statement presentation manifests, idempotently.
	 *
	 * Reads rj270-balance-sheet.json, rj270-pl.json, and rj270-cash-flow.json from
	 * lib/Settings/statements/ and persists each into app config under a per-statement
	 * key (statement_manifest_<type>) only when the key is not already set. This makes
	 * the import idempotent and preserves operator edits across repair re-runs per
	 * REQ-FS-002: a manifest that the operator has customised is never re-overwritten.
	 *
	 * Financial-statement assembly itself is declarative (trial-balance aggregations
	 * composed against these manifests per ADR-031); this method ships only the
	 * presentation templates, no report-builder logic.
	 *
	 * @return array<string,mixed> Result with success flag, imported count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
	 */
	public function seedStatementManifests(): array;

	/**
	 * Seed the WMO (Wet Markt en Overheid) example records, idempotently per administration.
	 *
	 * Reads the four JSON files under `lib/Settings/seeds/commercial-activities/`:
	 *
	 *   - sportaccommodaties-municipality.json      → CommercialActivity records (state=paused)
	 *   - waterschap-slibruimte.json            → CommercialActivity records (state=paused)
	 *   - abb-example-municipality.json             → AlgemeenBelangBesluit records (status=concept)
	 *   - integral-cost-price-example-q1-2026.json → IntegralCostPrice records (status=voorlopig)
	 *
	 * Each record is stamped with the configured administrationId and deduped on its
	 * natural key (`code` for activities, `reference` for ABBs, `(commercialActivityId,period)`
	 * for IKPs) so re-runs preserve operator edits per REQ-WMO-001 / REQ-WMO-002 /
	 * REQ-WMO-005. All seeded records ship in paused / concept / voorlopig lifecycle
	 * states; operators promote them through the workflow when adopting the seed.
	 *
	 * Skipped when administrationId is empty (C2 — prevents "default" contamination
	 * of real tenant data).
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag and per-schema counts.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-18
	 */
	public function seedWmoCommercialActivities(string $administrationId): array;

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @throws InvalidArgumentException When the portal return address is not an absolute https address.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-010)
	 */
	public function updateSettings(array $data): array;
}//end interface
