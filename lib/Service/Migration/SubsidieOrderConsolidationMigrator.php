<?php

/**
 * Subsidie + Order consolidation migrator
 *
 * The unit-tested migration core for consolidate-order-subsidie-collisions. The
 * schema-level consolidation (one canonical `Subsidie` = the field union; the
 * booking-context `Order` renamed to `BookingOrder` so the generic `Order` slug
 * is free for the abstract Order primitive) is done non-destructively in the
 * register JSON. This class carries the OBJECT-level migration decision logic:
 *
 *  1. The field-map that re-points a persisted object of a renamed schema to its
 *     new schema slug (`mapObjectToRenamedSchema`), pure and byte-safe.
 *  2. The source→target count guard (`assertCountsMatch`) that ABORTS — leaving
 *     the source data intact — the moment a migrated count does not equal the
 *     source count, so a migration can never silently drop rows.
 *
 * ## Subsidie needs no object migration
 *
 * OpenRegister's ImportHandler imports schemas ONLY from `components.schemas`
 * (never the legacy sibling `components.<Name>` blocks). All three historical
 * `Subsidie` definitions — the rich-Dutch `components.schemas.Subsidie`, the
 * dead `components.Subsidie` sibling, and the English
 * `add-shillinq-bookkeeping-operations.json` fragment — therefore resolved to
 * ONE live schema slug (`Subsidie`), i.e. one table. Consolidating the
 * DEFINITIONS (union of fields, duplicates retired) leaves the single live
 * `Subsidie` slug untouched, so there are no orphaned duplicate-schema objects
 * to move. {@see subsidieMigrationRequired()} returns false for this reason.
 * (The separate downstream fold of Subsidie onto Grant/Order is owned by the
 * `abstract-order-primitive` change's FoldIntoOrder / RetireSubsidieSchema
 * repair steps — out of scope here.)
 *
 * ## Order → BookingOrder is a rename
 *
 * Only the booking-context order schema is renamed (`Order` → `BookingOrder`),
 * so any persisted object under slug `Order` must be re-pointed to
 * `BookingOrder`. That is a NON-regulatory (booking) migration; the count guard
 * protects it. {@see bookingOrderRename()} defines the from/to pair.
 *
 * ## Deviation (live register migration deferred)
 *
 * The LIVE re-point of persisted `Order` objects to `BookingOrder` against a
 * running OpenRegister — and its interaction with the already-registered
 * FoldIntoOrder / RetireSubsidieSchema repair steps — is verified on a live
 * import (the spec marks the full register migration `@e2e exclude … verified
 * on a live import`). This class is the buildable, unit-tested half; wiring the
 * live re-point as its own repair step is deferred so it cannot run untested
 * against — and never risk — the regulatory Subsidie data those sibling steps
 * also touch.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/schema-consolidation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Migration;

use RuntimeException;

/**
 * Pure, unit-testable migration core for the Subsidie/Order consolidation.
 */
final class SubsidieOrderConsolidationMigrator {

	/**
	 * The renamed booking-order schema slug (source).
	 */
	public const BOOKING_ORDER_FROM = 'Order';

	/**
	 * The renamed booking-order schema slug (target).
	 */
	public const BOOKING_ORDER_TO = 'BookingOrder';

	/**
	 * The from/to slug pair for the booking-order rename.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @spec openspec/specs/schema-consolidation/spec.md
	 */
	public function bookingOrderRename(): array {
		return ['from' => self::BOOKING_ORDER_FROM, 'to' => self::BOOKING_ORDER_TO];
	}//end bookingOrderRename()

	/**
	 * Whether the Subsidie consolidation requires an object migration.
	 *
	 * Always false: all historical Subsidie definitions resolved to one live
	 * schema slug (OR imports only `components.schemas`), so consolidating the
	 * definitions orphans no objects. See the class docblock.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/schema-consolidation/spec.md
	 */
	public function subsidieMigrationRequired(): bool {
		return false;
	}//end subsidieMigrationRequired()

	/**
	 * Re-point a persisted object of a renamed schema to its new slug.
	 *
	 * Pure and byte-safe: only the object's `@self.schema` pointer is rewritten
	 * when it matches `$from`; every other field is preserved verbatim (no
	 * regulatory field is touched). An object not under `$from` is returned
	 * unchanged.
	 *
	 * @param array<string, mixed> $object The persisted object (with an `@self` envelope).
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<string, mixed> The migrated (or unchanged) object.
	 *
	 * @spec openspec/specs/schema-consolidation/spec.md
	 */
	public function mapObjectToRenamedSchema(array $object, string $from, string $to): array {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && ($self['schema'] ?? null) === $from) {
			$self['schema'] = $to;
			$object['@self'] = $self;
		}

		return $object;
	}//end mapObjectToRenamedSchema()

	/**
	 * Migrate a batch of source objects to the renamed schema, guarded by count.
	 *
	 * Every source object is re-pointed; the migrated count MUST equal the source
	 * count or the migration ABORTS (the source rows are the caller's — this
	 * method never deletes them, so an abort leaves the source intact).
	 *
	 * @param array<int, array<string, mixed>> $sourceObjects The objects under the source slug.
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<int, array<string, mixed>> The migrated objects.
	 *
	 * @throws RuntimeException When the migrated count does not match the source count.
	 *
	 * @spec openspec/specs/schema-consolidation/spec.md
	 */
	public function migrateBatch(array $sourceObjects, string $from, string $to): array {
		$migrated = [];
		foreach ($sourceObjects as $object) {
			$migrated[] = $this->mapObjectToRenamedSchema(object: $object, from: $from, to: $to);
		}

		$this->assertCountsMatch(sourceCount: count($sourceObjects), migratedCount: count($migrated));

		return $migrated;
	}//end migrateBatch()

	/**
	 * Assert a source→target object count match, aborting on mismatch.
	 *
	 * @param int $sourceCount The number of source objects.
	 * @param int $migratedCount The number of migrated objects.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the counts differ (no-row-loss guard).
	 *
	 * @spec openspec/specs/schema-consolidation/spec.md
	 */
	public function assertCountsMatch(int $sourceCount, int $migratedCount): void {
		if ($sourceCount !== $migratedCount) {
			throw new RuntimeException(
				sprintf(
					'Migration aborted: source count %d does not match migrated count %d; source data left intact.',
					$sourceCount,
					$migratedCount
				)
			);
		}

	}//end assertCountsMatch()
}//end class
