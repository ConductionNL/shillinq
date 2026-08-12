<?php

/**
 * Seeds Objects (optional CheckProvider capability)
 *
 * A CheckProvider whose rules target an object type that has no existing rows in
 * the local test register implements this to supply sample objects. The seeder
 * creates them (only when the type is empty) so the audit actually evaluates the
 * provider's checks against real data rather than counting them as never-run.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Optional capability: supply compliant sample objects for new object types.
 */
interface SeedsObjects {
	/**
	 * Compliant sample objects to create when the object type is empty, keyed by
	 * object type. Each entry is a list of objects (assoc arrays) that SATISFY this
	 * provider's checks. Return [] when no object creation is needed.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array;
}//end interface
