<?php

/**
 * Contract of IntercompanyLinkService
 *
 * The public surface of IntercompanyLinkService that other components call, so they depend
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
 * Contract of IntercompanyLinkService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntercompanyLinkServiceInterface {

	/**
	 * Resolve the counter-side entry of an intercompany pair.
	 *
	 * The counter-side carries the same `intercompanyNumber` with the source
	 * and destination administrations swapped. Also used by
	 * {@see \OCA\Shillinq\Guard\IntercompanyEliminationGuard}, which must
	 * fail closed when this returns null.
	 *
	 * @param array<string,mixed> $entry Either side of the pair.
	 *
	 * @return array<string,mixed>|null The counter-side entry, or null when absent.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findCounterSide(array $entry): ?array;

	/**
	 * Mirror + reconcile an intercompany pair on `link` (REQ-GLTAX-002).
	 *
	 * Wired by {@see \OCA\Shillinq\Listener\IntercompanyLinkListener} on the
	 * `IntercompanyJournalEntry` `concept -> gekoppeld` transition.
	 *
	 * @param array<string,mixed> $entry The source-side IntercompanyJournalEntry that was just linked.
	 *
	 * @return array{linked:bool, mirrorCreated:bool, varianceAmount:float, balanced:bool, message:string}
	 *
	 * @throws \RuntimeException When the entry carries no intercompanyNumber.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function linkAndReconcile(array $entry): array;
}//end interface
