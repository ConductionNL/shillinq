<?php

/**
 * Unit tests for SalesInvoiceApprovalGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Lifecycle\SalesInvoiceApprovalGuard;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SIIC-003: above the threshold an invoice is approved by a second person first.
 *
 * @covers \OCA\Shillinq\Lifecycle\SalesInvoiceApprovalGuard
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class SalesInvoiceApprovalGuardTest extends TestCase {
	/**
	 * The guard as a user, over the seed scenario's administration and invoices.
	 *
	 * @param string|null          $uid       The caller, null for none.
	 * @param float|string|null    $threshold The administration's threshold.
	 * @param array<string, mixed> $invoice   Fields of the stored large invoice to replace.
	 *
	 * @return SalesInvoiceApprovalGuard The guard.
	 */
	private function guard(?string $uid, float|string|null $threshold = 10000.0, array $invoice = []): SalesInvoiceApprovalGuard {
		$store = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [['id' => 'adm-vd', 'name' => 'Adviesbureau Van Dijk', 'salesInvoiceApprovalThreshold' => $threshold]],
				'ARInvoice' => [
					array_merge(['id' => 'inv-gv', 'administrationId' => 'adm-vd', 'grossAmount' => 24200.0, 'lifecycleState' => 'draft', 'owner' => 'j.devries'], $invoice),
					['id' => 'inv-bj', 'administrationId' => 'adm-vd', 'grossAmount' => 1210.0, 'lifecycleState' => 'draft', 'owner' => 'j.devries'],
				],
			]
		);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SalesInvoiceApprovalGuard(objectService: $store, registerLocation: $location, userSession: $session);
	}//end guard()

	/**
	 * The invoice to Gemeente Voorbeeld, as the issue transition passes it.
	 *
	 * @return array<string, mixed>
	 */
	private function large(): array {
		return ['id' => 'inv-gv', 'administrationId' => 'adm-vd', 'grossAmount' => 24200.0, 'lifecycleState' => 'issued'];
	}//end large()

	/**
	 * A draft of EUR 24,200 above a threshold of EUR 10,000 cannot be issued.
	 *
	 * @return void
	 */
	public function testALargeDraftCannotBeIssued(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage(SalesInvoiceApprovalGuard::MESSAGE_NEEDS_APPROVAL);

		$this->guard(uid: 'j.devries')->canIssue(invoice: $this->large());
	}//end testALargeDraftCannotBeIssued()

	/**
	 * Once approved, the large invoice is issued.
	 *
	 * @return void
	 */
	public function testAnApprovedLargeInvoiceIsIssued(): void {
		$guard = $this->guard(uid: 'j.devries', invoice: ['lifecycleState' => 'approved']);

		$this->assertTrue($guard->canIssue(invoice: $this->large()));
	}//end testAnApprovedLargeInvoiceIsIssued()

	/**
	 * A draft of EUR 1,210 is issued without approval; so is any draft without a threshold.
	 *
	 * @return void
	 */
	public function testASmallDraftOrNoThresholdIssuesDirectly(): void {
		$small = ['id' => 'inv-bj', 'administrationId' => 'adm-vd', 'grossAmount' => 1210.0, 'lifecycleState' => 'issued'];
		$this->assertTrue($this->guard(uid: 'j.devries')->canIssue(invoice: $small));
		$this->assertTrue($this->guard(uid: 'j.devries', threshold: null)->canIssue(invoice: $this->large()));
		$this->assertTrue($this->guard(uid: 'j.devries', threshold: '')->canIssue(invoice: $this->large()));
		$this->assertTrue($this->guard(uid: 'j.devries', threshold: 24200.0)->canIssue(invoice: $this->large()));
	}//end testASmallDraftOrNoThresholdIssuesDirectly()

	/**
	 * An invoice whose administration cannot be read is not issued unchecked.
	 *
	 * @return void
	 */
	public function testAnUnreadableAdministrationRefusesTheIssue(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('its administration could not be read');

		$this->guard(uid: 'j.devries')->canIssue(invoice: ['id' => 'inv-gv', 'administrationId' => 'adm-gone', 'grossAmount' => 5.0]);
	}//end testAnUnreadableAdministrationRefusesTheIssue()

	/**
	 * The creator cannot approve their own invoice.
	 *
	 * @return void
	 */
	public function testTheCreatorCannotApprove(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage(SalesInvoiceApprovalGuard::MESSAGE_CREATOR);

		$this->guard(uid: 'j.devries')->canApprove(invoice: ['id' => 'inv-gv', 'lifecycleState' => 'approved']);
	}//end testTheCreatorCannotApprove()

	/**
	 * Someone else approves it.
	 *
	 * @return void
	 */
	public function testAnotherUserApproves(): void {
		$this->assertTrue($this->guard(uid: 'a.bakker')->canApprove(invoice: ['id' => 'inv-gv', 'lifecycleState' => 'approved']));
	}//end testAnotherUserApproves()

	/**
	 * No signed-in approver, or no known creator, refuses the approval.
	 *
	 * @return void
	 */
	public function testAnUnknownApproverOrCreatorIsRefused(): void {
		foreach ([[null, ['id' => 'inv-gv']], ['a.bakker', ['id' => 'inv-unknown']], ['a.bakker', []]] as [$uid, $invoice]) {
			try {
				$this->guard(uid: $uid)->canApprove(invoice: $invoice);
				$this->fail('An unidentified party must refuse the approval.');
			} catch (PostingRefusedException $e) {
				$this->assertSame(SalesInvoiceApprovalGuard::MESSAGE_UNKNOWN, $e->getMessage());
			}
		}

		$guard = $this->guard(uid: 'a.bakker', invoice: ['owner' => null]);
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage(SalesInvoiceApprovalGuard::MESSAGE_UNKNOWN);
		$guard->canApprove(invoice: ['id' => 'inv-gv']);
	}//end testAnUnknownApproverOrCreatorIsRefused()
}//end class
