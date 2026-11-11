<?php

/**
 * Tagged file store messages
 *
 * The two warnings a TaggedFileStore writes, in its owner's words, so every
 * owner's log line reads as it always has.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * The warning messages of one owner of a TaggedFileStore.
 */
final class TaggedFileStoreMessages {
	/**
	 * Constructor.
	 *
	 * @param string $notFolder Warning when a path segment exists but is not a folder.
	 * @param string $tagFailed Warning when a system tag cannot be applied.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $notFolder,
		public readonly string $tagFailed,
	) {

	}//end __construct()
}//end class
