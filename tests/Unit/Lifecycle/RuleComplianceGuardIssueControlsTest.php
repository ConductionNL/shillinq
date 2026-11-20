<?php

/**
 * RuleComplianceGuard on ARInvoice.issue under sales-invoice-issue-controls.
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

use OCA\Shillinq\Lifecycle\BalanceGuard;
use OCA\Shillinq\Lifecycle\LedgerPostingRegistration;
use OCA\Shillinq\Lifecycle\PostingRestrictionGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\RuleComplianceGuard;
use OCA\Shillinq\Lifecycle\SalesInvoiceApprovalGuard;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-SIIC-001 and REQ-SIIC-003 at the one `requires` of ARInvoice.issue.
 *
 * @covers \OCA\Shillinq\Lifecycle\RuleComplianceGuard
 * @uses   \OCA\Shillinq\Lifecycle\SalesInvoiceApprovalGuard
 * @uses   \OCA\Shillinq\Lifecycle\Action\AssignInvoiceNumberAction
 * @uses   \OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter
 * @uses   \OCA\Shillinq\Lifecycle\LedgerPostingRegistration
 * @uses   \OCA\Shillinq\Lifecycle\BalanceGuard
 * @uses   \OCA\Shillinq\Lifecycle\PostingRestrictionGuard
 * @uses   \OCA\Shillinq\Standards\RuleEngine
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class RuleComplianceGuardIssueControlsTest extends TestCase {
	/**
	 * The warnings the guard logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The guard over Adviesbureau Van Dijk (threshold EUR 10,000) and the given stored invoices.
	 *
	 * @param array<int, array<string, mixed>> $invoices The stored ARInvoice rows.
	 *
	 * @return RuleComplianceGuard The guard.
	 */
	private function guard(array $invoices): RuleComplianceGuard {
		$store = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [['id' => 'adm-vd', 'salesInvoiceApprovalThreshold' => 10000]],
				'ARInvoice' => $invoices,
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new RuleComplianceGuard(
			appConfig: $appConfig,
			logger: $logger,
			balanceGuard: new BalanceGuard($container, $appConfig, $this->createMock(LoggerInterface::class)),
			objectService: $store,
			restrictions: new PostingRestrictionGuard($appConfig, $store, $l10n),
			approvals: new SalesInvoiceApprovalGuard(objectService: $store, registerLocation: $location, userSession: $this->createMock(IUserSession::class))
		);
	}//end guard()

	/**
	 * An invoice of the given total, as the issue passes it.
	 *
	 * @param float                $net   The amount excluding VAT.
	 * @param array<string, mixed> $extra Fields to add.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(float $net, array $extra = []): array {
		return array_merge(
			[
				'id' => 'ar-1', 'administrationId' => 'adm-vd', 'invoiceDate' => '2026-06-30', 'currency' => 'EUR', 'customerId' => 'cust-1',
				'netAmount' => $net, 'vatAmount' => round($net * 0.21, 2), 'grossAmount' => round($net * 1.21, 2), 'lifecycleState' => 'issued',
			],
			$extra
		);
	}//end invoice()

	/**
	 * Pressing Issue on the EUR 24,200 draft is refused with the request to ask for approval.
	 *
	 * @return void
	 */
	public function testIssuingALargeDraftIsRefusedWithTheApprovalMessage(): void {
		$invoice = $this->invoice(net: 20000.0);
		$guard = $this->guard(invoices: [array_merge($invoice, ['lifecycleState' => 'draft'])]);
		[, $method, $message] = LedgerPostingRegistration::GUARDS['OCA\Shillinq\Lifecycle\RuleComplianceGuard::validateInvoice'];
		$adapter = new RegisterRequiresGuardAdapter($guard, $method, $message, $this->createMock(LoggerInterface::class));

		$result = $adapter->check($invoice, 'issue', 'j.devries');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(SalesInvoiceApprovalGuard::MESSAGE_NEEDS_APPROVAL, $result->getMessage());
	}//end testIssuingALargeDraftIsRefusedWithTheApprovalMessage()

	/**
	 * Once approved the same invoice reaches the rule check like any other.
	 *
	 * @return void
	 */
	public function testAnApprovedLargeInvoiceReachesTheRuleCheck(): void {
		$invoice = $this->invoice(net: 20000.0, extra: ['invoiceNumber' => '2026-0042']);

		$allowed = $this->guard(invoices: [array_merge($invoice, ['lifecycleState' => 'approved'])])->validateInvoice($invoice);

		// No approval refusal was thrown; the verdict is the rule engine's, which logged its findings.
		$this->assertFalse($allowed);
		$this->assertNotEmpty(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'en16931-br-07')));
	}//end testAnApprovedLargeInvoiceReachesTheRuleCheck()

	/**
	 * A draft without a number is judged as numbered: the issue's first action numbers it.
	 *
	 * @return void
	 */
	public function testADraftWithoutANumberIsJudgedAsNumbered(): void {
		$stored = [array_merge($this->invoice(net: 1000.0), ['lifecycleState' => 'draft'])];
		$withNumber = $this->guard(invoices: $stored)->validateInvoice($this->invoice(net: 1000.0, extra: ['invoiceNumber' => '2026-0042']));
		$numberedWarnings = $this->warnings;
		$this->warnings = [];

		$withoutNumber = $this->guard(invoices: $stored)->validateInvoice($this->invoice(net: 1000.0));

		$this->assertSame($withNumber, $withoutNumber);
		$this->assertNotEmpty($numberedWarnings);
		$this->assertSame($numberedWarnings, $this->warnings);
		$this->assertSame([], array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'br-02') || str_contains($w, 'art226-2')));
	}//end testADraftWithoutANumberIsJudgedAsNumbered()

	/**
	 * A self-billed invoice without the customer's number still fails the number rules.
	 *
	 * @return void
	 */
	public function testASelfBilledInvoiceWithoutANumberIsRefused(): void {
		$stored = [array_merge($this->invoice(net: 1000.0), ['lifecycleState' => 'draft'])];

		$allowed = $this->guard(invoices: $stored)->validateInvoice($this->invoice(net: 1000.0, extra: ['selfBilled' => true, 'selfBillingMention' => 'Factuur uitgereikt door afnemer']));

		$this->assertFalse($allowed);
		$this->assertNotEmpty(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'en16931-br-02')));
	}//end testASelfBilledInvoiceWithoutANumberIsRefused()
}//end class
