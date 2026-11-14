<?php

/**
 * Unit tests for InvoiceNumberCheckController.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InvoiceNumberCheckController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\InvoiceNumberAuditService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SIIC-004: a controller checks a year's numbers of their own administration.
 *
 * @covers \OCA\Shillinq\Controller\InvoiceNumberCheckController
 * @uses   \OCA\Shillinq\Service\InvoiceNumberAuditService
 * @uses   \OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class InvoiceNumberCheckControllerTest extends TestCase {
	/**
	 * The controller over Adviesbureau Van Dijk's 2026 invoices 0001-0043 without 0017.
	 *
	 * @param array<string, string>  $params     The request parameters.
	 * @param string|null            $user       The caller, null for none.
	 * @param array<int, string>     $accessible The administrations the caller may read.
	 * @param string                 $active     The caller's active administration.
	 *
	 * @return InvoiceNumberCheckController The controller.
	 */
	private function controller(array $params = [], ?string $user = 'controller', array $accessible = ['adm-vd'], string $active = 'adm-vd'): InvoiceNumberCheckController {
		$invoices = [];
		for ($sequence = 1; $sequence <= 43; $sequence++) {
			if ($sequence !== 17) {
				$invoices[] = ['id' => 'inv-' . $sequence, 'administrationId' => 'adm-vd', 'invoiceNumber' => sprintf('2026-%04d', $sequence), 'lifecycleState' => 'issued'];
			}
		}

		$invoices[] = ['id' => 'inv-dup', 'administrationId' => 'adm-vd', 'invoiceNumber' => '2026-0005', 'lifecycleState' => 'paid'];
		$store = new InMemoryObjectServiceStub(data: ['ARInvoice' => $invoices]);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => $active]);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => in_array($administrationId, $accessible, true));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);

		return new InvoiceNumberCheckController(
			request: $request,
			audit: new InvoiceNumberAuditService(objectService: $store, registerLocation: $location),
			sequences: new InvoiceNumberSequenceService(objectService: $store, registerLocation: $location, locks: $this->createMock(ILockingProvider::class)),
			context: $context,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n
		);
	}//end controller()

	/**
	 * The controller opens the check for 2026: 2026-0017 is missing, 2026-0005 is used twice.
	 *
	 * @return void
	 */
	public function testTheCheckListsTheMissingAndDuplicateNumbers(): void {
		$response = $this->controller(params: ['year' => '2026'])->check();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('adm-vd', $data['administrationId']);
		$this->assertSame(2026, $data['year']);
		$this->assertSame(['2026-0017'], $data['missing']);
		$this->assertSame(['2026-0005'], $data['duplicates']);
		$this->assertSame([1, 1], [$data['missingCount'], $data['duplicateCount']]);
		$this->assertSame(
			[['number' => '2026-0017', 'finding' => 'nl:Missing'], ['number' => '2026-0005', 'finding' => 'nl:Duplicate']],
			$data['rows']
		);
	}//end testTheCheckListsTheMissingAndDuplicateNumbers()

	/**
	 * A year with no invoices reports nothing missing.
	 *
	 * @return void
	 */
	public function testAYearWithoutInvoicesReportsNothing(): void {
		$data = $this->controller(params: ['year' => '2024'])->check()->getData();

		$this->assertSame([[], [], []], [$data['missing'], $data['duplicates'], $data['rows']]);
	}//end testAYearWithoutInvoicesReportsNothing()

	/**
	 * An administration the caller is not a member of reads as absent.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationReadsAsAbsent(): void {
		$response = $this->controller(params: ['administrationId' => 'adm-vd'], accessible: ['adm-own'])->check();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testAnotherAdministrationReadsAsAbsent()

	/**
	 * No caller, no administration or a malformed year is refused.
	 *
	 * @return void
	 */
	public function testAMissingCallerAdministrationOrYearIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(user: null)->check()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(active: '')->check()->getStatus());

		$response = $this->controller(params: ['year' => '26'])->check();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The year must be four digits.'], $response->getData());
	}//end testAMissingCallerAdministrationOrYearIsRefused()
}//end class
