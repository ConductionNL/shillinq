<?php

/**
 * Unit tests for InvoiceLineScope.
 *
 * The request value object for an invoice-line scope: the slug-sanitised administration and invoice ids (a malformed id becomes an empty string), the integer line number, and the log contexts it builds for a failure.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/specs.md
 *
 * @covers \OCA\Shillinq\Controller\InvoiceLineScope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InvoiceLineScope;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class InvoiceLineScopeTest extends TestCase {
	private function scope(array $params): InvoiceLineScope {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new InvoiceLineScope($request);
	}//end scope()

	public function testSanitisesIdsAndReadsTheLineNumber(): void {
		$scope = $this->scope(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'invoiceLineNumber' => '3']);
		$this->assertSame('adm-1', $scope->administrationId);
		$this->assertSame('inv-9', $scope->invoiceId);
		$this->assertSame(3, $scope->invoiceLineNumber);
	}//end testSanitisesIdsAndReadsTheLineNumber()

	public function testMalformedIdsBecomeEmpty(): void {
		$scope = $this->scope(['administrationId' => 'bad id!', 'invoiceId' => '']);
		$this->assertSame('', $scope->administrationId);
		$this->assertSame('', $scope->invoiceId);
		$this->assertSame(0, $scope->invoiceLineNumber);
	}//end testMalformedIdsBecomeEmpty()

	public function testLogContexts(): void {
		$scope = $this->scope(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'invoiceLineNumber' => '3']);
		$e = new \RuntimeException('boom');
		$this->assertSame(
			['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'exception' => 'boom'],
			$scope->invoiceLogContext($e)
		);
		$this->assertSame(
			['administrationId' => 'adm-1', 'invoiceId' => 'inv-9', 'invoiceLineNumber' => 3, 'exception' => 'boom'],
			$scope->lineLogContext($e)
		);
	}//end testLogContexts()
}//end class
