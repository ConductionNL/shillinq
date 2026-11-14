<?php

/**
 * Contract of ListenerSchemaResolver
 *
 * The public surface of ListenerSchemaResolver that other components call, so they depend
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

/**
 * Contract of ListenerSchemaResolver.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ListenerSchemaResolverInterface {

	/**
	 * Whether the entity belongs to shillinq's own OpenRegister register.
	 *
	 * This is the guard that keeps a schema-only literal (for example the two
	 * distinct schemas both slugged `automation`) from firing on another app's
	 * objects.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return bool True when the entity sits in shillinq's register.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function isOwnRegister(?object $entity): bool;

	/**
	 * Whether an entity sits in a named register AND schema, both matched by slug.
	 *
	 * For listeners that consume ANOTHER app's objects. {@see matchesSchema()}
	 * cannot serve them: it is scoped to shillinq's own register on purpose.
	 * integriq, for one, saves every CloudEvent as an object in its `integriq`
	 * register, `event` schema, and OpenRegister stamps both as numeric ids.
	 *
	 * Each side accepts a raw value that already is the slug (a hand-built
	 * entity), or an id resolved through OpenRegister's mapper. Like
	 * matchesSchema() this does not depend on {@see ListenerSlugContract}: a
	 * listener that can only match through the gate never fires by default.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 * @param string $registerSlug The register slug to match (e.g. 'integriq').
	 * @param string $schemaSlug The schema slug to match (e.g. 'event').
	 *
	 * @return bool True when the entity is in that register and schema.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
	 */
	public function matchesRegisterAndSchema(?object $entity, string $registerSlug, string $schemaSlug): bool;

	/**
	 * Whether an entity is an instance of the named shillinq schema.
	 *
	 * Unlike {@see schemaSlug()} this does NOT depend on {@see ListenerSlugContract}:
	 * while that gate is closed `schemaSlug()` deliberately returns the entity's
	 * raw schema value, which OpenRegister stamps as a numeric ID — so a caller
	 * comparing it against a slug literal never matches and its listener silently
	 * never fires. A write-path guard cannot be allowed to fail that way, so this
	 * method accepts either form: a raw value that already IS the slug (hand-built
	 * entities and unit-test doubles), or a schema ID resolved through
	 * OpenRegister's SchemaMapper.
	 *
	 * Scoped to shillinq's own register, so a same-slug schema owned by another
	 * app can never trip a shillinq guard.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 * @param string $expectedSlug The schema slug to match (e.g. 'OrderFulfilment').
	 *
	 * @return bool True when the entity is an instance of that schema.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function matchesSchema(?object $entity, string $expectedSlug): bool;

	/**
	 * Resolve the schema slug of an OpenRegister object entity.
	 *
	 * Returns `''` when the object does not belong to shillinq's register, when
	 * the schema cannot be resolved, or when OpenRegister is unavailable — every
	 * caller treats `''` as "not my object" and returns early, so an
	 * unresolvable entity is never mistaken for a match.
	 *
	 * While {@see ListenerSlugContract} is disabled this deliberately returns
	 * the entity's raw schema value (an id), preserving the pre-fix behaviour.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return string The schema slug, or '' when this is not a shillinq object.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function schemaSlug(?object $entity): string;
}//end interface
