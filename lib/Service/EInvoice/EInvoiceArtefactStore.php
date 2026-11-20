<?php

/**
 * E-invoice artefact store.
 *
 * Writes the hybrid PDF and the UBL XML of a sent e-invoice into the
 * administration's folder in Nextcloud Files, so the reference handed to the
 * Peppol transport names a file that exists (REQ-SEIX-002).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stores the files of one e-invoice under
 * `Shillinq/<administration>/e-invoices/<year>/` of the sending user.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
 */
class EInvoiceArtefactStore {

	/**
	 * Top folder of the app's files.
	 *
	 * @var string
	 */
	public const ROOT_FOLDER = 'Shillinq';

	/**
	 * Folder of the e-invoices inside an administration's folder.
	 *
	 * @var string
	 */
	public const EINVOICE_FOLDER = 'e-invoices';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder  Nextcloud Files root.
	 * @param IUserSession    $userSession The sending user, whose Files hold the artefacts.
	 * @param LoggerInterface $logger      Logs a failed store.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Store the hybrid PDF and the UBL XML of one invoice.
	 *
	 * @param string              $administrationId The administration the invoice belongs to.
	 * @param array<string,mixed> $invoice          The invoice (its number and date name the files).
	 * @param string              $pdf              The hybrid PDF bytes.
	 * @param string              $ublXml           The UBL XML.
	 *
	 * @return string|null The Files path of the stored XML, or null when nothing could be stored.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
	 */
	public function store(string $administrationId, array $invoice, string $pdf, string $ublXml): ?string {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($userId === '') {
			$this->logger->warning('EInvoiceArtefactStore: no user session, the e-invoice files are not stored');
			return null;
		}

		$baseName = 'invoice-' . $this->segment(value: (string)($invoice['invoiceNumber'] ?? ''), fallback: 'unnumbered');

		try {
			$folder = $this->folder(
				base: $this->rootFolder->getUserFolder($userId),
				segments: [
					self::ROOT_FOLDER,
					$this->segment(value: $administrationId, fallback: 'administration'),
					self::EINVOICE_FOLDER,
					$this->year(invoice: $invoice),
				]
			);
			$this->write(folder: $folder, name: $baseName . '.pdf', content: $pdf);
			$xml = $this->write(folder: $folder, name: $baseName . '.xml', content: $ublXml);

			return $xml->getPath();
		} catch (Throwable $e) {
			$this->logger->warning(
				'EInvoiceArtefactStore: failed to store the e-invoice files',
				['administrationId' => $administrationId, 'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''), 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end store()

	/**
	 * The folder at a path below a base, created where missing.
	 *
	 * @param Folder            $base     The user's Files root.
	 * @param array<int,string> $segments The path below it.
	 *
	 * @return Folder The folder.
	 *
	 * @throws RuntimeException When a segment exists as a file.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
	 */
	private function folder(Folder $base, array $segments): Folder {
		$folder = $base;
		foreach ($segments as $segment) {
			if ($folder->nodeExists($segment) === false) {
				$folder = $folder->newFolder($segment);
				continue;
			}

			$node = $folder->get($segment);
			if (($node instanceof Folder) === false) {
				throw new RuntimeException('Path segment "' . $segment . '" is not a folder');
			}

			$folder = $node;
		}

		return $folder;

	}//end folder()

	/**
	 * Write one file, replacing the content of an earlier send of the same invoice.
	 *
	 * @param Folder $folder  The folder.
	 * @param string $name    The file name.
	 * @param string $content The content.
	 *
	 * @return File The file.
	 *
	 * @throws RuntimeException When the name exists as a folder.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
	 */
	private function write(Folder $folder, string $name, string $content): File {
		if ($folder->nodeExists($name) === false) {
			return $folder->newFile($name, $content);
		}

		$node = $folder->get($name);
		if (($node instanceof File) === false) {
			throw new RuntimeException('"' . $name . '" exists and is not a file');
		}

		$node->putContent($content);

		return $node;

	}//end write()

	/**
	 * The year folder of an invoice: its invoice date's year, else the current year.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return string The year.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
	 */
	private function year(array $invoice): string {
		$date = (string)($invoice['invoiceDate'] ?? '');
		if (preg_match('/^(\d{4})-/', $date, $matches) === 1) {
			return $matches[1];
		}

		return date('Y');

	}//end year()

	/**
	 * A value made safe to use as one path segment.
	 *
	 * @param string $value    The value.
	 * @param string $fallback What to use when nothing is left.
	 *
	 * @return string The segment.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.1
	 */
	private function segment(string $value, string $fallback): string {
		$safe = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '.-');
		if ($safe === '') {
			return $fallback;
		}

		return $safe;

	}//end segment()
}//end class
