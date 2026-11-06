<?php

/**
 * Unit tests for FinancialDocumentAuthoring.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ContributionDebtorResolver;
use OCA\Shillinq\Service\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentAuthoring;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * New quote, credit invoice and raise payout on a host object.
 */
final class FinancialDocumentAuthoringTest extends TestCase {
	private const CASE_ID = '7d9a2c4e-1b3f-4a5d-8e6f-0a1b2c3d4e5f';

	private const LINK = ['type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => self::CASE_ID];

	private InMemoryObjectServiceStub $store;

	/**
	 * The payout commands dispatched.
	 *
	 * @var array<int, PayableRaisedEvent>
	 */
	private array $raised = [];

	/**
	 * The service for a caller holding the given actions.
	 *
	 * @param array<int, string> $actions The actions the caller's group carries.
	 * @param bool $member Whether the caller works in adm-1.
	 * @param string|null $payoutRefusal What finance refuses a payout with, or null.
	 *
	 * @return FinancialDocumentAuthoring
	 */
	private function authoring(array $actions, bool $member = true, ?string $payoutRefusal = null): FinancialDocumentAuthoring {
		$this->store = new InMemoryObjectServiceStub(data: [
			'ARInvoice' => [
				['id' => 'i-1', 'invoiceNumber' => 'F-1', 'invoiceType' => 'standard', 'lifecycleState' => 'issued', 'customerId' => 'cm-1', 'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 121.0, 'netAmount' => 100.0, 'vatAmount' => 21.0, 'linkedObjects' => [self::LINK],
					'invoiceLines' => [['lineId' => '1', 'quantity' => 1, 'itemName' => 'Leges', 'netPrice' => 100.0, 'netAmount' => 100.0, 'vatCategory' => 'S', 'vatRate' => 21.0]]],
				['id' => 'i-2', 'invoiceNumber' => 'F-2', 'invoiceType' => 'standard', 'lifecycleState' => 'draft', 'administrationId' => 'adm-1', 'grossAmount' => 10.0],
				['id' => 'c-1', 'invoiceNumber' => 'C-1', 'invoiceType' => 'credit-note', 'lifecycleState' => 'issued', 'administrationId' => 'adm-1', 'grossAmount' => -10.0],
			],
			'CustomerMaster' => [['id' => 'cm-1', 'customerId' => 'K-1', 'email' => 'j.jansen@example.nl', 'administrationId' => 'adm-1']],
		]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($actions): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return (string)json_encode(array_fill_keys($actions, ['finance']));
				}

				return ($key === 'register') ? 'shillinq' : $default;
			}
		);
		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canAccess')->willReturn($member);
		$administrations->method('defaultAdministrationIdForUser')->willReturn('adm-1');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($payoutRefusal): void {
				$this->raised[] = $event;
				if ($payoutRefusal !== null) {
					$event->refuse($payoutRefusal);
					return;
				}

				$event->accept('payable-1', 'requested');
			}
		);

		return new FinancialDocumentAuthoring(
			objectService: $this->store,
			appConfig: $appConfig,
			guard: new FinancialDocumentGuard(
				objectService: $this->store,
				appConfig: $appConfig,
				authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
				administrations: $administrations,
			),
			debtors: new ContributionDebtorResolver(objectService: $this->store, dispatcher: $this->createMock(IEventDispatcher::class), appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)),
			dispatcher: $dispatcher,
		);
	}//end authoring()

	/**
	 * The objects saved in one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedIn(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save['object'], array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)));
	}//end savedIn()

	/**
	 * The status of the refusal an act throws.
	 *
	 * @param callable $act The act.
	 *
	 * @return int The status.
	 */
	private static function refusal(callable $act): int {
		try {
			$act();
		} catch (FinancialDocumentActionRefusedException $e) {
			return $e->getStatus();
		}

		self::fail('the act was not refused');
	}//end refusal()

	public function testANewQuoteIsADraftLinkedToTheObjectWithOneLine(): void {
		$quote = $this->authoring(['quote.create'])->createQuote('dossiq', 'case', self::CASE_ID, ['description' => 'Herinrichting plein', 'amount' => 4500, 'debtor' => ['customerMasterId' => 'cm-1'], 'objectType' => 'case']);

		$quotes = $this->savedIn('Quote');
		self::assertCount(1, $quotes);
		self::assertSame('draft', $quotes[0]['status']);
		self::assertSame('cm-1', $quotes[0]['customerReference']);
		self::assertSame('adm-1', $quotes[0]['administrationId']);
		self::assertSame([self::LINK], $quotes[0]['linkedObjects']);
		self::assertMatchesRegularExpression('/^OFF-\d{4}-[0-9A-F]{8}$/', $quotes[0]['quoteNumber']);
		$lines = $this->savedIn('QuoteLine');
		self::assertCount(1, $lines);
		unset($lines[0]['id']);
		self::assertSame(['quote' => $quote['id'], 'lineNumber' => 1, 'description' => 'Herinrichting plein', 'quantity' => 1, 'unitPrice' => 4500.0], $lines[0]);
	}//end testANewQuoteIsADraftLinkedToTheObjectWithOneLine()

	public function testCreditingAnInvoiceIssuesTheNegativeOfItOnTheSameObjects(): void {
		$credit = $this->authoring(['invoice.credit'])->creditInvoice(invoiceId: 'i-1');

		$saved = $this->savedIn('ARInvoice');
		self::assertCount(1, $saved);
		self::assertSame('credit-note', $saved[0]['invoiceType']);
		self::assertSame('issued', $saved[0]['lifecycleState']);
		self::assertSame(-121.0, $saved[0]['grossAmount']);
		self::assertSame(-100.0, $saved[0]['netAmount']);
		self::assertSame(-21.0, $saved[0]['vatAmount']);
		self::assertSame(['F-1'], $saved[0]['precedingInvoiceReferences']);
		self::assertSame('cm-1', $saved[0]['customerId']);
		self::assertSame([self::LINK], $saved[0]['linkedObjects']);
		self::assertSame(-100.0, $saved[0]['invoiceLines'][0]['netAmount']);
		self::assertMatchesRegularExpression('/^CRN-\d{4}-[0-9A-F]{8}$/', $saved[0]['invoiceNumber']);
		self::assertNotSame('', $credit['id']);
		self::assertNotSame('i-1', $saved[0]['id'] ?? '', 'a credit note is a new invoice, never the original saved again');
	}//end testCreditingAnInvoiceIssuesTheNegativeOfItOnTheSameObjects()

	public function testRaisingAPayoutHandsItToFinanceLinkedToTheObject(): void {
		$payout = $this->authoring(['payable.raise'])->raisePayout('dossiq', 'case', self::CASE_ID, ['amount' => 357, 'payeeName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'description' => 'Schadevergoeding', 'objectType' => 'case']);

		self::assertCount(1, $this->raised);
		$command = $this->raised[0]->toArray();
		self::assertSame('shillinq', $command['sourceApp']);
		self::assertSame(357.0, $command['amount']);
		self::assertSame(['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300'], $command['payee']);
		self::assertSame([self::LINK], $command['linkedObjects']);
		self::assertSame('adm-1', $command['administrationId']);
		self::assertSame(['payableId' => 'payable-1', 'state' => 'requested'], $payout);
	}//end testRaisingAPayoutHandsItToFinanceLinkedToTheObject()

	public function testEachActIsRefusedBeforeItWrites(): void {
		$quote = ['description' => 'x', 'amount' => 10, 'debtor' => ['customerMasterId' => 'cm-1']];
		$payout = ['amount' => 10, 'payeeName' => 'X', 'iban' => 'NL91ABNA0417164300'];
		$cases = [
			'quote without the action' => [[], true, static fn ($a) => $a->createQuote('dossiq', 'case', self::CASE_ID, $quote), 403],
			'quote outside the administration' => [['quote.create'], false, static fn ($a) => $a->createQuote('dossiq', 'case', self::CASE_ID, $quote), 403],
			'quote without an amount' => [['quote.create'], true, static fn ($a) => $a->createQuote('dossiq', 'case', self::CASE_ID, ['amount' => 0] + $quote), 400],
			'credit without the action' => [[], true, static fn ($a) => $a->creditInvoice(invoiceId: 'i-1'), 403],
			'credit of a draft' => [['invoice.credit'], true, static fn ($a) => $a->creditInvoice(invoiceId: 'i-2'), 409],
			'credit of a credit note' => [['invoice.credit'], true, static fn ($a) => $a->creditInvoice(invoiceId: 'c-1'), 409],
			'credit of an unknown invoice' => [['invoice.credit'], true, static fn ($a) => $a->creditInvoice(invoiceId: 'nope'), 404],
			'payout without the action' => [[], true, static fn ($a) => $a->raisePayout('dossiq', 'case', self::CASE_ID, $payout), 403],
			'payout outside the administration' => [['payable.raise'], false, static fn ($a) => $a->raisePayout('dossiq', 'case', self::CASE_ID, $payout), 403],
		];
		foreach ($cases as $label => [$actions, $member, $act, $status]) {
			$this->raised = [];
			$authoring = $this->authoring($actions, $member);
			self::assertSame($status, self::refusal(static fn () => $act($authoring)), $label);
			self::assertSame([], $this->store->saved, $label . ' wrote something');
			self::assertSame([], $this->raised, $label . ' raised something');
		}
	}//end testEachActIsRefusedBeforeItWrites()

	public function testAPayoutFinanceRefusesIsARefusal(): void {
		$authoring = $this->authoring(['payable.raise'], payoutRefusal: 'The payee\'s IBAN is not valid.');

		self::assertSame(400, self::refusal(static fn () => $authoring->raisePayout('dossiq', 'case', self::CASE_ID, ['amount' => 10, 'payeeName' => 'X', 'iban' => 'NL00'])));
	}//end testAPayoutFinanceRefusesIsARefusal()
}//end class
