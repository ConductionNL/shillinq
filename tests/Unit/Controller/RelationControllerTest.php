<?php

/**
 * Tests for RelationController (reporting-relation-both-sides).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RelationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use OCA\Shillinq\Tests\Unit\Service\Relation\RelationFixture;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every endpoint checks the administration first, and the role decides the sides.
 */
class RelationControllerTest extends TestCase {

	/**
	 * A controller for a caller with the given role in the Van Wijk administration.
	 *
	 * @param string                $role   The membership role.
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return RelationController The controller.
	 */
	private function controller(string $role, array $params = []): RelationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('petra');
		$context->method('buildContext')->willReturn(
			[
				'activeAdministrationId' => RelationFixture::ADM,
				'administrations' => [['administrationId' => RelationFixture::ADM, 'role' => $role]],
			]
		);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === RelationFixture::ADM);
		$records = RelationFixture::records($this);

		return new RelationController($request, $context, new RelationLinkService($records), new RelationBothSidesService($records));
	}

	/**
	 * A bookkeeper sees both sides; the supplier page shows the same relation.
	 *
	 * @return void
	 */
	public function testBothSidesFromTheCustomerAndTheSupplierPage(): void {
		$params = ['from' => '2026-01-01', 'to' => '2026-12-31'];
		$fromCustomer = $this->controller('boekhouder', $params)->bothSides('c-zuid');
		$fromSupplier = $this->controller('boekhouder', $params)->payeeBothSides('p-zuid');

		$this->assertSame(-1452.0, $fromCustomer->getData()['totals']['net']);
		$this->assertSame($fromCustomer->getData(), $fromSupplier->getData());
		$this->assertSame(['linked' => false], $this->controller('boekhouder')->payeeBothSides('p-west')->getData());
	}

	/**
	 * An accounts receivable administrator does not see the purchase side.
	 *
	 * @return void
	 */
	public function testAReceivablesRoleSeesOnlyTheSalesSide(): void {
		$data = $this->controller('debiteurenadmin', ['from' => '2026-01-01', 'to' => '2026-12-31T23:59:59'])->bothSides('c-zuid')->getData();

		$this->assertTrue($data['received']['restricted']);
		$this->assertNull($data['totals']['openPayable']);
		$this->assertSame(1210.0, $data['totals']['openReceivable']);
	}

	/**
	 * Another administration and a customer outside it are masked as absent.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('boekhouder', ['administrationId' => 'adm-other'])->bothSides('c-zuid')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('boekhouder')->bothSides('c-elsewhere')->getStatus());
	}

	/**
	 * The report as JSON and as a CSV download.
	 *
	 * @return void
	 */
	public function testTheReportAndItsCsv(): void {
		$json = $this->controller('controller', ['from' => '2026-01-01', 'to' => '2026-12-31'])->report();
		$csv = $this->controller('controller', ['from' => '2026-01-01', 'to' => '2026-12-31', 'format' => 'csv'])->report();

		$this->assertSame(['Reclamebureau Zuid B.V.'], array_column($json->getData()['relations'], 'name'));
		$this->assertInstanceOf(DataDownloadResponse::class, $csv);
		$this->assertStringContainsString('Reclamebureau Zuid B.V.,5445.00,2662.00,1210.00,2662.00,-1452.00', $csv->render());
	}

	/**
	 * Suggestions, a confirmed link, a refused second link, a dismissal and an unlink.
	 *
	 * @return void
	 */
	public function testSuggestAndLink(): void {
		$this->assertSame('p-noord', $this->controller('boekhouder')->suggestions()->getData()['suggestions'][0]['payeeId']);

		$linked = $this->controller('boekhouder', ['customerId' => 'c-noord', 'payeeId' => 'p-noord', 'matchedOn' => 'vat'])->link();
		$this->assertSame(Http::STATUS_OK, $linked->getStatus());
		$this->assertSame('petra', $linked->getData()['payeeLink']['confirmedBy']);

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller('boekhouder', ['customerId' => 'c-oost', 'payeeId' => 'p-zuid'])->link()->getStatus());
		$this->assertSame(['dismissed' => true], $this->controller('boekhouder', ['customerId' => 'c-noord', 'payeeId' => 'p-noord'])->dismiss()->getData());
		$this->assertSame(['unlinked' => true], $this->controller('boekhouder')->unlink('c-zuid')->getData());
	}

	/**
	 * A viewer may read but not link.
	 *
	 * @return void
	 */
	public function testAViewerMayNotLink(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('inkijker', ['customerId' => 'c-noord', 'payeeId' => 'p-noord'])->link()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('inkijker', ['customerId' => 'c-noord', 'payeeId' => 'p-noord'])->dismiss()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('inkijker')->unlink('c-zuid')->getStatus());
	}
}
