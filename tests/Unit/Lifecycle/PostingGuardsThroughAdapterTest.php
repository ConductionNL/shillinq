<?php

/**
 * The posting guards, driven the way OpenRegister drives them.
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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\AppInfo\LedgerPostingRegistration;
use OCA\Shillinq\Lifecycle\BalanceGuard;
use OCA\Shillinq\Lifecycle\JournalEntryGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Lifecycle\RuleComplianceGuard;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister calls a `requires` guard as `check($object, $action, $user)`
 * and RegisterRequiresGuardAdapter hands the OBJECT ARRAY to the wrapped
 * method. A method typed `string $id` throws a TypeError there, which the
 * adapter turns into a denial, so a correct, balanced document could never
 * be posted even once its tag was registered (#516, #1103). These tests go
 * through the adapter with the real guards.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PostingGuardsThroughAdapterTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * App config answering every key with its default.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * Wrap a guard method in the adapter exactly as LedgerPostingRegistration does.
	 *
	 * @param string $tag The literal requires tag.
	 * @param object $guard The guard instance.
	 *
	 * @return RegisterRequiresGuardAdapter
	 */
	private function adapter(string $tag, object $guard): RegisterRequiresGuardAdapter {
		[, $method, $message] = LedgerPostingRegistration::GUARDS[$tag];
		return new RegisterRequiresGuardAdapter($guard, $method, $message, $this->createMock(LoggerInterface::class));
	}//end adapter()

	/**
	 * A balanced BalanceGuard over the in-memory store.
	 *
	 * @return BalanceGuard
	 */
	private function balanceGuard(): BalanceGuard {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store->mock($this));
		return new BalanceGuard($container, $this->appConfig(), $this->createMock(LoggerInterface::class));
	}//end balanceGuard()

	/**
	 * Set up an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore();
	}//end setUp()

	/**
	 * APTransaction.issue: lines 1,500 plus tax 315 against a total of 1,815
	 * is issued; against 1,850 it is refused (REQ-BPR-001).
	 *
	 * @return void
	 */
	public function testAnApInvoiceIssuesOnlyWhenItsAmountsAddUp(): void {
		$tag = 'OCA\Shillinq\Lifecycle\BalanceGuard::isInvoiceBalanced';
		$adapter = $this->adapter($tag, $this->balanceGuard());
		$invoice = [
			'id' => 'apt-1',
			'invoiceNumber' => '2026-0412',
			'lines' => [['accountNumber' => '4500', 'amount' => 1000.0], ['accountNumber' => '4510', 'amount' => 500.0]],
			'taxAmount' => 315.0,
			'totalAmount' => 1815.0,
			'state' => 'issued',
		];

		self::assertTrue($adapter->check($invoice, 'issue', 'alice')->isAllowed());

		$invoice['totalAmount'] = 1850.0;
		$denied = $adapter->check($invoice, 'issue', 'alice');
		self::assertFalse($denied->isAllowed());
		self::assertStringContainsString('do not add up', (string)$denied->getMessage());
	}//end testAnApInvoiceIssuesOnlyWhenItsAmountsAddUp()

	/**
	 * JournalEntry.post: a balanced entry passes through the adapter.
	 *
	 * @return void
	 */
	public function testABalancedJournalEntryPassesThroughTheAdapter(): void {
		$guard = new JournalEntryGuard($this->appConfig(), $this->createMock(LoggerInterface::class), $this->store->mock($this));
		$adapter = $this->adapter('OCA\Shillinq\Lifecycle\JournalEntryGuard::canPost', $guard);

		$entry = [
			'id' => 'je-1',
			'lines' => [
				['accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0],
				['accountNumber' => '1100', 'side' => 'credit', 'amount' => 1200.0],
			],
		];
		self::assertTrue($adapter->check($entry, 'post', 'alice')->isAllowed());

		$entry['lines'][1]['amount'] = 1000.0;
		self::assertFalse($adapter->check($entry, 'post', 'alice')->isAllowed());
	}//end testABalancedJournalEntryPassesThroughTheAdapter()

	/**
	 * GLTransaction.post: a balanced, complete transaction passes; the lines
	 * are read from the store by the id in the object the adapter passes.
	 *
	 * @return void
	 */
	public function testABalancedGlTransactionPassesThroughTheAdapter(): void {
		$this->store->rows['GLLine'] = [
			['id' => 'l1', 'transactionId' => 'gl-1', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0],
			['id' => 'l2', 'transactionId' => 'gl-1', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 1200.0],
		];
		$guard = new RuleComplianceGuard(
			$this->appConfig(),
			$this->createMock(LoggerInterface::class),
			$this->balanceGuard(),
			$this->store->mock($this)
		);
		$adapter = $this->adapter('OCA\Shillinq\Lifecycle\RuleComplianceGuard::validateTransaction', $guard);

		$transaction = [
			'id' => 'gl-1',
			'transactionNumber' => 'MEM-2026-0001',
			'postingDate' => '2026-03-14',
			'sourceReference' => 'bank-statement-7',
			'administrationId' => 'adm-1',
			'state' => 'posted',
			// The mandatory GoBD and retention rules of LedgerIntegrityChecks.
			'postingLocked' => true,
			'integrityVerified' => true,
			'retentionUntil' => '2036-12-31',
			'auditTrail' => [['user' => 'alice', 'timestamp' => '2026-03-14T10:00:00+00:00', 'action' => 'post']],
		];
		self::assertTrue($adapter->check($transaction, 'post', 'alice')->isAllowed());

		$this->store->rows['GLLine'][1]['amount'] = 1000.0;
		self::assertFalse($adapter->check($transaction, 'post', 'alice')->isAllowed());
	}//end testABalancedGlTransactionPassesThroughTheAdapter()

	/**
	 * GLTransaction.post from the general ledger page: the page sends the
	 * draft with its state moved to posted and nothing else. The lock,
	 * retention, integrity and audit-trail fields are what the post itself
	 * gives the entry, so the guard judges the entry as the post leaves it
	 * (REQ-LPP-001). An unbalanced one stays refused.
	 *
	 * @return void
	 */
	public function testAMemorialEntryAsTheLedgerPageSendsItPosts(): void {
		$this->store->rows['GLLine'] = [
			['id' => 'l1', 'transactionId' => 'gl-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0],
			['id' => 'l2', 'transactionId' => 'gl-2', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 1200.0],
		];
		$guard = new RuleComplianceGuard(
			$this->appConfig(),
			$this->createMock(LoggerInterface::class),
			$this->balanceGuard(),
			$this->store->mock($this)
		);
		$adapter = $this->adapter('OCA\Shillinq\Lifecycle\RuleComplianceGuard::validateTransaction', $guard);

		$transaction = [
			'id' => 'gl-2',
			'transactionNumber' => 'MEM-2026-0002',
			'postingDate' => '2026-09-20',
			'description' => 'Huur september',
			'sourceReference' => 'manual',
			'administrationId' => 'adm-1',
			'state' => 'posted',
		];
		self::assertTrue($adapter->check($transaction, 'post', 'alice')->isAllowed());

		$this->store->rows['GLLine'][1]['amount'] = 1000.0;
		self::assertFalse($adapter->check($transaction, 'post', 'alice')->isAllowed());
	}//end testAMemorialEntryAsTheLedgerPageSendsItPosts()

	/**
	 * ARInvoice.issue: the invoice in the payload is evaluated as it stands.
	 * A total that is not net plus VAT is refused by that rule, not by a
	 * TypeError in the adapter.
	 *
	 * @return void
	 */
	public function testAnInvoiceIsEvaluatedFromThePayload(): void {
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$errors = 0;
		$logger->method('error')->willReturnCallback(
			static function () use (&$errors): void {
				$errors++;
			}
		);

		$guard = new RuleComplianceGuard($this->appConfig(), $logger, $this->balanceGuard(), $this->store->mock($this));

		$allowed = $guard->validateInvoice(
			[
				'id' => 'ar-1', 'invoiceNumber' => '2026-0042', 'invoiceDate' => '2026-06-30', 'currency' => 'EUR',
				'customerId' => 'cust-1', 'netAmount' => 1000.0, 'vatAmount' => 210.0, 'grossAmount' => 1250.0,
			]
		);

		self::assertFalse($allowed);
		self::assertSame(0, $errors, 'The array must be evaluated, not rejected by the fail-closed catch.');
		self::assertNotEmpty(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'en16931-br-co-15')));
	}//end testAnInvoiceIsEvaluatedFromThePayload()
}//end class
