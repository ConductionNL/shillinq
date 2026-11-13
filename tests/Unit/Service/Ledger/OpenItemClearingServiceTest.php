<?php

/**
 * Unit tests for OpenItemClearingService.
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
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\Shillinq\Service\Ledger\OpenItemClearingRefusedException;
use OCA\Shillinq\Service\Ledger\OpenItemClearingService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Clearing, undoing, listing, suggesting and reopening open items.
 */
#[CoversClass(OpenItemClearingService::class)]
#[UsesClass(OpenItemClearingRefusedException::class)]
#[UsesClass(ObjectTransitionRunner::class)]
#[UsesClass(RegisterLocation::class)]
#[UsesClass(ObjectIdentifier::class)]
final class OpenItemClearingServiceTest extends TestCase {

	public const ADMIN = 'adm-1';
	public const OTHER_ADMIN = 'adm-2';

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the engine was asked to run, as [id, action, app].
	 *
	 * @var array<int, array<int, string>>
	 */
	public array $transitionsRun = [];

	/**
	 * The ledger of the scenarios: account 1950 Kruisposten with the savings transfer and a cash deposit.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function records(): array {
		$line = static fn (string $id, string $tx, string $account, string $side, float $amount, string $description, ?string $group = null): array => [
			'id'              => $id,
			'transactionId'   => $tx,
			'accountNumber'   => $account,
			'side'            => $side,
			'amount'          => $amount,
			'description'     => $description,
			'administrationId' => self::ADMIN,
			'clearingGroupId' => $group,
		];

		return [
			'Account'       => [
				['id' => 'acc-1950', 'accountNumber' => '1950', 'name' => 'Kruisposten', 'accountType' => 'assets', 'openItemManaged' => true, 'administrationId' => self::ADMIN],
				['id' => 'acc-1960', 'accountNumber' => '1960', 'name' => 'Tussenrekening', 'accountType' => 'assets', 'openItemManaged' => false, 'administrationId' => self::ADMIN],
				['id' => 'acc-8000', 'accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'openItemManaged' => true, 'administrationId' => self::ADMIN],
			],
			'GLTransaction' => [
				['id' => 'tx-out', 'state' => 'posted', 'postingDate' => '2026-09-03', 'sourceReference' => 'Spaaropdracht 0903', 'administrationId' => self::ADMIN],
				['id' => 'tx-in', 'state' => 'posted', 'postingDate' => '2026-09-04', 'sourceReference' => 'Spaaropdracht 0903', 'administrationId' => self::ADMIN],
				['id' => 'tx-cash', 'state' => 'posted', 'postingDate' => '2026-09-10', 'sourceReference' => 'Kasstorting 0910', 'administrationId' => self::ADMIN],
				['id' => 'tx-draft', 'state' => 'draft', 'postingDate' => '2026-09-11', 'administrationId' => self::ADMIN],
				['id' => 'tx-short', 'state' => 'posted', 'postingDate' => '2026-09-12', 'sourceReference' => 'Short', 'administrationId' => self::ADMIN],
				['id' => 'tx-foreign', 'state' => 'posted', 'postingDate' => '2026-09-12', 'administrationId' => self::OTHER_ADMIN],
				['id' => 'tx-old', 'state' => 'posted', 'postingDate' => '2026-08-01', 'administrationId' => self::ADMIN],
			],
			'GLLine'        => [
				$line('l-out', 'tx-out', '1950', 'debit', 5000.0, 'Spaaropdracht 0903'),
				$line('l-in', 'tx-in', '1950', 'credit', 5000.0, 'Spaaropdracht 0903'),
				$line('l-cash', 'tx-cash', '1950', 'debit', 250.0, 'Kasstorting 0910'),
				$line('l-draft', 'tx-draft', '1950', 'credit', 250.0, 'Kasstorting 0910'),
				$line('l-short', 'tx-short', '1950', 'credit', 4750.0, 'Short'),
				$line('l-1960', 'tx-short', '1960', 'debit', 4750.0, 'Short'),
				$line('l-8000', 'tx-short', '8000', 'credit', 10.0, 'Omzet'),
				$line('l-8000b', 'tx-out', '8000', 'debit', 10.0, 'Omzet'),
				$line('l-foreign', 'tx-foreign', '1950', 'credit', 5000.0, 'Spaaropdracht 0903'),
				$line('l-old-a', 'tx-old', '1950', 'debit', 100.0, 'Old', 'grp-old'),
				$line('l-old-b', 'tx-old', '1950', 'credit', 100.0, 'Old', 'grp-old'),
			],
			'ClearingGroup' => [
				['id' => 'grp-old', 'administrationId' => self::ADMIN, 'accountNumber' => '1950', 'lineIds' => ['l-old-a', 'l-old-b'], 'debitCents' => 10000, 'creditCents' => 10000, 'state' => 'cleared'],
				['id' => 'grp-short', 'administrationId' => self::ADMIN, 'accountNumber' => '1950', 'lineIds' => ['x', 'y'], 'debitCents' => 500000, 'creditCents' => 475000, 'state' => 'cleared'],
			],
		];

	}//end records()

	/**
	 * Build the store over the seed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->transitionsRun = [];
		$this->store = new InMemoryObjectServiceStub(self::records(), $this->saved, true);

	}//end setUp()

	/**
	 * The service over the store, with an engine that records and applies transitions.
	 *
	 * @return OpenItemClearingService
	 */
	private function service(): OpenItemClearingService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$store = $this->store;
		$test = $this;
		$engine = new class ($store, $test) {
			/**
			 * Constructor.
			 *
			 * @param InMemoryObjectServiceStub  $store The store.
			 * @param OpenItemClearingServiceTest $test  The test, which records the calls.
			 */
			public function __construct(private InMemoryObjectServiceStub $store, private OpenItemClearingServiceTest $test) {
			}

			/**
			 * Apply the reopen transition to the store.
			 *
			 * @param string               $id     The object.
			 * @param string               $action The transition.
			 * @param string               $app    The app running it.
			 * @param array<string, mixed> $data   Inputs.
			 *
			 * @return mixed
			 */
			public function transitionAsSystem(string $id, string $action, string $app, array $data=[]): mixed {
				$this->test->transitionsRun[] = [$id, $action, $app];

				return $this->store->patchObject(objectId: $id, data: ['state' => 'reopened'], schema: 'ClearingGroup');
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);

		return new OpenItemClearingService(
			$this->store,
			new RegisterLocation($appConfig, $this->createMock(IAppManager::class)),
			new ObjectTransitionRunner($container)
		);

	}//end service()

	/**
	 * A record as stored now.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stored(string $schema, string $id): ?array {
		return $this->store->find(id: $id, schema: $schema)?->getObject();

	}//end stored()

	/**
	 * Assert a clearing is refused with the given template, and nothing was written.
	 *
	 * @param array<int, string> $lineIds  The lines.
	 * @param string             $template The expected template.
	 *
	 * @return OpenItemClearingRefusedException
	 */
	private function assertRefused(array $lineIds, string $template): OpenItemClearingRefusedException {
		try {
			$this->service()->clear($lineIds, 'bookkeeper');
		} catch (OpenItemClearingRefusedException $e) {
			$this->assertSame($template, $e->getTemplate());
			$this->assertSame([], $this->saved, 'A refused clearing writes nothing.');

			return $e;
		}

		$this->fail('The clearing was not refused.');

	}//end assertRefused()

	/**
	 * Clearing the savings transfer writes one group with balance zero and points both lines at it.
	 *
	 * @return void
	 */
	public function testClearingTheSavingsTransferWritesOneGroupAndLinksBothLines(): void {
		$group = $this->service()->clear(['l-out', 'l-in'], 'bookkeeper', 'Spaaropdracht 0903');

		$this->assertSame(self::ADMIN, $group['administrationId']);
		$this->assertSame('1950', $group['accountNumber']);
		$this->assertSame(['l-out', 'l-in'], $group['lineIds']);
		$this->assertSame([500000, 500000, 0], [$group['debitCents'], $group['creditCents'], $group['balanceCents']]);
		$this->assertSame(['bookkeeper', 'cleared', 'Spaaropdracht 0903'], [$group['clearedBy'], $group['state'], $group['reason']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $group['clearedAt']);

		$stored = $this->stored('ClearingGroup', $group['id']);
		$this->assertNotNull($stored);
		$this->assertArrayNotHasKey('balanceCents', $stored, 'balanceCents is left to the declared calculation.');
		$this->assertSame($group['id'], $this->stored('GLLine', 'l-out')['clearingGroupId']);
		$this->assertSame($group['id'], $this->stored('GLLine', 'l-in')['clearingGroupId']);
		$this->assertSame(['ClearingGroup', 'GLLine', 'GLLine'], array_column($this->saved, 'schema'));

	}//end testClearingTheSavingsTransferWritesOneGroupAndLinksBothLines()

	/**
	 * A group that does not net to zero is allowed and carries its balance; an empty reason is stored as null.
	 *
	 * @return void
	 */
	public function testAGroupThatDoesNotNetToZeroKeepsItsBalance(): void {
		$group = $this->service()->clear(['l-out', 'l-short'], 'bookkeeper');

		$this->assertSame(25000, $group['balanceCents']);
		$this->assertNull($group['reason']);

	}//end testAGroupThatDoesNotNetToZeroKeepsItsBalance()

	/**
	 * An unposted line is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnpostedLineIsRefused(): void {
		$e = $this->assertRefused(['l-cash', 'l-draft'], 'Line %1$s is not posted and cannot be cleared.');

		$this->assertSame(['Kasstorting 0910'], $e->getParameters());
		$this->assertSame('Line Kasstorting 0910 is not posted and cannot be cleared.', $e->getMessage());

	}//end testAnUnpostedLineIsRefused()

	/**
	 * Lines on two accounts are refused.
	 *
	 * @return void
	 */
	public function testLinesOnDifferentAccountsAreRefused(): void {
		$this->assertRefused(['l-out', 'l-1960'], 'Lines on different accounts cannot be cleared together.');

	}//end testLinesOnDifferentAccountsAreRefused()

	/**
	 * A line already in a group is refused.
	 *
	 * @return void
	 */
	public function testALineAlreadyInAGroupIsRefused(): void {
		$e = $this->assertRefused(['l-out', 'l-old-b'], 'Line %1$s is already cleared in another group.');

		$this->assertSame(['Old'], $e->getParameters());

	}//end testALineAlreadyInAGroupIsRefused()

	/**
	 * Lines of two administrations are refused.
	 *
	 * @return void
	 */
	public function testLinesOfDifferentAdministrationsAreRefused(): void {
		$this->assertRefused(['l-out', 'l-foreign'], 'Lines of different administrations cannot be cleared together.');

	}//end testLinesOfDifferentAdministrationsAreRefused()

	/**
	 * An account not kept by open item, or not a balance account, is refused.
	 *
	 * @return void
	 */
	public function testAnAccountNotKeptByOpenItemIsRefused(): void {
		$this->store->saveObject(object: ['id' => 'l-1960b', 'transactionId' => 'tx-in', 'accountNumber' => '1960', 'side' => 'credit', 'amount' => 4750.0], schema: 'GLLine');
		$this->saved = [];
		$e = $this->assertRefused(['l-1960', 'l-1960b'], 'Account %1$s is not a balance account kept by open item.');
		$this->assertSame(['1960'], $e->getParameters());

		$e = $this->assertRefused(['l-8000', 'l-8000b'], 'Account %1$s is not a balance account kept by open item.');
		$this->assertSame(['8000'], $e->getParameters());

	}//end testAnAccountNotKeptByOpenItemIsRefused()

	/**
	 * One line, or none, is refused.
	 *
	 * @return void
	 */
	public function testFewerThanTwoLinesAreRefused(): void {
		$this->assertRefused(['l-out'], 'Select at least two lines to clear.');
		$this->assertRefused(['l-out', 'l-out', ''], 'Select at least two lines to clear.');
		$this->assertRefused([], 'Select at least two lines to clear.');

	}//end testFewerThanTwoLinesAreRefused()

	/**
	 * More lines than one clearing may hold are refused before anything is read.
	 *
	 * @return void
	 */
	public function testTooManyLinesAreRefused(): void {
		$ids = array_map(static fn (int $i): string => 'line-' . $i, range(1, (OpenItemClearingService::MAX_LINES_PER_CLEARING + 1)));

		$e = $this->assertRefused($ids, 'A clearing holds at most %1$s lines.');
		$this->assertSame(['200'], $e->getParameters());

	}//end testTooManyLinesAreRefused()

	/**
	 * An unknown line is a miss, not a refusal.
	 *
	 * @return void
	 */
	public function testAnUnknownLineIsNotFound(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('Ledger line not found');

		$this->service()->administrationOfLines(['l-out', 'nope']);

	}//end testAnUnknownLineIsNotFound()

	/**
	 * The administration of lines is their transaction's.
	 *
	 * @return void
	 */
	public function testTheAdministrationOfLinesIsTheirTransactions(): void {
		$this->store->saveObject(object: ['id' => 'l-mislabelled', 'transactionId' => 'tx-foreign', 'accountNumber' => '1950', 'side' => 'debit', 'amount' => 1.0, 'administrationId' => self::ADMIN], schema: 'GLLine');

		$this->assertSame(self::ADMIN, $this->service()->administrationOfLines(['l-out', 'l-in']));
		$this->assertSame(self::OTHER_ADMIN, $this->service()->administrationOfLines(['l-mislabelled']));

	}//end testTheAdministrationOfLinesIsTheirTransactions()

	/**
	 * Undo opens the group's lines again and removes the group.
	 *
	 * @return void
	 */
	public function testUndoOpensTheLinesAgainAndRemovesTheGroup(): void {
		$service = $this->service();
		$result = $service->undo($service->findGroup('grp-old'));

		$this->assertSame(['groupId' => 'grp-old', 'releasedLineIds' => ['l-old-a', 'l-old-b']], $result);
		$this->assertNull($this->stored('GLLine', 'l-old-a')['clearingGroupId']);
		$this->assertNull($this->stored('GLLine', 'l-old-b')['clearingGroupId']);
		$this->assertNull($this->stored('ClearingGroup', 'grp-old'));

	}//end testUndoOpensTheLinesAgainAndRemovesTheGroup()

	/**
	 * Undo leaves alone a line that points at another group.
	 *
	 * @return void
	 */
	public function testUndoLeavesALineOfAnotherGroupAlone(): void {
		$this->store->patchObject(objectId: 'l-old-b', data: ['clearingGroupId' => 'grp-other'], schema: 'GLLine');
		$service = $this->service();

		$result = $service->undo($service->findGroup('grp-old'));

		$this->assertSame(['l-old-a'], $result['releasedLineIds']);
		$this->assertSame('grp-other', $this->stored('GLLine', 'l-old-b')['clearingGroupId']);

	}//end testUndoLeavesALineOfAnotherGroupAlone()

	/**
	 * An unknown group or account is a miss.
	 *
	 * @return void
	 */
	public function testAnUnknownGroupOrAccountIsNotFound(): void {
		try {
			$this->service()->findGroup('nope');
			$this->fail('An unknown group was found.');
		} catch (OutOfBoundsException $e) {
			$this->assertSame('Clearing group not found', $e->getMessage());
		}

		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('Account not found');
		$this->service()->findAccount('');

	}//end testAnUnknownGroupOrAccountIsNotFound()

	/**
	 * The overview lists the uncleared posted lines, the groups not netting to zero and the pair.
	 *
	 * @return void
	 */
	public function testTheOverviewListsUnclearedLinesShortGroupsAndThePair(): void {
		$service = $this->service();
		$overview = $service->overview($service->findAccount('acc-1950'));

		$this->assertSame('1950', $overview['accountNumber']);
		$this->assertTrue($overview['openItemManaged']);
		$this->assertSame(['l-out', 'l-in', 'l-cash', 'l-short'], array_column($overview['uncleared'], 'id'));
		$this->assertSame(
			['id' => 'l-out', 'date' => '2026-09-03', 'description' => 'Spaaropdracht 0903', 'side' => 'debit', 'amount' => 5000.0, 'reference' => 'Spaaropdracht 0903'],
			$overview['uncleared'][0]
		);
		$this->assertSame(['grp-short'], array_column($overview['openGroups'], 'id'));
		$this->assertSame(25000, $overview['openGroups'][0]['balanceCents']);
		$this->assertSame(250.0, $overview['openGroups'][0]['balance']);
		$this->assertSame([['l-out', 'l-in']], array_column($overview['suggestions'], 'lineIds'));

	}//end testTheOverviewListsUnclearedLinesShortGroupsAndThePair()

	/**
	 * A reopened group is listed even when it nets to zero.
	 *
	 * @return void
	 */
	public function testAReopenedGroupIsListed(): void {
		$this->store->patchObject(objectId: 'grp-old', data: ['state' => 'reopened'], schema: 'ClearingGroup');
		$service = $this->service();

		$overview = $service->overview($service->findAccount('acc-1950'));

		$this->assertSame(['grp-old', 'grp-short'], array_column($overview['openGroups'], 'id'));
		$this->assertSame(0, $overview['openGroups'][0]['balanceCents']);

	}//end testAReopenedGroupIsListed()

	/**
	 * The suggestion pairs the two EUR 5,000 lines and clears nothing.
	 *
	 * @return void
	 */
	public function testTheSuggestionPairsTheTransferAndClearsNothing(): void {
		$service = $this->service();
		$pairs = $service->suggest($service->findAccount('acc-1950'));

		$this->assertSame(
			[
				[
					'lineIds'      => ['l-out', 'l-in'],
					'debitLineId'  => 'l-out',
					'creditLineId' => 'l-in',
					'amount'       => 5000.0,
					'reference'    => 'Spaaropdracht 0903',
					'debitDate'    => '2026-09-03',
					'creditDate'   => '2026-09-04',
				],
			],
			$pairs
		);
		$this->assertSame([], $this->saved);
		$this->assertNull($this->stored('GLLine', 'l-out')['clearingGroupId']);

	}//end testTheSuggestionPairsTheTransferAndClearsNothing()

	/**
	 * Equal amounts without a shared reference, or on the same side, or a different amount, are no pair.
	 *
	 * @return void
	 */
	public function testNonPairsAreNotSuggested(): void {
		$this->store->patchObject(objectId: 'l-in', data: ['description' => 'Something else'], schema: 'GLLine');
		$this->store->patchObject(objectId: 'tx-in', data: ['sourceReference' => 'Other'], schema: 'GLTransaction');
		$service = $this->service();

		$this->assertSame([], $service->suggest($service->findAccount('acc-1950')), 'No shared reference.');

		$this->store->patchObject(objectId: 'l-in', data: ['description' => 'Spaaropdracht 0903', 'amount' => 4999.99], schema: 'GLLine');
		$this->assertSame([], $this->service()->suggest($service->findAccount('acc-1950')), 'A different amount.');

		$this->store->patchObject(objectId: 'l-in', data: ['amount' => 5000.0, 'side' => 'debit'], schema: 'GLLine');
		$this->assertSame([], $this->service()->suggest($service->findAccount('acc-1950')), 'The same side.');

	}//end testNonPairsAreNotSuggested()

	/**
	 * The transaction's source reference is a shared reference too.
	 *
	 * @return void
	 */
	public function testTheSourceReferenceIsASharedReference(): void {
		$this->store->patchObject(objectId: 'l-in', data: ['description' => 'Arrival'], schema: 'GLLine');
		$service = $this->service();

		$pairs = $service->suggest($service->findAccount('acc-1950'));

		$this->assertSame('Spaaropdracht 0903', $pairs[0]['reference']);
		$this->assertSame(['l-out', 'l-in'], $pairs[0]['lineIds']);

	}//end testTheSourceReferenceIsASharedReference()

	/**
	 * A reversal reopens the cleared group of the transaction's line through the reopen transition.
	 *
	 * @return void
	 */
	public function testAReversalReopensTheGroupOfItsLine(): void {
		$reopened = $this->service()->reopenForTransaction('tx-old');

		$this->assertSame(['grp-old'], $reopened);
		$this->assertSame([['grp-old', 'reopen', 'shillinq']], $this->transitionsRun);
		$group = $this->stored('ClearingGroup', 'grp-old');
		$this->assertSame('reopened', $group['state']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $group['reopenedAt']);

	}//end testAReversalReopensTheGroupOfItsLine()

	/**
	 * A transaction without cleared lines, an already reopened group and an empty id reopen nothing.
	 *
	 * @return void
	 */
	public function testNothingIsReopenedWithoutAClearedGroup(): void {
		$this->assertSame([], $this->service()->reopenForTransaction('tx-out'));
		$this->assertSame([], $this->service()->reopenForTransaction(''));

		$this->store->patchObject(objectId: 'grp-old', data: ['state' => 'reopened'], schema: 'ClearingGroup');
		$this->assertSame([], $this->service()->reopenForTransaction('tx-old'));
		$this->assertSame([], $this->transitionsRun);

	}//end testNothingIsReopenedWithoutAClearedGroup()
}//end class
