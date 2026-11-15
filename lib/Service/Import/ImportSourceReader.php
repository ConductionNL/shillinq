<?php

/**
 * Import Source Reader
 *
 * Reads an import batch's auditfile from Nextcloud Files as the batch owner.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use DomainException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserSession;
use Throwable;

/**
 * Files are linked, not copied: the batch names them and parse reads them here.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportSourceReader {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder  $rootFolder  Nextcloud Files.
	 * @param IUserSession $userSession The acting user, when the batch names no owner.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The contents of the batch's auditfile.
	 *
	 * @param array<string,mixed> $batch The ImportBatch data.
	 *
	 * @return string The XAF contents.
	 *
	 * @throws DomainException When the batch names no auditfile or its owner cannot read it.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function readAuditfile(array $batch): string {
		$source = $this->auditfileOf(batch: $batch);
		$path   = (string)($source['path'] ?? '');
		$owner  = (string)($batch['owner'] ?? '');
		if ($owner === '') {
			$owner = (string)($this->userSession->getUser()?->getUID() ?? '');
		}

		try {
			$node = $this->nodeFor(owner: $owner, source: $source);
			if ($node instanceof File && $node->isReadable() === true) {
				return (string)$node->getContent();
			}
		} catch (Throwable $e) {
			throw new DomainException(sprintf('The file %s cannot be read.', $path), 0, $e);
		}

		throw new DomainException(sprintf('The file %s cannot be read.', $path));

	}//end readAuditfile()

	/**
	 * The batch's auditfile entry: the first source file of kind xaf.
	 *
	 * @param array<string,mixed> $batch The ImportBatch data.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DomainException When the batch names no auditfile.
	 */
	private function auditfileOf(array $batch): array {
		foreach ((array)($batch['sourceFiles'] ?? []) as $source) {
			if (is_array($source) === true && (string)($source['kind'] ?? 'xaf') === 'xaf' && (string)($source['path'] ?? '') !== '') {
				return $source;
			}
		}

		throw new DomainException('Choose the auditfile (XAF) to import.');

	}//end auditfileOf()

	/**
	 * The file node, by its id when the batch has one, else by its path.
	 *
	 * @param string              $owner  The user whose Files hold the file.
	 * @param array<string,mixed> $source The source file entry.
	 *
	 * @return Node|null
	 */
	private function nodeFor(string $owner, array $source): ?Node {
		$folder = $this->rootFolder->getUserFolder($owner);
		$fileId = (string)($source['fileId'] ?? '');
		if ($fileId !== '') {
			return ($folder->getById((int)$fileId)[0] ?? null);
		}

		return $folder->get((string)$source['path']);

	}//end nodeFor()
}//end class
