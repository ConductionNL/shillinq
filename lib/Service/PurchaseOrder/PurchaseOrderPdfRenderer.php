<?php

/**
 * Renders a purchase order as a one-document text PDF.
 *
 * Plain PDF 1.4 with the built-in Helvetica font, so no library is needed:
 * the order number, supplier, dates, lines and totals, one line of text per
 * row, as many A4 pages as the lines take.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

/**
 * The purchase order as a PDF attachment.
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 */
class PurchaseOrderPdfRenderer {

	/**
	 * Text lines on one A4 page.
	 *
	 * @var integer
	 */
	public const LINES_PER_PAGE = 60;

	/**
	 * The order fields printed under the supplier, with their labels.
	 *
	 * @var array<string,string>
	 */
	private const HEADER_FIELDS = [
		'supplierReference'    => 'Your reference',
		'expectedDeliveryDate' => 'Expected delivery',
		'deliveryAddress'      => 'Delivery address',
		'paymentTerms'         => 'Payment terms',
	];

	/**
	 * The order's text lines, as the PDF prints them.
	 *
	 * @param array<string,mixed> $purchaseOrder The order.
	 * @param string              $supplierName  The supplier's name.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function lines(array $purchaseOrder, string $supplierName): array {
		$currency = (string)($purchaseOrder['currency'] ?? 'EUR');
		$out = [
			'Purchase order ' . (string)($purchaseOrder['poNumber'] ?? ''),
			'',
			'Supplier: ' . $supplierName,
		];
		foreach (self::HEADER_FIELDS as $field => $label) {
			$value = trim((string)($purchaseOrder[$field] ?? ''));
			if ($value !== '') {
				$out[] = $label . ': ' . $value;
			}
		}

		$out[] = '';
		foreach ((array)($purchaseOrder['lines'] ?? []) as $index => $line) {
			$line = (array)$line;
			$out[] = sprintf(
				'%d. %s  %s x %s %s',
				((int)$index + 1),
				(string)($line['description'] ?? ''),
				$this->number(value: $line['quantityOrdered'] ?? ($line['quantity'] ?? 0)),
				$currency,
				$this->number(value: $line['unitPrice'] ?? 0)
			);
		}

		$out[] = '';
		$out[] = 'Total excl. VAT: ' . $currency . ' ' . $this->number(value: $purchaseOrder['totalExclVat'] ?? 0);
		$out[] = 'VAT: ' . $currency . ' ' . $this->number(value: $purchaseOrder['totalVat'] ?? 0);
		$out[] = 'Total incl. VAT: ' . $currency . ' ' . $this->number(value: $purchaseOrder['totalInclVat'] ?? 0);

		return $out;
	}//end lines()

	/**
	 * The order as PDF bytes.
	 *
	 * @param array<string,mixed> $purchaseOrder The order.
	 * @param string              $supplierName  The supplier's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
	 */
	public function render(array $purchaseOrder, string $supplierName): string {
		$pages = array_chunk($this->lines(purchaseOrder: $purchaseOrder, supplierName: $supplierName), self::LINES_PER_PAGE);
		$fontId = 3 + (2 * count($pages));
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
		];
		$kids = [];
		foreach ($pages as $index => $pageLines) {
			$pageId = 3 + (2 * $index);
			$kids[] = $pageId . ' 0 R';
			$stream = $this->stream(lines: $pageLines);
			$objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '
				. $fontId . ' 0 R >> >> /Contents ' . ($pageId + 1) . ' 0 R >>';
			$objects[$pageId + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
		}

		$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
		$objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		ksort($objects);

		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $id => $body) {
			$offsets[$id] = strlen($pdf);
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}//end render()

	/**
	 * One page's content stream.
	 *
	 * @param array<int,string> $lines The page's lines.
	 *
	 * @return string
	 */
	private function stream(array $lines): string {
		$out = "BT\n/F1 10 Tf\n12 TL\n50 800 Td\n";
		foreach ($lines as $line) {
			$out .= '(' . $this->escape(text: $line) . ") Tj T*\n";
		}

		return $out . 'ET';
	}//end stream()

	/**
	 * A PDF string literal's content: WinAnsi bytes with the three specials escaped.
	 *
	 * @param string $text UTF-8 text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		$encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
	}//end escape()

	/**
	 * A number with two decimals.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function number(mixed $value): string {
		return number_format((float)$value, 2, '.', '');
	}//end number()
}//end class
