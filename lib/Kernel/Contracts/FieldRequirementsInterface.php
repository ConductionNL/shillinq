<?php

/**
 * Contract of FieldRequirements
 *
 * The public surface of FieldRequirements that other components call, so they depend
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
 * Contract of FieldRequirements.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FieldRequirementsInterface {

	/**
	 * The schema holding one requirement.
	 */
	public const SCHEMA = 'FieldRequirement';

	/**
	 * One requirement by id, null when absent.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The requirement.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function find(string $id): ?array;

	/**
	 * The fields of a requirement's schema, each marked always, administration or no.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 *
	 * @return list<array{field: string, title: string, required: string, requiredLabel: string}> The fields.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function formFields(array $requirement): array;

	/**
	 * The active requirements of the given administrations, switched on or off, each with a title naming field and record type.
	 *
	 * @param list<string> $administrationIds The administrations.
	 *
	 * @return list<array<string, mixed>> The requirements, each with `title`.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	public function listed(array $administrationIds): array;

	/**
	 * The required fields an object of a schema leaves empty, with their titles and reasons.
	 *
	 * @param array{slug: string, title: string, properties: array<string, mixed>, required: list<string>} $definition The object's schema.
	 * @param array<string, mixed>                                                                          $object     The object.
	 *
	 * @return list<array{field: string, title: string, reason: string}> The missing fields.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function missing(array $definition, array $object): array;

	/**
	 * Why a requirement cannot be saved, by the field to correct; empty when it can.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 *
	 * @return array<string, string> The refusal by field.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function refusal(array $requirement): array;
}//end interface
