<?php

/**
 * Statement Body Reader
 *
 * Reads an uploaded statement file from a request: a multipart 'file'
 * upload, or a JSON body carrying raw or base64 'contents'.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The upload and JSON-body steps of reading a statement file from a request.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */
final class StatementBodyReader {
	/**
	 * The request to read from.
	 *
	 * @var IRequest
	 */
	private readonly IRequest $request;

	/**
	 * Read from this request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->request = $request;

	}//end __construct()

	/**
	 * The contents of a real multipart 'file' upload ('' when it cannot be
	 * read), or null when the request carries no such upload.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	public function uploadedContents(): ?string {
		$uploaded = $this->request->getUploadedFile('file');
		if ($this->isUpload(uploaded: $uploaded) === false) {
			return null;
		}

		$raw = file_get_contents((string)$uploaded['tmp_name']);
		if ($raw === false) {
			return '';
		}

		return $raw;
	}//end uploadedContents()

	/**
	 * The decoded JSON request body, or null when there is none or it is not
	 * an object/array.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	public function jsonBody(): ?array {
		$rawBody = file_get_contents('php://input');
		if ($rawBody === false || $rawBody === '') {
			return null;
		}

		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end jsonBody()

	/**
	 * The body's contents, tolerating base64-wrapped payloads from the browser.
	 *
	 * @param array<string,mixed> $decoded The decoded JSON body.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	public function bodyContents(array $decoded): string {
		$contents = (string)($decoded['contents'] ?? '');
		if ($contents === '' || ($decoded['encoding'] ?? '') !== 'base64') {
			return $contents;
		}

		$maybe = base64_decode($contents, true);
		if ($maybe === false) {
			return $contents;
		}

		return $maybe;
	}//end bodyContents()

	/**
	 * Whether the 'file' entry is a real multipart upload.
	 *
	 * @param mixed $uploaded The uploaded-file entry.
	 *
	 * @return bool
	 */
	private function isUpload(mixed $uploaded): bool {
		return is_array($uploaded) === true
			&& isset($uploaded['tmp_name']) === true
			&& is_uploaded_file((string)$uploaded['tmp_name']) === true;
	}//end isUpload()
}//end class
