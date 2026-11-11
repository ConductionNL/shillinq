<?php

/**
 * Pins QuickDraftInvoiceService's line builder: the EN 16931 line it builds,
 * the lines it skips, and the refusals for unusable numbers and rates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The line builder before it is split into named checks.
 *
 * @covers \OCA\Shillinq\Service\Sales\QuickDraftInvoiceService
 */
final class QuickDraftInvoiceLineTest extends TestCase {

	/**
	 * Build lines with the service's own builder.
	 *
	 * @param mixed $given The lines.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function lines(mixed $given): array {
		return (new ReflectionMethod(QuickDraftInvoiceService::class, 'lines'))->invoke(null, $given, '8000');

	}//end lines()

	/**
	 * Lines and what they build.
	 *
	 * @return array<string, array{0: mixed, 1: array<int,array<string,mixed>>}>
	 */
	public static function built(): array {
		$full = ['lineId' => '1', 'itemName' => 'Advice', 'quantity' => 2.0, 'unitCode' => 'C62', 'netPrice' => 12.345, 'netAmount' => 24.69, 'vatRate' => 21.0, 'vatCategory' => 'S', 'glAccount' => '8100'];
		return [
			'full line'            => [[['description' => ' Advice ', 'quantity' => '2', 'unitPrice' => '12.345', 'vatRate' => 21, 'glAccount' => ' 8100 ']], [$full]],
			'defaults'             => [[['description' => 'Fee', 'unitPrice' => 10]], [['lineId' => '1', 'itemName' => 'Fee', 'quantity' => 1.0, 'unitCode' => 'C62', 'netPrice' => 10.0, 'netAmount' => 10.0, 'vatRate' => 0.0, 'vatCategory' => 'Z', 'glAccount' => '8000']]],
			'skips empty lines'    => [[['description' => '', 'unitPrice' => 0], 'x', ['description' => 'Fee', 'unitPrice' => 5, 'vatRate' => 9]], [['lineId' => '1', 'itemName' => 'Fee', 'quantity' => 1.0, 'unitCode' => 'C62', 'netPrice' => 5.0, 'netAmount' => 5.0, 'vatRate' => 9.0, 'vatCategory' => 'S', 'glAccount' => '8000']]],
			'unpriced only'        => [[['description' => 'Note', 'unitPrice' => 0]], []],
			'price only'           => [[['unitPrice' => 3]], []],
			'none'                 => [null, []],
			'boundary rates'       => [[['description' => 'A', 'unitPrice' => 1, 'vatRate' => 0], ['description' => 'B', 'unitPrice' => 1, 'vatRate' => '100']], [
				['lineId' => '1', 'itemName' => 'A', 'quantity' => 1.0, 'unitCode' => 'C62', 'netPrice' => 1.0, 'netAmount' => 1.0, 'vatRate' => 0.0, 'vatCategory' => 'Z', 'glAccount' => '8000'],
				['lineId' => '2', 'itemName' => 'B', 'quantity' => 1.0, 'unitCode' => 'C62', 'netPrice' => 1.0, 'netAmount' => 1.0, 'vatRate' => 100.0, 'vatCategory' => 'S', 'glAccount' => '8000'],
			]],
		];

	}//end built()

	/**
	 * Each set of lines builds the pinned lines.
	 *
	 * @param mixed                          $given    The lines.
	 * @param array<int,array<string,mixed>> $expected The built lines.
	 *
	 * @return void
	 */
	#[DataProvider('built')]
	public function testBuilt(mixed $given, array $expected): void {
		self::assertSame($expected, self::lines($given));

	}//end testBuilt()

	/**
	 * Lines refused and the refusal each gets.
	 *
	 * @return array<string, array{0: array<int,array<string,mixed>>, 1: string}>
	 */
	public static function refused(): array {
		return [
			'quantity not a number'  => [[['description' => 'A', 'quantity' => 'two', 'unitPrice' => 1]], 'Every line needs a number for quantity, price and VAT rate.'],
			'price not a number'     => [[['description' => 'A', 'unitPrice' => 'a lot']], 'Every line needs a number for quantity, price and VAT rate.'],
			'rate not a number'      => [[['description' => '', 'unitPrice' => 0, 'vatRate' => 'high']], 'Every line needs a number for quantity, price and VAT rate.'],
			'rate below zero'        => [[['description' => 'A', 'unitPrice' => 1, 'vatRate' => -1]], 'A VAT rate lies between 0 and 100 percent.'],
			'rate above 100'         => [[['description' => 'A', 'unitPrice' => 1, 'vatRate' => 100.5]], 'A VAT rate lies between 0 and 100 percent.'],
		];

	}//end refused()

	/**
	 * Each refused line throws its message.
	 *
	 * @param array<int,array<string,mixed>> $given   The lines.
	 * @param string                         $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testRefused(array $given, string $message): void {
		$this->expectException(QuickDraftRefusedException::class);
		$this->expectExceptionMessage($message);
		self::lines($given);

	}//end testRefused()

	/**
	 * An empty line with an out-of-range rate is skipped, not refused.
	 *
	 * @return void
	 */
	public function testEmptyLineSkipsTheRateCheck(): void {
		self::assertSame([], self::lines([['description' => '', 'unitPrice' => 0, 'vatRate' => 500]]));

	}//end testEmptyLineSkipsTheRateCheck()
}//end class
