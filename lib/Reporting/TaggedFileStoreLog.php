<?php

/**
 * Tagged file store log
 *
 * Where a TaggedFileStore reports a failure, and in whose words: the owner's
 * logger and the two warning messages it has always written, so moving the
 * storage steps into one place leaves every owner's log line as it was.
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

use Psr\Log\LoggerInterface;

/**
 * The logger and warning messages of one owner of a TaggedFileStore.
 */
final class TaggedFileStoreLog {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger    The owner's logger.
	 * @param string          $notFolder Warning when a path segment exists but is not a folder.
	 * @param string          $tagFailed Warning when a system tag cannot be applied.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly string $notFolder,
		public readonly string $tagFailed,
	) {

	}//end __construct()
}//end class
