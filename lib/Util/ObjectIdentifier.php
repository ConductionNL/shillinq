<?php

/**
 * Object Identifier
 *
 * Resolves the OpenRegister identifier from whatever `ObjectService::saveObject()`
 * or `findAll()` handed back, in the ONE identifier space the rest of this app
 * joins on: the UUID.
 *
 * Extracted from CycleCountService so the same logic can serve every site in the
 * `method_exists` sweep (shillinq#526, #527) instead of being re-derived — and
 * re-derived wrongly — per call site.
 *
 * ## Why this is not a one-liner
 *
 * Two stacked defects, both measured live against the real loaded classes rather
 * than inferred from a docblock.
 *
 * ### 1. Which branch runs
 *
 * OpenRegister's `ObjectEntity` declares neither `getId()` nor `getUuid()`; both
 * arrive through `OCP\AppFramework\Db\Entity::__call()`, and `Entity::getter()`
 * decides with `property_exists()`. So:
 *
 *     method_exists(ObjectEntity, 'getId')         = false
 *     method_exists(ObjectEntity, 'getUuid')       = false
 *     method_exists(ObjectEntity, 'jsonSerialize') = true    <- POSITIVE CONTROL
 *     is_callable($o, 'totalNonsenseXyz')          = true    <- NEGATIVE CONTROL
 *     property_exists($o, 'uuid')                  = true
 *
 * A `method_exists()` probe is therefore permanently FALSE and the guarded branch
 * never runs. ⚠️ Swapping in `is_callable()` is not the fix — it is TRUE for any
 * name at all on a `__call()` class, which turns a never-taken branch into an
 * always-taken one and moves the failure into the accessor.
 *
 * ### 2. Which identifier space
 *
 * Probing correctly is still wrong if you then name the wrong accessor. On the
 * value production passes — a `saveObject()` return:
 *
 *     getId()             = 2  (integer)   <- the numeric bigint row id
 *     getUuid()           = 'd1185dfc-…'
 *     jsonSerialize()[id] = 'd1185dfc-…'   <- the UUID
 *
 * Every cross-reference in this app joins on the UUID: OR renders `id` as the
 * UUID, `@self.id` renders as the UUID, and a `findAll(['filters' => ['id' =>
 * $uuid]])` against the bigint column dies with `SQLSTATE[22P02] invalid input
 * syntax for type bigint` — the two spaces are not interchangeable in either
 * direction. So `property_exists($x, 'id') && $x->getId()` returns `"2"` into a
 * UUID-typed foreign key: a dangling reference that fails later and silently,
 * which is strictly worse than the dead path it replaces.
 *
 * `getId()` is therefore never called here.
 *
 * ⚠️ A fixture built from `findAll()` cannot catch defect 2 — on a *read* entity
 * `getId()` returns NULL, the fallback runs, and the wrong fix certifies itself.
 * Build fixtures from the same call the production path uses.
 *
 * @category Util
 * @package  OCA\Shillinq\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-cycle-count/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Util;

use Throwable;

/**
 * Resolves an OpenRegister object's UUID from a save or read return value.
 *
 * @spec openspec/specs/inventory-cycle-count/spec.md
 */
final class ObjectIdentifier
{
    /**
     * Resolve the UUID from whatever ObjectService handed back.
     *
     * Accepts both the array shape and the entity shape, and answers in the same
     * identifier space for both — the array arm already returned the UUID, and
     * the object arm now agrees with it.
     *
     * @param mixed $saved Whatever ObjectService::saveObject()/findAll() returned.
     *
     * @return string The object's UUID, or '' when not derivable.
     *
     * @spec openspec/specs/inventory-cycle-count/spec.md
     */
    public static function resolve(mixed $saved): string
    {
        if (is_array($saved) === true) {
            return self::stringify(value: $saved['id'] ?? $saved['@self']['id'] ?? null);
        }

        if (is_object($saved) === false) {
            return '';
        }

        // `jsonSerialize()` IS concrete on ObjectEntity, so `method_exists()`
        // answers it correctly, and it renders `id` as the UUID.
        $rendered = self::rendered(entity: $saved);
        if ($rendered !== '') {
            return $rendered;
        }

        // `getUuid()` is magic, so `property_exists()` on the backing property is
        // the only correct probe — and it is exactly what `Entity::getter()`
        // itself decides on.
        if (property_exists($saved, 'uuid') === false) {
            return '';
        }

        return self::accessor(entity: $saved, getter: 'getUuid');

    }//end resolve()

    /**
     * Read the rendered `id` from an entity's jsonSerialize(), tolerating failure.
     *
     * @param object $entity The OpenRegister ObjectEntity.
     *
     * @return string The rendered id, or '' when unavailable.
     */
    private static function rendered(object $entity): string
    {
        if (method_exists($entity, 'jsonSerialize') === false) {
            return '';
        }

        try {
            $payload = $entity->jsonSerialize();
        } catch (Throwable $e) {
            return '';
        }

        if (is_array($payload) === false) {
            return '';
        }

        return self::stringify(value: $payload['id'] ?? null);

    }//end rendered()

    /**
     * Call a magic accessor by variable name, tolerating failure.
     *
     * Mirrors ListenerSchemaResolver::readAccessor(). The accessor is invoked
     * through a variable method name because `property_exists()` — unlike
     * `method_exists()` — is not a static-analysis type guard, and because
     * `Entity::__call()` throws BadFunctionCallException for a property the
     * entity does not carry.
     *
     * @param object $entity The OpenRegister ObjectEntity.
     * @param string $getter The accessor name, e.g. 'getUuid'.
     *
     * @return string The scalar value as a string, or '' when unavailable.
     */
    private static function accessor(object $entity, string $getter): string
    {
        try {
            return self::stringify(value: $entity->{$getter}());
        } catch (Throwable $e) {
            return '';
        }

    }//end accessor()

    /**
     * Render a scalar identifier as a non-empty string.
     *
     * @param mixed $value The candidate identifier.
     *
     * @return string The value as a string, or '' when absent or non-scalar.
     */
    private static function stringify(mixed $value): string
    {
        if (is_scalar($value) === false) {
            return '';
        }

        return (string) $value;

    }//end stringify()
}//end class
