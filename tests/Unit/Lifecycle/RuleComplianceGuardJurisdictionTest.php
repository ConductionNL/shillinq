<?php

/**
 * RuleComplianceGuard jurisdiction tests (ledger-foreign-legislation REQ-LFL-002)
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BalanceGuard;
use OCA\Shillinq\Lifecycle\PostingRestrictionGuard;
use OCA\Shillinq\Lifecycle\RuleComplianceGuard;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The posting rule check follows the jurisdiction of the transaction's administration.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class RuleComplianceGuardJurisdictionTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * Set up a store with a Dutch, a Belgian and an American administration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore();
		$this->store->rows['Administration'] = [
			['id' => 'a-nl', 'administrationCode' => 'HVD', 'jurisdiction' => 'NL'],
			['id' => 'a-be', 'administrationCode' => 'VDS', 'jurisdiction' => 'BE'],
			['id' => 'a-us', 'administrationCode' => 'VDI', 'jurisdiction' => 'US'],
		];
		$this->store->rows['GLLine'] = [
			['id' => 'l1', 'transactionId' => 'gl-1', 'accountNumber' => '400000', 'side' => 'debit', 'amount' => 1210.0],
			['id' => 'l2', 'transactionId' => 'gl-1', 'accountNumber' => '700000', 'side' => 'credit', 'amount' => 1210.0],
		];
	}//end setUp()

	/**
	 * A Belgian transaction runs the Belgian rule that a Dutch one does not, and no Dutch-only rule.
	 *
	 * @return void
	 */
	public function testABelgianPostingIsCheckedAgainstBelgianRules(): void {
		$belgian = $this->violations(administrationId: 'VDS');
		$dutch = $this->violations(administrationId: 'HVD');

		// No transaction number: the Belgian no-blank rule (Wetboek economisch recht) is broken.
		$this->assertContains('be-wer-no-blank-no-alteration', $belgian);
		$this->assertNotContains('be-wer-no-blank-no-alteration', $dutch);
		$this->assertSame([], array_values(array_filter($belgian, static fn (string $id): bool => str_starts_with($id, 'nl-'))));
		// EU rules apply to both.
		$this->assertContains('eu-saft-oecd-audit-file', $belgian);
		$this->assertContains('eu-saft-oecd-audit-file', $dutch);
	}//end testABelgianPostingIsCheckedAgainstBelgianRules()

	/**
	 * An administration outside the EU drops the EU rules; one that names none, or is unknown, is Dutch.
	 *
	 * @return void
	 */
	public function testTheJurisdictionDecidesWhetherEuRulesApply(): void {
		$american = $this->violations(administrationId: 'VDI');
		$this->assertNotContains('eu-saft-oecd-audit-file', $american);
		$this->assertContains('us-irs-electronic-records', $american);

		$this->assertSame($this->violations(administrationId: 'HVD'), $this->violations(administrationId: 'unknown'));
	}//end testTheJurisdictionDecidesWhetherEuRulesApply()

	/**
	 * The rule ids the guard logs as violated for a posted transaction without a number.
	 *
	 * @param string $administrationId The transaction's administration.
	 *
	 * @return list<string>
	 */
	private function violations(string $administrationId): array {
		$ids = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message) use (&$ids): void {
					if (preg_match('/ violates (\S+) /', $message, $match) === 1) {
						$ids[] = $match[1];
					}
				}
			);
		}

		$guard = new RuleComplianceGuard($this->appConfig(), $logger, $this->balanceGuard(), $this->store->mock($this), $this->restrictions());
		$guard->validateTransaction(
			[
				'id' => 'gl-1',
				'postingDate' => '2026-03-14',
				'sourceReference' => 'VF-2026-0042',
				'administrationId' => $administrationId,
				'state' => 'posted',
			]
		);

		sort($ids);
		return $ids;
	}//end violations()

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
	 * A BalanceGuard over the in-memory store.
	 *
	 * @return BalanceGuard
	 */
	private function balanceGuard(): BalanceGuard {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store->mock($this));
		return new BalanceGuard($container, $this->appConfig(), $this->createMock(LoggerInterface::class));
	}//end balanceGuard()

	/**
	 * The booking-rules guard over the in-memory store.
	 *
	 * @return PostingRestrictionGuard
	 */
	private function restrictions(): PostingRestrictionGuard {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		return new PostingRestrictionGuard($this->appConfig(), $this->store->mock($this), $l10n);
	}//end restrictions()
}//end class
