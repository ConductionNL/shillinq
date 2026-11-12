<?php

/**
 * System tag assigner
 *
 * Assigns a user-visible, assignable system tag to a stored file, creating the
 * tag when it does not exist yet.
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

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

/**
 * Resolves (or creates) a system tag by name and assigns it to a file.
 */
final class SystemTagAssigner {
	/**
	 * Object type stored files are tagged under.
	 *
	 * @var string
	 */
	private const TAG_OBJECT_TYPE = 'files';

	/**
	 * Constructor.
	 *
	 * @param ISystemTagManager      $tagManager Resolves/creates the system tags.
	 * @param ISystemTagObjectMapper $tagMapper  Maps tags onto the stored file id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagMapper,
	) {

	}//end __construct()

	/**
	 * Assign the named tag to a file id, creating the tag on miss.
	 *
	 * @param int    $fileId  The Nextcloud file id.
	 * @param string $tagName The tag name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	public function assign(int $fileId, string $tagName): void {
		$tag = $this->resolveTag(tagName: $tagName);
		$this->tagMapper->assignTags((string)$fileId, self::TAG_OBJECT_TYPE, [$tag->getId()]);

	}//end assign()

	/**
	 * Resolve a user-visible, assignable system tag by name, creating it on miss.
	 *
	 * @param string $tagName The tag name.
	 *
	 * @return ISystemTag The resolved (or newly created) tag.
	 */
	private function resolveTag(string $tagName): ISystemTag {
		try {
			return $this->tagManager->getTag($tagName, true, true);
		} catch (TagNotFoundException $e) {
			return $this->tagManager->createTag($tagName, true, true);
		}

	}//end resolveTag()
}//end class
