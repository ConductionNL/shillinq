<?php

/**
 * Contract of BbvProgrammeBudgetService
 *
 * The public surface of BbvProgrammeBudgetService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BbvProgrammeBudgetService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BbvProgrammeBudgetServiceInterface {

	/**
	 * The seven BBV programme codes REQ-BBC-002 enumerates.
	 *
	 * @var array<int,string>
	 */
	public const PROGRAMMES = [
		'ruimte',
		'mobiliteit',
		'water',
		'milieu',
		'cultuur',
		'economie',
		'bestuur',
	];

	/**
	 * Build the GL-line filter facets the Budget-to-Programme Linker renders
	 * (REQ-BBL-001, "Filter bar: account type, programme, assignment status").
	 *
	 * Each facet resolves to a filter over a property `GLLine` actually
	 * DECLARES, so selecting one narrows the list instead of matching nothing:
	 *
	 *  - **Account type** is a property of `Account`, not of `GLLine`. The facet
	 *    therefore carries the administration's account NUMBERS per type, and
	 *    the client filters `accountNumber[]` — a declared GL-line property.
	 *  - **Programme** filters `programmeStructure` directly.
	 *  - **Assignment status** offers `mapped` only, and that is a MEASURED
	 *    limitation rather than an oversight — see below.
	 *
	 * ## Why `unmapped` is not offered
	 *
	 * "Unmapped" means the GL line has NO `programmeStructure`. OpenRegister
	 * stores only the properties an object actually carries, so an unassigned
	 * line has no such key at all — and the filter grammar cannot address an
	 * ABSENT key. Measured on a live instance, with a positive control that
	 * proves the operator family is alive:
	 *
	 *     Account?vatApplicable[null]=false   -> 115   (a key every row HAS)
	 *     Account?description[null]=false     ->   0
	 *     Account?description[null]=true      ->   0   (a key no row has)
	 *     GLLine?programmeStructure[empty]=true  -> 0
	 *     GLLine?programmeStructure[empty]=false -> 0
	 *
	 * BOTH truth values answering zero is the tell: the clause is unsatisfiable,
	 * not selective. `[ne]` and the `field[]=` IN form both work, so the facet
	 * offers the half that can be expressed — `mapped`, as an IN over the seven
	 * declared programme codes — rather than an `unmapped` option that would
	 * render, be clickable, and quietly return nothing. Restoring it needs an
	 * OpenRegister filter for an absent property; that is a different repo and
	 * a separate decision.
	 *
	 * @return array<string,mixed> The facet envelope; empty facets when the
	 *         caller has no accessible administration.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function glLineFacets(): array;

	/**
	 * Build the programme budget-vs-actuals envelope (REQ-BBC-001..003).
	 *
	 * @param integer|null $fiscalYear Fiscal-year filter; null = the caller's active year.
	 * @param array<int,string>|null $programmes Programme filter; null = every programme.
	 *                                           An EMPTY array means "none selected",
	 *                                           which REQ-BBC-002 requires to show no
	 *                                           data rather than everything.
	 * @param array<int,string>|null $statuses Budget-status filter; null = every status.
	 *
	 * @return array<string,mixed> The dashboard envelope; an empty envelope when the
	 *         caller has no accessible administration.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function programmeBudgetVsActuals(
		?int $fiscalYear = null,
		?array $programmes = null,
		?array $statuses = null,
	): array;
}//end interface
