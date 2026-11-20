<?php

/**
 * Contract of ChartTemplateService
 *
 * The public surface of ChartTemplateService that other components call, so they depend
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
 * Contract of ChartTemplateService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChartTemplateServiceInterface {

	/**
	 * The schema slug of an administration template.
	 *
	 * @var string
	 */
	public const TEMPLATE_SCHEMA = 'AdministrationTemplate';

	/**
	 * The active templates, for the setup wizard's template step.
	 *
	 * @return array<int, array{id: string, name: string, version: int, accounts: int, dimensions: int}>
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	public function activeTemplates(): array;

	/**
	 * Save an administration's accounts and dimensions as a new template at version 1.
	 *
	 * @param string $administrationId The administration to copy.
	 * @param string $name             The template's name.
	 *
	 * @return array<string, mixed> The saved template.
	 *
	 * @throws InvalidArgumentException When the name or administration is empty.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
	 */
	public function saveAsTemplate(string $administrationId, string $name): array;

	/**
	 * Seed an administration from a template: every account and dimension it
	 * does not have yet. Existing records are left as they are.
	 *
	 * @param string $templateId       The template.
	 * @param string $administrationId The administration to seed.
	 *
	 * @return array{added: string[], updated: string[], skipped: string[]}
	 *
	 * @throws InvalidArgumentException When the template does not exist or the administration is empty.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	public function seedFromTemplate(string $templateId, string $administrationId): array;

	/**
	 * Bring every administration that follows the template, and is behind
	 * its version, in step with it, and record each run.
	 *
	 * @param string $templateId The template.
	 *
	 * @return array<string, array{added: string[], updated: string[], skipped: string[]}> Report per administration id.
	 *
	 * @throws InvalidArgumentException When the template does not exist.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
	 */
	public function syncFollowers(string $templateId): array;
}//end interface
