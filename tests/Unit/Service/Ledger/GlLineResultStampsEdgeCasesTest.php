<?php

/**
 * Unit tests for the edges of GlLineResultStamps: unknown and empty
 * transactions, lines without an id or already stamped, the exact patch it
 * writes, the per-administration account-type cache and its fallbacks, which
 * transactions count in the result, and the reversal walk.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the result stamps on GL lines.
 *
 * @covers \OCA\Shillinq\Service\Ledger\GlLineResultStamps
 * @uses   \OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class GlLineResultStampsEdgeCasesTest extends TestCase {

	private const ADMIN = 'adm-1';

	private const TX = '7d1e2f3a-0000-4000-8000-000000000001';

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The filters of every Account lookup.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $accountLookups = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Build a store over the given records that records its Account lookups.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $records The records per schema.
	 *
	 * @return void
	 */
	private function seed(array $records): void {
		$this->saved = [];
		$this->accountLookups = [];
		$lookups = &$this->accountLookups;
		$this->store = new class($records, $this->saved, $lookups) extends InMemoryObjectServiceStub {
			/**
			 * The schema findAll() reads next.
			 *
			 * @var string
			 */
			private string $current = '';

			/**
			 * Where Account lookups are recorded.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $lookups;

			/**
			 * @param array<string, mixed>              $records The records.
			 * @param array<int, mixed>                 $saved   The save sink.
			 * @param array<int, array<string, mixed>> $lookups The lookup sink.
			 */
			public function __construct(array $records, array &$saved, array &$lookups) {
				parent::__construct($records, $saved, true);
				$this->lookups = &$lookups;
			}

			/**
			 * @param string|int $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string|int $schema): static {
				$this->current = (string)$schema;
				return parent::setSchema($schema);
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->current === 'Account') {
					$this->lookups[] = ($config['filters'] ?? []);
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}
		};

	}//end seed()

	/**
	 * The service under test.
	 *
	 * @return GlLineResultStamps
	 */
	private function stamps(): GlLineResultStamps {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new GlLineResultStamps($this->store, $settings);

	}//end stamps()

	/**
	 * The objects saved to one schema, in order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedTo(string $schema): array {
		return array_values(
			array_map(
				static fn (array $write): array => $write['object'],
				array_filter($this->saved, static fn (array $write): bool => $write['schema'] === $schema)
			)
		);

	}//end savedTo()

	/**
	 * A posted transaction with the given extra fields.
	 *
	 * @param string               $id    The id.
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function tx(string $id, array $extra = []): array {
		return array_merge(['id' => $id, 'state' => 'posted', 'administrationId' => self::ADMIN], $extra);

	}//end tx()

	/**
	 * An unknown or empty transaction id stamps nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownOrEmptyTransactionStampsNothing(): void {
		$this->seed(['GLLine' => [['id' => 'l1', 'transactionId' => 'missing', 'accountNumber' => '4000']]]);

		$this->assertSame(0, $this->stamps()->stampTransaction('missing'));
		$this->assertSame(0, $this->stamps()->stampTransaction(''));
		$this->assertFalse($this->stamps()->stampLine(['id' => 'l1', 'transactionId' => 'missing']));
		$this->assertFalse($this->stamps()->stampLine(['id' => 'l1']));
		$this->assertSame([], $this->saved);

	}//end testAnUnknownOrEmptyTransactionStampsNothing()

	/**
	 * The patch adds the two stamps to the line's id and changes nothing else; a line without an id is skipped.
	 *
	 * @return void
	 */
	public function testThePatchAddsOnlyTheStampsAndSkipsALineWithoutAnId(): void {
		$this->seed(
			[
				'Account'       => [['id' => 'a', 'accountNumber' => '8000', 'accountType' => 'revenue', 'administrationId' => self::ADMIN]],
				'GLTransaction' => [self::tx(self::TX)],
				'GLLine'        => [
					['id' => 'l1', 'transactionId' => self::TX, 'accountNumber' => '8000', 'amount' => 10.0],
					['transactionId' => self::TX, 'accountNumber' => '8000'],
				],
			]
		);

		$this->assertSame(1, $this->stamps()->stampTransaction(self::TX));
		// The store merges a patch into the stored line, so the line comes back
		// whole with the two stamps added and nothing else changed.
		$this->assertEquals(
			[['id' => 'l1', 'transactionId' => self::TX, 'accountNumber' => '8000', 'amount' => 10.0, 'accountClass' => 'pnl', 'countsInResult' => true]],
			$this->savedTo('GLLine')
		);
		$this->assertFalse($this->stamps()->stampLine(['transactionId' => self::TX, 'accountNumber' => '8000']));

	}//end testThePatchAddsOnlyTheStampsAndSkipsALineWithoutAnId()

	/**
	 * A line whose class matches but whose count flag differs is patched; one matching both is not.
	 *
	 * @return void
	 */
	public function testOnlyALineWithAStaleStampIsPatched(): void {
		$this->seed(
			[
				'Account'       => [['id' => 'a', 'accountNumber' => '1000', 'accountType' => 'assets', 'administrationId' => self::ADMIN]],
				'GLTransaction' => [self::tx(self::TX)],
				'GLLine'        => [
					['id' => 'ok', 'transactionId' => self::TX, 'accountNumber' => '1000', 'accountClass' => 'balance', 'countsInResult' => true],
					['id' => 'stale', 'transactionId' => self::TX, 'accountNumber' => '1000', 'accountClass' => 'balance', 'countsInResult' => false],
				],
			]
		);

		$this->assertSame(1, $this->stamps()->stampTransaction(self::TX));
		$written = $this->savedTo('GLLine');
		$this->assertCount(1, $written);
		$this->assertSame(['stale', 'balance', true], [$written[0]['id'], $written[0]['accountClass'], $written[0]['countsInResult']]);

	}//end testOnlyALineWithAStaleStampIsPatched()

	/**
	 * Each administration and account number is looked up once per service, scoped to the transaction's administration.
	 *
	 * @return void
	 */
	public function testTheAccountTypeIsLookedUpOncePerAdministrationAndNumber(): void {
		$this->seed(
			[
				'Account'       => [
					['id' => 'a', 'accountNumber' => '4000', 'accountType' => 'expenses', 'administrationId' => self::ADMIN],
					['id' => 'b', 'accountNumber' => '4000', 'accountType' => 'assets', 'administrationId' => 'other'],
				],
				'GLTransaction' => [self::tx(self::TX)],
				'GLLine'        => [
					['id' => 'l1', 'transactionId' => self::TX, 'accountNumber' => '4000'],
					['id' => 'l2', 'transactionId' => self::TX, 'accountNumber' => '4000', 'administrationId' => 'other'],
					['id' => 'l3', 'transactionId' => self::TX, 'accountNumber' => '4000'],
				],
			]
		);

		$this->assertSame(3, $this->stamps()->stampTransaction(self::TX));
		$this->assertSame([['accountNumber' => '4000', 'administrationId' => self::ADMIN]], $this->accountLookups);
		$this->assertSame(['pnl', 'pnl', 'pnl'], array_column($this->savedTo('GLLine'), 'accountClass'));

	}//end testTheAccountTypeIsLookedUpOncePerAdministrationAndNumber()

	/**
	 * Without an administration on the transaction the line's is used; with neither the lookup is by number alone.
	 *
	 * @return void
	 */
	public function testTheAdministrationFallsBackToTheLineAndThenToNone(): void {
		$this->seed(
			[
				'Account'       => [['id' => 'a', 'accountNumber' => '8000', 'accountType' => 'revenue', 'administrationId' => 'line-adm']],
				'GLTransaction' => [['id' => self::TX, 'state' => 'posted']],
			]
		);
		$stamps = $this->stamps();

		$this->assertTrue($stamps->stampLine(['id' => 'l1', 'transactionId' => self::TX, 'accountNumber' => '8000', 'administrationId' => 'line-adm']));
		$this->assertTrue($stamps->stampLine(['id' => 'l2', 'transactionId' => self::TX, 'accountNumber' => '8000']));

		$this->assertSame(
			[['accountNumber' => '8000', 'administrationId' => 'line-adm'], ['accountNumber' => '8000']],
			$this->accountLookups
		);
		$this->assertSame(['pnl', 'pnl'], array_column($this->savedTo('GLLine'), 'accountClass'));

	}//end testTheAdministrationFallsBackToTheLineAndThenToNone()

	/**
	 * An account that cannot be found makes the line a balance line.
	 *
	 * @return void
	 */
	public function testAnUnknownAccountIsABalanceLine(): void {
		$this->seed(['GLTransaction' => [self::tx(self::TX)]]);

		$this->assertTrue($this->stamps()->stampLine(['id' => 'l1', 'transactionId' => self::TX, 'accountNumber' => '9999']));
		$this->assertSame(['balance', true], [$this->savedTo('GLLine')[0]['accountClass'], $this->savedTo('GLLine')[0]['countsInResult']]);

	}//end testAnUnknownAccountIsABalanceLine()

	/**
	 * Only a posted transaction counts; a reversal counts unless the original it reverses was reversed.
	 *
	 * @return void
	 */
	public function testWhichTransactionsCountInTheResult(): void {
		$this->seed(
			[
				'GLTransaction' => [
					self::tx('orig-posted'),
					self::tx('orig-reversed', ['state' => 'reversed']),
				],
			]
		);
		$stamps = $this->stamps();

		$this->assertFalse($stamps->countsInResult(['state' => 'reversed']));
		$this->assertFalse($stamps->countsInResult([]));
		$this->assertTrue($stamps->countsInResult(['state' => 'posted']));
		$this->assertTrue($stamps->countsInResult(['state' => 'posted', 'reversesTransactionId' => '']));
		$this->assertTrue($stamps->countsInResult(['state' => 'posted', 'reversesTransactionId' => 'orig-posted']));
		$this->assertTrue($stamps->countsInResult(['state' => 'posted', 'reversesTransactionId' => 'gone']));
		$this->assertFalse($stamps->countsInResult(['state' => 'posted', 'reversesTransactionId' => 'orig-reversed']));

	}//end testWhichTransactionsCountInTheResult()

	/**
	 * Stamping a reversal also restamps the transaction it reverses: both leave the result.
	 *
	 * @return void
	 */
	public function testAReversalAlsoRestampsTheTransactionItReverses(): void {
		$this->seed(
			[
				'Account'       => [['id' => 'a', 'accountNumber' => '4000', 'accountType' => 'expenses', 'administrationId' => self::ADMIN]],
				'GLTransaction' => [
					self::tx('orig', ['state' => 'reversed']),
					self::tx('rev', ['reversesTransactionId' => 'orig']),
				],
				'GLLine'        => [
					['id' => 'o1', 'transactionId' => 'orig', 'accountNumber' => '4000', 'accountClass' => 'pnl', 'countsInResult' => true],
					['id' => 'r1', 'transactionId' => 'rev', 'accountNumber' => '4000'],
				],
			]
		);

		$this->assertSame(2, $this->stamps()->stampReversal('rev'));
		$this->assertSame([['r1', false], ['o1', false]], array_map(static fn (array $o): array => [$o['id'], $o['countsInResult']], $this->savedTo('GLLine')));

		$this->saved = [];
		$this->assertSame(0, $this->stamps()->stampReversal('nothing-here'));
		$this->assertSame([], $this->saved);

	}//end testAReversalAlsoRestampsTheTransactionItReverses()
}//end class
