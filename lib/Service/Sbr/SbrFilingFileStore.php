<?php

/**
 * Shillinq SBR filing file store
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sbr;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use RuntimeException;

/**
 * Stores the XML of a filing under /Shillinq/Sbr/<administrationId>/ in the
 * files of the user who files it, so integriq is handed a file, not the
 * bytes, and the filed document stays with the books.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
class SbrFilingFileStore {

	/**
	 * The URI prefix of a stored filing, followed by the Nextcloud file id.
	 */
	public const URI_PREFIX = 'nextcloud://files/';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder  $rootFolder  Nextcloud Files.
	 * @param IUserSession $userSession Whose files the filing is stored in.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Store (or replace) the filing's file and return its URI.
	 *
	 * @param string $administrationId The administration.
	 * @param string $fileName         The file name.
	 * @param string $content          The XML.
	 *
	 * @return string The file's URI.
	 *
	 * @throws RuntimeException When no user is signed in or the folder is taken by a file.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public function store(string $administrationId, string $fileName, string $content): string {
		$userId = (string) $this->userSession->getUser()?->getUID();
		if ($userId === '') {
			throw new RuntimeException('The SBR filing can only be stored for a signed-in user.');
		}

		$folder   = $this->rootFolder->getUserFolder($userId);
		$segments = ['Shillinq', 'Sbr'];
		if (trim($administrationId) !== '') {
			$segments[] = trim($administrationId);
		}

		foreach ($segments as $segment) {
			$folder = self::child(parent: $folder, name: str_replace('/', '-', $segment));
		}

		$name = str_replace('/', '-', $fileName);
		if ($folder->nodeExists($name) === false) {
			return self::URI_PREFIX . (string) $folder->newFile($name, $content)->getId();
		}

		$file = $folder->get($name);
		if ($file instanceof File === false) {
			throw new RuntimeException(sprintf('%s is not a file; the SBR filing cannot be stored.', $name));
		}

		$file->putContent($content);
		return self::URI_PREFIX . (string) $file->getId();
	}//end store()

	/**
	 * The named child folder, created when absent.
	 *
	 * @param Folder $parent The parent.
	 * @param string $name   The child's name.
	 *
	 * @return Folder The child.
	 *
	 * @throws RuntimeException When the name is taken by a file.
	 */
	private static function child(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name) === false) {
			return $parent->newFolder($name);
		}

		$node = $parent->get($name);
		if ($node instanceof Folder === false) {
			throw new RuntimeException(sprintf('%s is not a folder; the SBR filing cannot be stored.', $name));
		}

		return $node;
	}//end child()
}//end class
