<?php

/**
 * Contract of ProductCatalogService
 *
 * The public surface of ProductCatalogService that other components call, so they depend
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
 * Contract of ProductCatalogService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProductCatalogServiceInterface {

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
	public function listAttributes(): ?array;

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
	public function listProducts(): ?array;
}//end interface
