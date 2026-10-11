<?php

/**
 * Inbound Payable Service
 *
 * Money another app owes someone, paid out by finance. The app raises the
 * payable (amount, payee, reference, callback) with PayableRaisedEvent;
 * finance approves it (expense against the payables account), marks it paid
 * (payables against the bank) or rejects it, and each step is posted back to
 * the app (decision 192, REQ-IPY-001 to REQ-IPY-004).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises, approves, pays and rejects a payable another app asked for.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
 */
class InboundPayableService {

	/**
	 * App config key: the payables account an approved payout waits on.
	 * @var string
	 */
	public const CONFIG_PAYABLE_ACCOUNT = 'inboundPayableAccount';

	/**
	 * App config key: a JSON map of category or app id to expense account.
	 * @var string
	 */
	public const CONFIG_EXPENSE_ACCOUNTS = 'inboundPayableExpenseAccounts';

	/**
	 * The schema slug.
	 * @var string
	 */
	private const SCHEMA = 'InboundPayable';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig                   $appConfig     The register slug and the accounts.
	 * @param IAppManager                  $appManager    Whether the raising app is installed.
	 * @param ObjectTransitionRunner       $transitions   Posts the journal entries.
	 * @param InboundPayableCallbackSender $callbacks     Tells the app each step.
	 * @param LoggerInterface              $logger        Logs a raise that failed after its checks.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly ObjectTransitionRunner $transitions,
		private readonly InboundPayableCallbackSender $callbacks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a payout command: store it as requested, or refuse and write nothing.
	 *
	 * @param PayableRaisedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function raise(PayableRaisedEvent $event): void {
		$payable = $event->toArray();
		$refusal = $this->refusal(payable: $payable);
		if ($refusal !== null) {
			$event->refuse(error: $refusal);
			return;
		}

		$existing = $this->findByReference(app: $payable['sourceApp'], reference: $payable['reference']);
		if ($existing !== null) {
			$this->answerRetry(event: $event, existing: $existing, payable: $payable);
			return;
		}

		$payable['state'] = 'requested';
		$payable['requestedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		try {
			$id = $this->save(schema: self::SCHEMA, object: $payable);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a payable could not be stored', ['exception' => $e->getMessage()]);
			$event->refuse(error: 'The payable could not be stored: ' . $e->getMessage());
			return;
		}

		$event->accept(payableId: $id, state: 'requested');
	}//end raise()

	/**
	 * Approve a requested payable: book the expense against the payables account.
	 *
	 * @param string $payableId The payable's uuid.
	 *
	 * @return array<string, mixed> The payable, now approved.
	 *
	 * @throws InvalidArgumentException When it is not requested, or an account is missing.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	public function approve(string $payableId): array {
		$payable = $this->findIn(id: $payableId, state: 'requested');
		$payableAccount = $this->payableAccount();
		$expense = $this->expenseAccount(payable: $payable);

		$journalId = $this->book(
			payable: $payable,
			prefix: 'PA',
			debit: $expense,
			credit: $payableAccount,
			description: sprintf('Payout approved: %s', $this->label(payable: $payable))
		);
		$payable['state'] = 'approved';
		$payable['approvedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$payable['journalEntryIds'] = [...($payable['journalEntryIds'] ?? []), $journalId];

		return $this->saveAndCallBack(payable: $payable);
	}//end approve()

	/**
	 * Mark an approved payable paid: clear the payables account against the bank.
	 *
	 * @param string $payableId     The payable's uuid.
	 * @param string $bankReference The reference of the bank payment.
	 * @param string $bankAccount   The ledger account of the bank it was paid from.
	 * @param string $paidOn        The date it left the bank (Y-m-d), today when empty.
	 *
	 * @return array<string, mixed> The payable, now paid.
	 *
	 * @throws InvalidArgumentException When it is not approved, or a value is missing.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function markPaid(string $payableId, string $bankReference, string $bankAccount, string $paidOn = ''): array {
		$bankReference = trim($bankReference);
		$bankAccount = trim($bankAccount);
		if ($bankReference === '' || $bankAccount === '') {
			throw new InvalidArgumentException('A paid payout needs the bank reference and the bank account it was paid from.');
		}

		$paidOn = trim($paidOn);
		if ($paidOn === '') {
			$paidOn = gmdate('Y-m-d');
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) !== 1) {
			throw new InvalidArgumentException('The paid date must read YYYY-MM-DD.');
		}

		$payable = $this->findIn(id: $payableId, state: 'approved');
		$journalId = $this->book(
			payable: $payable,
			prefix: 'PP',
			debit: $this->payableAccount(),
			credit: $bankAccount,
			description: sprintf('Payout paid: %s (%s)', $this->label(payable: $payable), $bankReference)
		);
		$payable['state'] = 'paid';
		$payable['paidOn'] = $paidOn;
		$payable['bankReference'] = $bankReference;
		$payable['journalEntryIds'] = [...($payable['journalEntryIds'] ?? []), $journalId];

		return $this->saveAndCallBack(payable: $payable);
	}//end markPaid()

	/**
	 * Reject a requested payable: nothing is booked, the app hears why.
	 *
	 * @param string $payableId The payable's uuid.
	 * @param string $reason    Why.
	 *
	 * @return array<string, mixed> The payable, now rejected.
	 *
	 * @throws InvalidArgumentException When it is not requested, or no reason is given.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function reject(string $payableId, string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('Rejecting a payout needs a reason the app can pass on.');
		}

		$payable = $this->findIn(id: $payableId, state: 'requested');
		$payable['state'] = 'rejected';
		$payable['rejectedReason'] = $reason;

		return $this->saveAndCallBack(payable: $payable);
	}//end reject()

	/**
	 * Why a payout command is refused, or null.
	 *
	 * @param array<string, mixed> $payable The command's fields.
	 *
	 * @return string|null The refusal.
	 */
	private function refusal(array $payable): ?string {
		$app = (string)$payable['sourceApp'];
		if ($app === '' || $this->appManager->isInstalled($app) === false) {
			return sprintf('Only an installed app can raise a payable; "%s" is not one.', $app);
		}

		if ((float)$payable['amount'] <= 0.0) {
			return 'A payable needs an amount above zero.';
		}

		if ($payable['payee']['name'] === '') {
			return 'A payable needs the payee\'s name.';
		}

		if (self::isValidIban(iban: (string)$payable['payee']['iban']) === false) {
			return 'The payee\'s IBAN is not valid.';
		}

		if (mb_strlen((string)$payable['reference']) < 6) {
			return 'A payable needs a reference of at least 6 characters.';
		}

		$url = (string)$payable['callbackUrl'];
		if ($url !== '' && preg_match('#^https?://[^\s/]+#i', $url) !== 1) {
			return 'The callback must be an http or https address.';
		}

		if ($payable['dueOn'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$payable['dueOn']) !== 1) {
			return 'The pay-by date must read YYYY-MM-DD.';
		}

		return null;
	}//end refusal()

	/**
	 * Answer a reference the app used before: the same payout is a retry, another is a clash.
	 *
	 * @param PayableRaisedEvent   $event    The command.
	 * @param array<string, mixed> $existing The payable stored under the reference.
	 * @param array<string, mixed> $payable  The command's fields.
	 *
	 * @return void
	 */
	private function answerRetry(PayableRaisedEvent $event, array $existing, array $payable): void {
		$same = round((float)($existing['amount'] ?? 0), 2) === $payable['amount']
			&& (string)($existing['payee']['iban'] ?? '') === $payable['payee']['iban'];
		if ($same === false) {
			$event->refuse(
				error: sprintf('%s already raised a different payable under reference %s.', $payable['sourceApp'], $payable['reference'])
			);
			return;
		}

		$event->accept(payableId: (string)$existing['id'], state: (string)($existing['state'] ?? 'requested'));
	}//end answerRetry()

	/**
	 * The payable an app raised under a reference, or null.
	 *
	 * @param string $app       The app id.
	 * @param string $reference The reference.
	 *
	 * @return array<string, mixed>|null The payable with its id.
	 */
	private function findByReference(string $app, string $reference): ?array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['sourceApp' => $app, 'reference' => $reference], 'limit' => 1], _rbac: false, _multitenancy: false);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				return $record;
			}
		}

		return null;
	}//end findByReference()

	/**
	 * Read one payable, which must be in the given state.
	 *
	 * @param string $id    The uuid.
	 * @param string $state The state it must be in.
	 *
	 * @return array<string, mixed> The payable with its id.
	 *
	 * @throws InvalidArgumentException When there is none, or it is in another state.
	 */
	private function findIn(string $id, string $state): array {
		$record = null;
		if ($id !== '') {
			try {
				$found = $this->objectService
					->setRegister($this->registerSlug())
					->setSchema(self::SCHEMA)
					->find($id, _rbac: false, _multitenancy: false);
				$record = ObjectIdentifier::recordWithId(candidate: $found);
			} catch (Throwable $e) {
				$record = null;
			}
		}

		if ($record === null || (string)($record['id'] ?? '') === '') {
			throw new InvalidArgumentException('No payable with that id exists.');
		}

		if (($record['state'] ?? '') !== $state) {
			throw new InvalidArgumentException(
				sprintf('The payable is %s, so it cannot take this step; it must be %s.', (string)($record['state'] ?? 'unknown'), $state)
			);
		}

		return $record;
	}//end findIn()

	/**
	 * The expense account: by the payable's category, else by its app.
	 *
	 * @param array<string, mixed> $payable The payable.
	 *
	 * @return string The account number.
	 *
	 * @throws InvalidArgumentException When neither is mapped.
	 */
	private function expenseAccount(array $payable): string {
		$map = json_decode($this->appConfig->getValueString('shillinq', self::CONFIG_EXPENSE_ACCOUNTS, ''), true);
		if (is_array($map) === false) {
			$map = [];
		}

		foreach ([(string)($payable['category'] ?? ''), (string)($payable['sourceApp'] ?? '')] as $key) {
			$account = trim((string)($map[$key] ?? ''));
			if ($key !== '' && $account !== '') {
				return $account;
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'No expense account is mapped for category "%s" or app "%s"; add one to %s and try again.',
				(string)($payable['category'] ?? ''),
				(string)($payable['sourceApp'] ?? ''),
				self::CONFIG_EXPENSE_ACCOUNTS
			)
		);
	}//end expenseAccount()

	/**
	 * The payables account from app config.
	 *
	 * @return string The account number.
	 *
	 * @throws InvalidArgumentException When it is not set.
	 */
	private function payableAccount(): string {
		$account = trim($this->appConfig->getValueString('shillinq', self::CONFIG_PAYABLE_ACCOUNT, ''));
		if ($account === '') {
			throw new InvalidArgumentException('No payables account is set; add it to ' . self::CONFIG_PAYABLE_ACCOUNT . ' and try again.');
		}

		return $account;
	}//end payableAccount()

	/**
	 * Save the payable after a step, tell the app, and save what the callback did.
	 *
	 * @param array<string, mixed> $payable The payable after the step.
	 *
	 * @return array<string, mixed> The payable as stored.
	 */
	private function saveAndCallBack(array $payable): array {
		$this->save(schema: self::SCHEMA, object: $payable);
		$told = $this->callbacks->send(payable: $payable);
		if ($told !== $payable) {
			$this->save(schema: self::SCHEMA, object: $told);
		}

		return $told;
	}//end saveAndCallBack()

	/**
	 * Post one balanced journal entry for the payable.
	 *
	 * @param array<string, mixed> $payable     The payable.
	 * @param string               $prefix      The journal number prefix.
	 * @param string               $debit       The account debited.
	 * @param string               $credit      The account credited.
	 * @param string               $description The posting text.
	 *
	 * @return string The journal entry's uuid.
	 *
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 */
	private function book(array $payable, string $prefix, string $debit, string $credit, string $description): string {
		$amount = round((float)$payable['amount'], 2);
		$journalId = $this->save(
			schema: 'JournalEntry',
			object: [
				'journalNumber' => $prefix . '-' . substr(hash('sha256', (string)($payable['id'] ?? '')), 0, 12),
				'entryDate' => gmdate('Y-m-d'),
				'description' => $description,
				'journalType' => 'manual',
				'approvalState' => 'not-required',
				'administrationId' => (string)($payable['administrationId'] ?? ''),
				'state' => 'draft',
				'lines' => [
					['accountNumber' => $debit, 'side' => 'debit', 'amount' => $amount, 'description' => $description],
					['accountNumber' => $credit, 'side' => 'credit', 'amount' => $amount, 'description' => $description],
				],
			]
		);
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		return $journalId;
	}//end book()

	/**
	 * How the payable is named in a posting text.
	 *
	 * @param array<string, mixed> $payable The payable.
	 *
	 * @return string App, reference and payee.
	 */
	private function label(array $payable): string {
		return sprintf(
			'%s %s to %s',
			(string)($payable['sourceApp'] ?? ''),
			(string)($payable['reference'] ?? ''),
			(string)($payable['payee']['name'] ?? '')
		);
	}//end label()

	/**
	 * Conservative IBAN check: shape, length and mod-97.
	 *
	 * @param string $iban The IBAN without spaces, in capitals.
	 *
	 * @return bool True when valid.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public static function isValidIban(string $iban): bool {
		if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
			return false;
		}

		$rearranged = substr($iban, 4) . substr($iban, 0, 4);
		$digits = '';
		foreach (str_split($rearranged) as $char) {
			if (ctype_alpha($char) === true) {
				$digits .= (string)(ord($char) - 55);
				continue;
			}

			$digits .= $char;
		}

		$remainder = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$remainder = (int)(($remainder . $chunk) % 97);
		}

		return $remainder === 1;
	}//end isValidIban()

	/**
	 * Save one object in shillinq's register and answer its uuid.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return string The uuid.
	 *
	 * @throws RuntimeException When OpenRegister answers without an id.
	 */
	private function save(string $schema, array $object): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: $this->registerSlug(),
			schema: $schema,
			_rbac: false,
			_multitenancy: false,
		);
		$uuid = ObjectIdentifier::resolve(saved: $saved);
		if ($uuid === '') {
			throw new RuntimeException(sprintf('The %s was saved but OpenRegister answered without an id.', $schema));
		}

		return $uuid;
	}//end save()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
