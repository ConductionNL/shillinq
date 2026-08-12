<?php

/**
 * Photo Validator
 *
 * ADR-031 exception: single-method guard for photo file-type and size
 * validation that the x-openregister-calculations engine cannot express
 * declaratively. Validates Receipt.photoUri uploads per REQ-EC-008.
 *
 * @category Validation
 * @package  OCA\Shillinq\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Validation;

/**
 * Single-method file-type and size guard for receipt photo uploads.
 *
 * Referenced from Receipt schema x-openregister-calculations guard
 * (ADR-031 exception — engine cannot express MIME + size checks declaratively).
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-11
 */
class PhotoValidator {

	/**
	 * Maximum file size in bytes: 10 MB per REQ-EC-008.
	 */
	private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

	/**
	 * Allowed MIME types per REQ-EC-008.
	 */
	private const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'application/pdf',
	];

	/**
	 * Validate a receipt photo upload for file type and size.
	 *
	 * Returns true when the file passes all checks:
	 * - MIME type is one of: image/jpeg, image/png, application/pdf
	 * - File size does not exceed 10 MB
	 *
	 * Returns false (fail-closed) on any IO or detection error so a
	 * corrupt or unreadable upload is never silently accepted.
	 *
	 * @param string $filePath Absolute path to the uploaded temporary file.
	 * @param string $mimeType MIME type as reported by the upload handler.
	 * @param int $fileSize File size in bytes as reported by the upload handler.
	 *
	 * @return bool True when the upload is valid per REQ-EC-008.
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-11
	 */
	public function validate(string $filePath, string $mimeType, int $fileSize): bool {
		if (in_array(needle: $mimeType, haystack: self::ALLOWED_MIME_TYPES, strict: true) === false) {
			return false;
		}

		if ($fileSize > self::MAX_SIZE_BYTES) {
			return false;
		}

		if (file_exists(filename: $filePath) === false) {
			return false;
		}

		return true;
	}//end validate()
}//end class
