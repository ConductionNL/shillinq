<?php

/**
 * Golden table for ObjectApiRequest::isPersonSaving.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Platform
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Platform;

use OCA\Shillinq\Service\Platform\ObjectApiRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only a create or update of this object of this schema through the object API counts.
 */
final class ObjectApiRequestGoldenTest extends TestCase {

	/**
	 * The verdict for one request.
	 *
	 * @param string       $method      The HTTP method.
	 * @param string       $uri         The request URI.
	 * @param list<string> $schemaNames The schema's names.
	 * @param string       $uuid        The object being saved.
	 *
	 * @return bool
	 */
	private function verdict(string $method, string $uri, array $schemaNames = ['SupplierInvoice', '41'], string $uuid = 'u-1'): bool {
		$request = $this->createStub(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);

		return (new ObjectApiRequest($request))->isPersonSaving(schemaNames: $schemaNames, uuid: $uuid);

	}//end verdict()

	/**
	 * The table.
	 *
	 * @return void
	 */
	public function testVerdictTable(): void {
		$base = '/index.php/apps/openregister/api/objects/shillinq';
		$this->assertSame(
			[true, true, true, true, true, true, true, false, false, false, false, false, false, false, false, true, false],
			[
				$this->verdict(method: 'POST', uri: $base.'/SupplierInvoice'),
				$this->verdict(method: 'post', uri: $base.'/supplierinvoice/'),
				$this->verdict(method: 'PUT', uri: $base.'/SupplierInvoice/u-1'),
				$this->verdict(method: 'PATCH', uri: $base.'/41/u-1?_extend=x'),
				$this->verdict(method: 'PUT', uri: $base.'/Supplier%20Invoice/u%2D1', schemaNames: ['supplier invoice']),
				$this->verdict(method: 'POST', uri: '/apps/openregister/api/objects/other/SupplierInvoice'),
				$this->verdict(method: 'PUT', uri: $base.'/SupplierInvoice/u-1/'),
				$this->verdict(method: 'GET', uri: $base.'/SupplierInvoice'),
				$this->verdict(method: 'DELETE', uri: $base.'/SupplierInvoice/u-1'),
				$this->verdict(method: 'PUT', uri: $base.'/SupplierInvoice/u-2'),
				$this->verdict(method: 'PUT', uri: $base.'/SupplierInvoice/u-1/lock'),
				$this->verdict(method: 'POST', uri: $base.'/ARInvoice'),
				$this->verdict(method: 'POST', uri: $base.'/SupplierInvoice', schemaNames: ['', 'Other']),
				$this->verdict(method: 'POST', uri: $base.'/SupplierInvoice', schemaNames: []),
				$this->verdict(method: 'POST', uri: '/apps/shillinq/api/objects/shillinq/SupplierInvoice'),
				$this->verdict(method: 'POST', uri: '?/apps/openregister/api/objects/shillinq/SupplierInvoice'),
				$this->verdict(method: 'POST', uri: ''),
			]
		);

	}//end testVerdictTable()

	/**
	 * A request that cannot be read is not a person's save.
	 *
	 * @return void
	 */
	public function testUnreadableRequestIsNotASave(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getMethod')->willThrowException(new RuntimeException('no request'));

		$this->assertFalse((new ObjectApiRequest($request))->isPersonSaving(schemaNames: ['SupplierInvoice'], uuid: 'u-1'));

	}//end testUnreadableRequestIsNotASave()
}//end class
