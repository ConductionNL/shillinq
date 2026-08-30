<?php

/**
 * PDF Document Assembler
 *
 * Serialises a set of numbered PDF objects into a complete, structurally valid
 * PDF document with a correct cross-reference table and trailer, plus the
 * escaping rule for a PDF literal string token.
 *
 * ## Why this is a util and not a composer dependency
 *
 * Extracted verbatim from {@see \OCA\Shillinq\Service\InvoicePdfGenerator},
 * which already shipped this byte-writer for the NLCIUS hybrid invoice export
 * (REQ-EINV-002). That class's docblock records the decision and it still
 * stands: a heavy PDF/A toolchain (mPDF / TCPDF / dompdf) is gold-plating for
 * documents whose compliance artefact is the embedded XML, and shillinq's
 * `composer.json` requires nothing but `php` + `ext-zip` at runtime. The
 * cashflow export (REQ-CF-016) needs the same writer, so it lives here rather
 * than being copied a second time.
 *
 * ⚠️ What this writer does NOT do, stated so nobody infers it: it emits no ICC
 * `OutputIntent` and embeds no font (the standard-14 Helvetica/Courier faces
 * are referenced by name). A document built with it therefore meets NO PDF/A
 * conformance level, and nothing built on it may assert `pdfaid:part` /
 * `pdfaid:conformance` in its XMP metadata.
 *
 * @category Util
 * @package  OCA\Shillinq\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Util;

/**
 * Dependency-free PDF object serialiser.
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 */
final class PdfDocument {
	/**
	 * Serialise a set of PDF objects (indexed by object number starting at 1)
	 * into a complete PDF document with a correct cross-reference table and
	 * trailer.
	 *
	 * Object numbers MUST be contiguous from 1: the xref table is positional,
	 * so a gap would point a reader at the wrong byte offset.
	 *
	 * ℹ️ An instance method rather than a static one so callers can hold it as
	 * an injected collaborator — `phpmd`'s CleanCode ruleset is enabled here
	 * and reports static access as a finding, and `development` currently
	 * carries ZERO phpmd findings across `lib/`.
	 *
	 * @param array<int,string> $objects Object bodies keyed by object number
	 *                                   (without the `N 0 obj` / `endobj` wrapper).
	 *
	 * @return string The complete PDF document bytes.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 */
	public function assemble(array $objects): string {
		ksort($objects);
		$count = count($objects);

		$out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [0 => 0];

		foreach ($objects as $number => $body) {
			$offsets[$number] = strlen($out);
			$out .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xrefOffset = strlen($out);
		$out .= "xref\n0 " . ($count + 1) . "\n";
		$out .= "0000000000 65535 f \n";
		for ($i = 1; $i <= $count; $i++) {
			$out .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}

		$out .= "trailer\n<< /Size " . ($count + 1) . ' /Root 1 0 R /ID [<' . md5((string)$xrefOffset) . '> <' . md5($xrefOffset . '-2') . ">] >>\n";
		$out .= 'startxref' . "\n" . $xrefOffset . "\n%%EOF";

		return $out;
	}//end assemble()

	/**
	 * Escape a value for a PDF literal string `(...)` token (backslash,
	 * parentheses, and control characters).
	 *
	 * @param string $value Raw value.
	 *
	 * @return string The escaped value, safe to place between `(` and `)`.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 */
	public function escapeString(string $value): string {
		$escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

		return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $escaped);
	}//end escapeString()
}//end class
