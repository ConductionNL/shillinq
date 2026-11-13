<?php

/**
 * Open Item Clearing Service
 *
 * Clears posted lines of one open-item balance account against each other
 * (afletteren), undoes a clearing, lists what is still open on the account and
 * suggests exact pairs (ledger-open-item-clearing design D1, D3, D4).
 *
 * A clearing is a ClearingGroup naming its lines; each line points back at the
 * group through clearingGroupId. The group carries the debit and credit totals
 * in cents, and its balanceCents is calculated from them by OpenRegister, so a
 * group that does not net to zero is a plain filter.
 *
 * The service checks what a line is: posted, on one open-item balance account,
 * in one administration, not in a group yet. Whether the caller may touch that
 * administration is the controller's check, made before any read or write here
 * (administrationOfLines() and findGroup() tell it which administration).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ledger
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

namespace OCA\Shillinq\Service\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OutOfBoundsException;

/**
 * Clears, undoes, lists and suggests open items on a balance account.
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 */
class OpenItemClearingService {
	use ReadsSourceRowsInBatches;

	/**
	 * Account types that are balance accounts and so can be kept by open item.
	 */
	public const BALANCE_ACCOUNT_TYPES = ['assets', 'liabilities', 'equity'];

	/**
	 * The most lines one clearing may hold, so one request stays bounded.
	 */
	public const MAX_LINES_PER_CLEARING = 200;

	/**
	 * Lines read in this call chain, by the id they were asked for.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * Transactions read in this call chain, by id; null when absent.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $transactions = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister object service.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param ObjectTransitionRunner $transitions      Runs the group's reopen transition.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ObjectTransitionRunner $transitions,
	) {

	}//end __construct()

	/**
	 * The account by its uuid.
	 *
	 * @param string $accountId The Account uuid.
	 *
	 * @return array<string, mixed> The account with its `id`.
	 *
	 * @throws OutOfBoundsException When there is no such account.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-001)
	 */
	public function findAccount(string $accountId): array {
		$account = $this->record(schema: 'Account', id: $accountId);
		if ($account === null) {
			throw new OutOfBoundsException('Account not found');
		}

		return $account;

	}//end findAccount()

	/**
	 * What is open on an account: its uncleared posted lines, its groups that
	 * do not net to zero or were reopened, and the suggested pairs.
	 *
	 * @param array<string, mixed> $account The account, as findAccount() returns it.
	 *
	 * @return array<string, mixed> `uncleared`, `openGroups` and `suggestions`.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-001, REQ-LOIC-003, REQ-LOIC-004)
	 */
	public function overview(array $account): array {
		$uncleared = $this->unclearedLines(account: $account);

		return [
			'accountNumber'   => (string)($account['accountNumber'] ?? ''),
			'openItemManaged' => (($account['openItemManaged'] ?? false) === true),
			'uncleared'       => $uncleared,
			'openGroups'      => $this->openGroups(account: $account),
			'suggestions'     => $this->pairs(lines: $uncleared),
		];

	}//end overview()

	/**
	 * Pairs of uncleared posted lines on the account with equal amounts, opposite
	 * sides and the same reference. Nothing is cleared.
	 *
	 * @param array<string, mixed> $account The account, as findAccount() returns it.
	 *
	 * @return array<int, array<string, mixed>> The pairs.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-004)
	 */
	public function suggest(array $account): array {
		return $this->pairs(lines: $this->unclearedLines(account: $account));

	}//end suggest()

	/**
	 * The one administration the lines belong to.
	 *
	 * @param array<int, mixed> $lineIds The GLLine ids.
	 *
	 * @return string The administration id.
	 *
	 * @throws OutOfBoundsException             When a line does not exist.
	 * @throws OpenItemClearingRefusedException When no lines are given or they span administrations.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function administrationOfLines(array $lineIds): string {
		$ids = $this->lineIds(lineIds: $lineIds);
		$administrations = [];
		foreach ($ids as $id) {
			$administrations[$this->lineAdministration(line: $this->line(lineId: $id))] = true;
		}

		if (count($administrations) !== 1) {
			throw new OpenItemClearingRefusedException('Lines of different administrations cannot be cleared together.');
		}

		return (string)array_key_first($administrations);

	}//end administrationOfLines()

	/**
	 * Clear the lines into one group.
	 *
	 * @param array<int, mixed> $lineIds The GLLine ids.
	 * @param string            $actor   The user who clears them.
	 * @param string            $reason  Why, optional.
	 *
	 * @return array<string, mixed> The saved ClearingGroup with its `id` and `balanceCents`.
	 *
	 * @throws OutOfBoundsException             When a line does not exist.
	 * @throws OpenItemClearingRefusedException When the lines may not be cleared together.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function clear(array $lineIds, string $actor, string $reason=''): array {
		$ids = $this->lineIds(lineIds: $lineIds);
		if (count($ids) < 2) {
			throw new OpenItemClearingRefusedException('Select at least two lines to clear.');
		}

		$administrationId = $this->administrationOfLines(lineIds: $ids);
		$accountNumbers = [];
		$debit = 0;
		$credit = 0;
		foreach ($ids as $id) {
			$line = $this->line(lineId: $id);
			$this->assertClearable(line: $line);
			$accountNumbers[(string)($line['accountNumber'] ?? '')] = true;
			if ((string)($line['side'] ?? '') === 'credit') {
				$credit += self::cents(amount: $line['amount'] ?? 0);
				continue;
			}

			$debit += self::cents(amount: $line['amount'] ?? 0);
		}

		if (count($accountNumbers) !== 1) {
			throw new OpenItemClearingRefusedException('Lines on different accounts cannot be cleared together.');
		}

		$accountNumber = (string)array_key_first($accountNumbers);
		$this->assertOpenItemAccount(administrationId: $administrationId, accountNumber: $accountNumber);

		$reasonValue = null;
		if ($reason !== '') {
			$reasonValue = $reason;
		}

		$group = [
			'administrationId' => $administrationId,
			'accountNumber'    => $accountNumber,
			'lineIds'          => $ids,
			'debitCents'       => $debit,
			'creditCents'      => $credit,
			'clearedBy'        => $actor,
			'clearedAt'        => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
			'reason'           => $reasonValue,
			'state'            => 'cleared',
		];
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ClearingGroup')->saveObject($group, _rbac: false, _multitenancy: false));
		$groupId = (string)($saved['id'] ?? '');
		if ($groupId === '') {
			throw new OpenItemClearingRefusedException('The clearing could not be saved.');
		}

		foreach ($ids as $id) {
			$this->patchLine(lineId: $id, groupId: $groupId);
		}

		return array_merge($group, ['id' => $groupId, 'balanceCents' => ($debit - $credit)]);

	}//end clear()

	/**
	 * The group by its uuid.
	 *
	 * @param string $groupId The ClearingGroup uuid.
	 *
	 * @return array<string, mixed> The group with its `id`.
	 *
	 * @throws OutOfBoundsException When there is no such group.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function findGroup(string $groupId): array {
		$group = $this->record(schema: 'ClearingGroup', id: $groupId);
		if ($group === null) {
			throw new OutOfBoundsException('Clearing group not found');
		}

		return $group;

	}//end findGroup()

	/**
	 * Undo a clearing: its lines are open again and the group is removed.
	 *
	 * A line that meanwhile points at another group is left alone.
	 *
	 * @param array<string, mixed> $group The group, as findGroup() returns it.
	 *
	 * @return array<string, mixed> `groupId` and the `releasedLineIds`.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function undo(array $group): array {
		$groupId = (string)($group['id'] ?? '');
		$released = [];
		foreach (($group['lineIds'] ?? []) as $lineId) {
			$line = $this->record(schema: 'GLLine', id: (string)$lineId);
			if ($line === null || (string)($line['clearingGroupId'] ?? '') !== $groupId) {
				continue;
			}

			$this->patchLine(lineId: (string)$line['id'], groupId: null);
			$released[] = (string)$line['id'];
		}

		$this->objectService->deleteObject(
			uuid: $groupId,
			register: $this->registerLocation->getRegisterSlug(),
			schema: 'ClearingGroup',
			_rbac: false,
			_multitenancy: false
		);

		return ['groupId' => $groupId, 'releasedLineIds' => $released];

	}//end undo()

	/**
	 * Reopen every cleared group that holds a line of a reversed transaction.
	 *
	 * @param string $transactionId The GLTransaction that moved to reversed.
	 *
	 * @return array<int, string> The ids of the groups reopened.
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/design.md (D4)
	 */
	public function reopenForTransaction(string $transactionId): array {
		if ($transactionId === '') {
			return [];
		}

		$groupIds = [];
		foreach ($this->rows(schema: 'GLLine', filters: ['transactionId' => $transactionId]) as $row) {
			$groupId = (string)($this->rowPayload(row: $row)['clearingGroupId'] ?? '');
			if ($groupId !== '') {
				$groupIds[$groupId] = true;
			}
		}

		$reopened = [];
		foreach (array_keys($groupIds) as $groupId) {
			$group = $this->record(schema: 'ClearingGroup', id: (string)$groupId);
			if ($group === null || (string)($group['state'] ?? '') !== 'cleared') {
				continue;
			}

			$this->transitions->runAsSystem(objectId: (string)$groupId, action: 'reopen');
			$this->objectService->patchObject(
				objectId: (string)$groupId,
				data: ['reopenedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)],
				register: $this->registerLocation->getRegisterSlug(),
				schema: 'ClearingGroup',
				_rbac: false,
				_multitenancy: false
			);
			$reopened[] = (string)$groupId;
		}

		return $reopened;

	}//end reopenForTransaction()

	/**
	 * Refuse a line that is not posted or already in a group.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return void
	 *
	 * @throws OpenItemClearingRefusedException When the line cannot be cleared.
	 */
	private function assertClearable(array $line): void {
		$label = self::lineLabel(line: $line);
		$transaction = $this->transaction(transactionId: (string)($line['transactionId'] ?? ''));
		if ($transaction === null || (string)($transaction['state'] ?? '') !== 'posted') {
			throw new OpenItemClearingRefusedException('Line %1$s is not posted and cannot be cleared.', [$label]);
		}

		if ((string)($line['clearingGroupId'] ?? '') !== '') {
			throw new OpenItemClearingRefusedException('Line %1$s is already cleared in another group.', [$label]);
		}

	}//end assertClearable()

	/**
	 * Refuse an account that is not a balance account kept by open item.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber    The account number.
	 *
	 * @return void
	 *
	 * @throws OpenItemClearingRefusedException When the account is not kept by open item.
	 */
	private function assertOpenItemAccount(string $administrationId, string $accountNumber): void {
		$rows = $this->rows(schema: 'Account', filters: ['administrationId' => $administrationId, 'accountNumber' => $accountNumber]);
		$account = [];
		if ($rows !== []) {
			$account = $this->rowPayload(row: $rows[0]);
		}

		if (($account['openItemManaged'] ?? false) !== true
			|| in_array((string)($account['accountType'] ?? ''), self::BALANCE_ACCOUNT_TYPES, true) === false
		) {
			throw new OpenItemClearingRefusedException('Account %1$s is not a balance account kept by open item.', [$accountNumber]);
		}

	}//end assertOpenItemAccount()

	/**
	 * The uncleared posted lines of an account, oldest first.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array<int, array<string, mixed>> Rows with id, date, description, side, amount, reference.
	 */
	private function unclearedLines(array $account): array {
		$filters = ['accountNumber' => (string)($account['accountNumber'] ?? '')];
		$administrationId = (string)($account['administrationId'] ?? '');
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$lines = [];
		foreach ($this->rows(schema: 'GLLine', filters: $filters) as $row) {
			$line = $this->rowPayload(row: $row);
			$line['id'] = ObjectIdentifier::resolve(saved: $row);
			if ($line['id'] === '' || (string)($line['clearingGroupId'] ?? '') !== '') {
				continue;
			}

			$transaction = $this->transaction(transactionId: (string)($line['transactionId'] ?? ''));
			if ($transaction === null || (string)($transaction['state'] ?? '') !== 'posted') {
				continue;
			}

			// The transaction's administration is the source of truth; a line
			// whose denormalised copy disagrees belongs to another ledger.
			if ($administrationId !== '' && $this->lineAdministration(line: $line) !== $administrationId) {
				continue;
			}

			$lines[] = [
				'id'          => $line['id'],
				'date'        => (string)($transaction['postingDate'] ?? ''),
				'description' => (string)($line['description'] ?? ''),
				'side'        => (string)($line['side'] ?? ''),
				'amount'      => (float)($line['amount'] ?? 0),
				'reference'   => (string)($transaction['sourceReference'] ?? ''),
			];
		}

		usort($lines, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

		return $lines;

	}//end unclearedLines()

	/**
	 * The account's groups that do not net to zero or were reopened.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array<int, array<string, mixed>> The groups, each with its balanceCents and balance in euros.
	 */
	private function openGroups(array $account): array {
		$filters = ['accountNumber' => (string)($account['accountNumber'] ?? '')];
		$administrationId = (string)($account['administrationId'] ?? '');
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$groups = [];
		foreach ($this->rows(schema: 'ClearingGroup', filters: $filters) as $row) {
			$group = $this->rowPayload(row: $row);
			$group['id'] = ObjectIdentifier::resolve(saved: $row);
			$group['balanceCents'] = ((int)($group['debitCents'] ?? 0) - (int)($group['creditCents'] ?? 0));
			if ($group['balanceCents'] === 0 && (string)($group['state'] ?? '') !== 'reopened') {
				continue;
			}

			$group['balance'] = ($group['balanceCents'] / 100.0);

			$groups[] = $group;
		}

		return $groups;

	}//end openGroups()

	/**
	 * Exact pairs among uncleared lines: equal amount, opposite side, same reference.
	 *
	 * Each line is used in one pair at most, the earliest match first.
	 *
	 * @param array<int, array<string, mixed>> $lines Uncleared lines, as unclearedLines() returns them.
	 *
	 * @return array<int, array<string, mixed>> The pairs.
	 */
	private function pairs(array $lines): array {
		$used = [];
		$pairs = [];
		foreach ($lines as $debit) {
			if ($debit['side'] !== 'debit') {
				continue;
			}

			foreach ($lines as $credit) {
				if ($credit['side'] !== 'credit' || isset($used[$credit['id']]) === true) {
					continue;
				}

				if (self::cents(amount: $debit['amount']) !== self::cents(amount: $credit['amount'])) {
					continue;
				}

				$reference = self::sharedReference(debit: $debit, credit: $credit);
				if ($reference === '') {
					continue;
				}

				$used[$credit['id']] = true;
				$pairs[] = [
					'lineIds'         => [$debit['id'], $credit['id']],
					'debitLineId'     => $debit['id'],
					'creditLineId'    => $credit['id'],
					'amount'          => $debit['amount'],
					'reference'       => $reference,
					'debitDate'       => $debit['date'],
					'creditDate'      => $credit['date'],
				];
				break;
			}
		}

		return $pairs;

	}//end pairs()

	/**
	 * The reference two lines share: the line description, else the transaction's source reference.
	 *
	 * @param array<string, mixed> $debit  The debit line.
	 * @param array<string, mixed> $credit The credit line.
	 *
	 * @return string The shared reference, '' when they share none.
	 */
	private static function sharedReference(array $debit, array $credit): string {
		foreach (['description', 'reference'] as $key) {
			$one = trim((string)$debit[$key]);
			if ($one !== '' && strcasecmp($one, trim((string)$credit[$key])) === 0) {
				return $one;
			}
		}

		return '';

	}//end sharedReference()

	/**
	 * The distinct, non-empty line ids of a request, bounded.
	 *
	 * @param array<int, mixed> $lineIds The ids as given.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @throws OpenItemClearingRefusedException When none are given or too many.
	 */
	private function lineIds(array $lineIds): array {
		$ids = [];
		foreach ($lineIds as $id) {
			if (is_string($id) === true && trim($id) !== '') {
				$ids[trim($id)] = true;
			}
		}

		if ($ids === []) {
			throw new OpenItemClearingRefusedException('Select at least two lines to clear.');
		}

		if (count($ids) > self::MAX_LINES_PER_CLEARING) {
			throw new OpenItemClearingRefusedException('A clearing holds at most %1$s lines.', [(string)self::MAX_LINES_PER_CLEARING]);
		}

		return array_keys($ids);

	}//end lineIds()

	/**
	 * One line by id, read once per call chain.
	 *
	 * @param string $lineId The GLLine id.
	 *
	 * @return array<string, mixed> The line with its `id`.
	 *
	 * @throws OutOfBoundsException When the line does not exist.
	 */
	private function line(string $lineId): array {
		if (isset($this->lines[$lineId]) === false) {
			$line = $this->record(schema: 'GLLine', id: $lineId);
			if ($line === null) {
				throw new OutOfBoundsException('Ledger line not found');
			}

			$this->lines[$lineId] = $line;
		}

		return $this->lines[$lineId];

	}//end line()

	/**
	 * The administration of a line: its transaction's, which is the source of truth.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return string The administration id, '' when neither says.
	 */
	private function lineAdministration(array $line): string {
		$transaction = $this->transaction(transactionId: (string)($line['transactionId'] ?? ''));
		$administrationId = (string)($transaction['administrationId'] ?? '');
		if ($administrationId !== '') {
			return $administrationId;
		}

		return (string)($line['administrationId'] ?? '');

	}//end lineAdministration()

	/**
	 * One transaction by id, read once per call chain; null when absent.
	 *
	 * @param string $transactionId The GLTransaction id.
	 *
	 * @return array<string, mixed>|null The transaction.
	 */
	private function transaction(string $transactionId): ?array {
		if ($transactionId === '') {
			return null;
		}

		if (array_key_exists($transactionId, $this->transactions) === false) {
			$this->transactions[$transactionId] = $this->record(schema: 'GLTransaction', id: $transactionId);
		}

		return $this->transactions[$transactionId];

	}//end transaction()

	/**
	 * Point a line at a group, or at none.
	 *
	 * @param string      $lineId  The GLLine id.
	 * @param string|null $groupId The group, null to open the line again.
	 *
	 * @return void
	 */
	private function patchLine(string $lineId, ?string $groupId): void {
		$this->objectService->patchObject(
			objectId: $lineId,
			data: ['clearingGroupId' => $groupId],
			register: $this->registerLocation->getRegisterSlug(),
			schema: 'GLLine',
			_rbac: false,
			_multitenancy: false
		);
		unset($this->lines[$lineId]);

	}//end patchLine()

	/**
	 * One record of a schema by uuid, null when absent.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null The record with its `id`.
	 */
	private function record(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$found = ObjectIdentifier::findOne(
			scoped: $this->scoped(schema: $schema),
			id: $id,
			scope: ['_rbac' => false, '_multitenancy' => false]
		);
		if ($found === null) {
			return null;
		}

		if ((string)($found['id'] ?? '') === '') {
			$found['id'] = $id;
		}

		return $found;

	}//end record()

	/**
	 * Every row of a schema matching the filters, read in batches.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rows(string $schema, array $filters): array {
		return $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $this->registerLocation->getRegisterSlug(),
			schema: $schema,
			filters: $filters
		);

	}//end rows()

	/**
	 * The object service set to the register and a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);

	}//end scoped()

	/**
	 * An amount in cents.
	 *
	 * @param mixed $amount The amount in euros.
	 *
	 * @return int
	 */
	private static function cents(mixed $amount): int {
		return (int)round(((float)$amount) * 100);

	}//end cents()

	/**
	 * How a refusal names a line: its description, else its id.
	 *
	 * @param array<string, mixed> $line The line.
	 *
	 * @return string
	 */
	private static function lineLabel(array $line): string {
		$description = trim((string)($line['description'] ?? ''));
		if ($description !== '') {
			return $description;
		}

		return (string)($line['id'] ?? '');

	}//end lineLabel()
}//end class
