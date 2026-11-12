<?php

/**
 * Tagged file store
 *
 * The steps of storing a generated file in a user's Files and tagging it:
 * descend or create the folder path, pick a name that does not collide, and
 * apply (creating when missing) the system tags. ReportGenerationService and
 * PaymentRunExportService both store their files this way; each builds one of
 * these with its own log messages.
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

use OCP\Files\Folder;

/**
 * Stores files in a folder path and tags them.
 */
final class TaggedFileStore {
	/**
	 * Constructor.
	 *
	 * @param SystemTagAssigner  $tags Assigns the system tags.
	 * @param TaggedFileStoreLog $log  The owner's logger and messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SystemTagAssigner $tags,
		private readonly TaggedFileStoreLog $log,
	) {

	}//end __construct()

	/**
	 * Ensure a nested folder path exists under a base folder, returning the leaf.
	 *
	 * @param Folder        $base     The base folder (the user's Files home).
	 * @param array<string> $segments Path segments to create/descend.
	 *
	 * @return Folder|null The leaf folder, or null when a segment is not a folder.
	 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	public function ensureFolder(Folder $base, array $segments): ?Folder {
		$current = $base;
		foreach ($segments as $segment) {
			$current = $this->childFolder(parent: $current, name: $segment);
			if ($current === null) {
				return null;
			}
		}

		return $current;
	}//end ensureFolder()

	/**
	 * Produce a non-colliding file name within a folder (suffixes -1, -2, ...).
	 *
	 * @param Folder $folder The target folder.
	 * @param string $name   The desired file name.
	 *
	 * @return string A name that does not yet exist in the folder.
	 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	public function uniqueName(Folder $folder, string $name): string {
		if ($folder->nodeExists($name) === false) {
			return $name;
		}

		$counter = 1;
		do {
			$candidate = self::numbered(name: $name, counter: $counter);
			$counter++;
		} while ($folder->nodeExists($candidate) === true && $counter < 1000);

		return $candidate;
	}//end uniqueName()

	/**
	 * Apply (creating if needed) the given system tags to a file id.
	 *
	 * @param int           $fileId The Nextcloud file id.
	 * @param array<string> $tags   The tag names to apply.
	 *
	 * @return void
	 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	public function applyTags(int $fileId, array $tags): void {
		foreach ($tags as $tagName) {
			$tagName = trim($tagName);
			if ($tagName !== '') {
				$this->applyTag(fileId: $fileId, tagName: $tagName);
			}
		}

	}//end applyTags()

	/**
	 * The child folder of a parent by name: the existing one, or a new one when
	 * absent; null (logged) when the name is taken by something that is not a folder.
	 *
	 * @param Folder $parent The parent folder.
	 * @param string $name   The child's name.
	 *
	 * @return Folder|null
	 */
	private function childFolder(Folder $parent, string $name): ?Folder {
		if ($parent->nodeExists($name) === false) {
			return $parent->newFolder($name);
		}

		$node = $parent->get($name);
		if ($node instanceof Folder) {
			return $node;
		}

		$this->log->logger->warning($this->log->messages->notFolder, ['segment' => $name]);
		return null;
	}//end childFolder()

	/**
	 * A file name with -<counter> before its extension, or at its end without one.
	 *
	 * @param string $name    The file name.
	 * @param int    $counter The suffix number.
	 *
	 * @return string
	 */
	private static function numbered(string $name, int $counter): string {
		$dot = strrpos($name, '.');
		if ($dot === false) {
			return $name . '-' . $counter;
		}

		return substr($name, 0, $dot) . '-' . $counter . substr($name, $dot);
	}//end numbered()

	/**
	 * Apply one system tag to a file id; a failure is logged, not thrown.
	 *
	 * @param int    $fileId  The Nextcloud file id.
	 * @param string $tagName The tag name.
	 *
	 * @return void
	 */
	private function applyTag(int $fileId, string $tagName): void {
		try {
			$this->tags->assign(fileId: $fileId, tagName: $tagName);
		} catch (\Throwable $e) {
			$this->log->logger->warning(
				$this->log->messages->tagFailed,
				['tag' => $tagName, 'fileId' => $fileId, 'exception' => $e->getMessage()]
			);
		}

	}//end applyTag()
}//end class
