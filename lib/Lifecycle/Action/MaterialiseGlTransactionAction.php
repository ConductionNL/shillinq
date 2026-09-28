<?php

/**
 * Shillinq MaterialiseGlTransactionAction
 *
 * Handler for the posting action declared on the transitions that book a
 * source document into the general ledger: JournalEntry `post` and
 * `postDirect`, APInvoice `post` and ARInvoice `issue` (ledger-posting-path
 * REQ-LPP-004, REQ-LPP-006, REQ-LPP-007).
 *
 * Why the declarations name this class and not `materialise-gl-transaction`
 * ----------------------------------------------------------------------------
 * OpenRegister's LifecycleActionRegistry looks a declared name up in its own
 * container and then in the Nextcloud server container. The server container
 * only reaches an app's container for a name that starts with `OCA\<App>\`, so
 * a kebab-case alias registered in shillinq's register() is invisible to it,
 * and every transition that declared `materialise-gl-transaction` aborted with
 * "declared but no handler is registered" (#516). The FQCN is what the rest of
 * the fleet declares and what AppendReopenHistoryAction already uses.
 *
 * What it writes
 * --------------
 * One balanced GLTransaction in state `posted` plus one GLLine per posting
 * line, and it sets `glTransactionId` on the source it returns, so the back
 * reference is saved with the transition. It is idempotent: a source that
 * already carries `glTransactionId`, or whose `sourceReference` already has a
 * GLTransaction, is not booked twice. It refuses, by throwing, to book an
 * unbalanced posting and to book a source schema it has no mapper for, so an
 * unplanned declaration fails loudly instead of posting something invented.
 *
 * Mappers, one per source schema:
 *
 *   - JournalEntry: `lines` 1:1 (account, side, amount).
 *   - ARInvoice: debit the receivables control account for `grossAmount`,
 *     credit revenue per invoice line (plus document charges, less document
 *     allowances), credit output VAT for `vatAmount`.
 *   - APInvoice: debit each line's account, debit input VAT for `taxAmount`,
 *     credit the payables control account for `totalAmount`.
 *
 * InventoryValuation and ExpenseClaimEntry declare this action too; their
 * mappers are tasks 2.2 of ledger-posting-path and of expenses-category-mapping
 * and are not built, so those declarations are refused by name.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Books a source document into one balanced, posted GLTransaction.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One small mapper per source schema, by design D2.
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.1
 */
class MaterialiseGlTransactionAction implements LifecycleActionInterface {

	/**
	 * App-config key: receivables control account (RGS 3.5 MKB 1100 Debiteuren).
	 *
	 * @var string
	 */
	public const CFG_AR_CONTROL_ACCOUNT = 'ledger_ar_control_account';

	/**
	 * App-config key: revenue account (RGS 3.5 MKB 8000 Netto-omzet).
	 *
	 * @var string
	 */
	public const CFG_REVENUE_ACCOUNT = 'ledger_revenue_account';

	/**
	 * App-config key: output VAT account (RGS 3.5 MKB 2110 BTW-schuld).
	 *
	 * @var string
	 */
	public const CFG_OUTPUT_VAT_ACCOUNT = 'ledger_output_vat_account';

	/**
	 * App-config key: payables control account (RGS 3.5 MKB 2000 Crediteuren).
	 *
	 * @var string
	 */
	public const CFG_AP_CONTROL_ACCOUNT = 'ledger_ap_control_account';

	/**
	 * App-config key: input VAT account (RGS 3.5 MKB 1230 BTW-vordering).
	 *
	 * @var string
	 */
	public const CFG_INPUT_VAT_ACCOUNT = 'ledger_input_vat_account';

	/**
	 * Defaults for the account keys, taken from the shipped RGS 3.5 MKB chart
	 * (lib/Settings/seeds/rgs-3.5-mkb.json).
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_ACCOUNTS = [
		self::CFG_AR_CONTROL_ACCOUNT => '1100',
		self::CFG_REVENUE_ACCOUNT => '8000',
		self::CFG_OUTPUT_VAT_ACCOUNT => '2110',
		self::CFG_AP_CONTROL_ACCOUNT => '2000',
		self::CFG_INPUT_VAT_ACCOUNT => '1230',
	];

	/**
	 * The other side of a posting line.
	 *
	 * @var array<string, string>
	 */
	private const OPPOSITE_SIDE = [
		'debit' => 'credit',
		'credit' => 'debit',
	];

	/**
	 * The source schemas this handler can post.
	 *
	 * @var list<string>
	 */
	public const SUPPORTED_SOURCES = ['JournalEntry', 'ARInvoice', 'APInvoice'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig Register slug and account numbers.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Book the transitioning source into the ledger.
	 *
	 * @param array<string,mixed> $objectData The source after its state moved.
	 * @param array<string,mixed> $previousData The source before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (`sourceSchema`, `keepBalanced`).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The source with `glTransactionId` set.
	 *
	 * @throws RuntimeException When the source schema has no mapper, the posting does not balance, or a write fails.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.1
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$sourceSchema = (string)($parameters['sourceSchema'] ?? '');
		if (in_array($sourceSchema, self::SUPPORTED_SOURCES, true) === false) {
			throw new RuntimeException(
				sprintf(
					'This transition cannot post to the ledger yet: no posting is defined for "%s" (supported: %s).',
					$sourceSchema,
					implode(', ', self::SUPPORTED_SOURCES)
				)
			);
		}

		if ((string)($objectData['glTransactionId'] ?? '') !== '') {
			return $objectData;
		}

		$sourceId = (string)($objectData['id'] ?? ($objectData['@self']['id'] ?? ''));
		if ($sourceId === '') {
			throw new RuntimeException(sprintf('Cannot post a %s without an id.', $sourceSchema));
		}

		$sourceReference = $sourceSchema . ':' . $sourceId;
		$existing = $this->findExistingTransaction(sourceReference: $sourceReference);
		if ($existing !== '') {
			$objectData['glTransactionId'] = $existing;
			return $objectData;
		}

		$posting = match ($sourceSchema) {
			'JournalEntry' => $this->mapJournalEntry(entry: $objectData, sourceId: $sourceId),
			'ARInvoice' => $this->mapArInvoice(invoice: $objectData, sourceId: $sourceId),
			default => $this->mapApInvoice(invoice: $objectData, sourceId: $sourceId),
		};
		$lines = $this->normaliseLines(lines: $posting['lines']);
		if (($parameters['keepBalanced'] ?? true) !== false) {
			$this->assertBalanced(lines: $lines, label: $posting['header']['description']);
		}

		$header = $posting['header'];
		$header['sourceReference'] = $sourceReference;
		$header['state'] = 'posted';
		$header['currency'] = (string)($objectData['currency'] ?? 'EUR');
		$header['administrationId'] = (string)($objectData['administrationId'] ?? '');
		if ($header['administrationId'] === '') {
			throw new RuntimeException(sprintf('Cannot post %s: it has no administrationId.', $sourceReference));
		}

		$header['periodId'] = $this->periodId(object: $objectData, postingDate: (string)$header['postingDate']);

		$objectData['glTransactionId'] = $this->write(header: $header, lines: $lines, sourceId: $sourceId);
		return $objectData;
	}//end execute()

	/**
	 * JournalEntry: its `lines` already carry account, side and amount.
	 *
	 * @param array<string,mixed> $entry The JournalEntry.
	 * @param string $sourceId The JournalEntry id.
	 *
	 * @return array{header: array<string,mixed>, lines: list<array<string,mixed>>}
	 */
	private function mapJournalEntry(array $entry, string $sourceId): array {
		$lines = [];
		foreach ((array)($entry['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				throw new RuntimeException('A journal entry line is not an object.');
			}

			$lines[] = [
				'accountNumber' => (string)($line['accountNumber'] ?? ''),
				'side' => (string)($line['side'] ?? ''),
				'cents' => $this->cents(amount: ($line['amount'] ?? 0)),
				'description' => (string)($line['description'] ?? ($entry['description'] ?? '')),
			];
		}

		return [
			'header' => [
				'transactionNumber' => 'JE-' . (string)($entry['journalNumber'] ?? $sourceId),
				'postingDate' => (string)($entry['entryDate'] ?? date('Y-m-d')),
				'description' => (string)($entry['description'] ?? ('Journal entry ' . ($entry['journalNumber'] ?? $sourceId))),
				'journalType' => 'manual',
				'journalEntryId' => $sourceId,
			],
			'lines' => $lines,
		];
	}//end mapJournalEntry()

	/**
	 * ARInvoice: receivables against revenue and output VAT (REQ-LPP-007).
	 *
	 * @param array<string,mixed> $invoice The ARInvoice.
	 * @param string $sourceId The ARInvoice id.
	 *
	 * @return array{header: array<string,mixed>, lines: list<array<string,mixed>>}
	 */
	private function mapArInvoice(array $invoice, string $sourceId): array {
		$number = (string)($invoice['invoiceNumber'] ?? $sourceId);
		$label = 'Sales invoice ' . $number;
		$revenue = $this->account(key: self::CFG_REVENUE_ACCOUNT);

		$lines = [
			[
				'accountNumber' => $this->account(key: self::CFG_AR_CONTROL_ACCOUNT),
				'side' => 'debit',
				'cents' => $this->cents(amount: ($invoice['grossAmount'] ?? 0)),
				'description' => $label,
				'subLedgerType' => 'ar',
				'subLedgerRef' => $sourceId,
			],
		];

		$invoiceLines = (array)($invoice['invoiceLines'] ?? []);
		if ($invoiceLines === []) {
			$lines[] = $this->revenueLine(account: $revenue, amount: ($invoice['netAmount'] ?? 0), description: $label);
		}

		foreach ($invoiceLines as $line) {
			if (is_array($line) === true) {
				$lines[] = $this->revenueLine(
					account: $revenue,
					amount: ($line['netAmount'] ?? 0),
					description: (string)($line['itemName'] ?? $label)
				);
			}
		}

		if ($invoiceLines !== []) {
			// Document-level charges add to revenue, allowances reduce it (EN 16931 BT-99 / BT-107).
			$lines[] = $this->revenueLine(account: $revenue, amount: ($invoice['chargesTotal'] ?? 0), description: $label . ' charges');
			$lines[] = $this->revenueLine(account: $revenue, amount: -(float)($invoice['allowancesTotal'] ?? 0), description: $label . ' allowances');
		}

		$lines[] = [
			'accountNumber' => $this->account(key: self::CFG_OUTPUT_VAT_ACCOUNT),
			'side' => 'credit',
			'cents' => $this->cents(amount: ($invoice['vatAmount'] ?? 0)),
			'description' => $label . ' VAT',
		];

		return [
			'header' => [
				'transactionNumber' => 'AR-' . $number,
				'postingDate' => (string)($invoice['invoiceDate'] ?? date('Y-m-d')),
				'description' => $label,
				'journalType' => 'ar-invoice',
			],
			'lines' => $lines,
		];
	}//end mapArInvoice()

	/**
	 * APInvoice: expense per line and input VAT against payables.
	 *
	 * @param array<string,mixed> $invoice The APInvoice.
	 * @param string $sourceId The APInvoice id.
	 *
	 * @return array{header: array<string,mixed>, lines: list<array<string,mixed>>}
	 */
	private function mapApInvoice(array $invoice, string $sourceId): array {
		$number = (string)($invoice['invoiceNumber'] ?? $sourceId);
		$label = 'Purchase invoice ' . $number;

		$lines = [];
		foreach ((array)($invoice['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				throw new RuntimeException('A purchase invoice line is not an object.');
			}

			$lines[] = [
				'accountNumber' => (string)($line['accountNumber'] ?? ''),
				'side' => 'debit',
				'cents' => $this->cents(amount: ($line['amount'] ?? 0)),
				'description' => (string)($line['description'] ?? $label),
			];
		}

		$lines[] = [
			'accountNumber' => $this->account(key: self::CFG_INPUT_VAT_ACCOUNT),
			'side' => 'debit',
			'cents' => $this->cents(amount: ($invoice['taxAmount'] ?? 0)),
			'description' => $label . ' VAT',
		];
		$lines[] = [
			'accountNumber' => $this->account(key: self::CFG_AP_CONTROL_ACCOUNT),
			'side' => 'credit',
			'cents' => $this->cents(amount: ($invoice['totalAmount'] ?? 0)),
			'description' => $label,
			'subLedgerType' => 'ap',
			'subLedgerRef' => $sourceId,
		];

		return [
			'header' => [
				'transactionNumber' => 'AP-' . $number,
				'postingDate' => (string)($invoice['invoiceDate'] ?? date('Y-m-d')),
				'description' => $label,
				'journalType' => 'ap-invoice',
			],
			'lines' => $lines,
		];
	}//end mapApInvoice()

	/**
	 * A revenue credit line.
	 *
	 * @param string $account The revenue account.
	 * @param mixed $amount The amount in euros (negative flips it to a debit).
	 * @param string $description The line description.
	 *
	 * @return array<string,mixed>
	 */
	private function revenueLine(string $account, mixed $amount, string $description): array {
		return [
			'accountNumber' => $account,
			'side' => 'credit',
			'cents' => $this->cents(amount: $amount),
			'description' => $description,
		];
	}//end revenueLine()

	/**
	 * Drop zero lines and turn a negative amount into a positive one on the
	 * other side, so every written GLLine carries a positive amount.
	 *
	 * @param list<array<string,mixed>> $lines The mapped lines.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function normaliseLines(array $lines): array {
		$out = [];
		foreach ($lines as $line) {
			$cents = (int)$line['cents'];
			if ($cents === 0) {
				continue;
			}

			if (in_array($line['side'], ['debit', 'credit'], true) === false) {
				throw new RuntimeException(sprintf('A posting line has side "%s"; it must be debit or credit.', (string)$line['side']));
			}

			if ((string)$line['accountNumber'] === '') {
				throw new RuntimeException('A posting line has no account number.');
			}

			if ($cents < 0) {
				$line['side'] = self::OPPOSITE_SIDE[$line['side']];
				$cents = -$cents;
			}

			$line['cents'] = $cents;
			$out[] = $line;
		}

		return $out;
	}//end normaliseLines()

	/**
	 * Refuse a posting whose debits and credits differ, or that is empty.
	 *
	 * @param list<array<string,mixed>> $lines The normalised lines.
	 * @param string $label What is being posted, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the posting does not balance.
	 */
	private function assertBalanced(array $lines, string $label): void {
		$debit = 0;
		$credit = 0;
		foreach ($lines as $line) {
			if ($line['side'] === 'debit') {
				$debit += (int)$line['cents'];
				continue;
			}

			$credit += (int)$line['cents'];
		}

		if ($debit === 0 || $debit !== $credit) {
			throw new RuntimeException(
				sprintf(
					'%s is not balanced and was not posted: debit %.2f, credit %.2f.',
					$label,
					($debit / 100),
					($credit / 100)
				)
			);
		}
	}//end assertBalanced()

	/**
	 * Write the transaction and its lines; undo the header when a line fails,
	 * so a failed post leaves no half-written transaction behind.
	 *
	 * @param array<string,mixed> $header The GLTransaction header.
	 * @param list<array<string,mixed>> $lines The normalised lines.
	 * @param string $sourceId The source id, for the log.
	 *
	 * @return string The new GLTransaction id.
	 */
	private function write(array $header, array $lines, string $sourceId): string {
		$register = $this->register();
		$transactionId = $this->idOf(
			saved: $this->objectService->saveObject(object: $header, register: $register, schema: 'GLTransaction')->jsonSerialize()
		);
		if ($transactionId === '') {
			throw new RuntimeException(sprintf('The ledger transaction for %s was not saved.', (string)$header['sourceReference']));
		}

		$written = [];
		try {
			foreach ($lines as $index => $line) {
				$row = [
					'transactionId' => $transactionId,
					'lineNumber' => ($index + 1),
					'accountNumber' => (string)$line['accountNumber'],
					'side' => (string)$line['side'],
					'amount' => round(((int)$line['cents'] / 100), 2),
					'currency' => (string)$header['currency'],
					'periodId' => (string)$header['periodId'],
					'administrationId' => (string)$header['administrationId'],
					'description' => (string)($line['description'] ?? ''),
				];
				if (isset($line['subLedgerType']) === true) {
					$row['subLedgerType'] = (string)$line['subLedgerType'];
					$row['subLedgerRef'] = (string)$line['subLedgerRef'];
				}

				$written[] = $this->idOf(
					saved: $this->objectService->saveObject(object: $row, register: $register, schema: 'GLLine')->jsonSerialize()
				);
			}//end foreach
		} catch (\Throwable $e) {
			$this->rollBack(transactionId: $transactionId, lineIds: $written);
			$this->logger->error(
				'MaterialiseGlTransactionAction: a GL line failed; the transaction was withdrawn',
				['sourceId' => $sourceId, 'transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'The ledger transaction could not be written: ' . $e->getMessage(), previous: $e);
		}//end try

		return $transactionId;
	}//end write()

	/**
	 * Remove a partially written transaction and its lines, best effort.
	 *
	 * @param string $transactionId The GLTransaction id.
	 * @param list<string> $lineIds The GLLine ids already written.
	 *
	 * @return void
	 */
	private function rollBack(string $transactionId, array $lineIds): void {
		$register = $this->register();
		foreach (array_filter($lineIds) as $lineId) {
			try {
				$this->objectService->deleteObject(uuid: $lineId, register: $register, schema: 'GLLine');
			} catch (\Throwable $e) {
				$this->logger->error('MaterialiseGlTransactionAction: could not withdraw GL line ' . $lineId, ['exception' => $e->getMessage()]);
			}
		}

		try {
			$this->objectService->deleteObject(uuid: $transactionId, register: $register, schema: 'GLTransaction');
		} catch (\Throwable $e) {
			$this->logger->error('MaterialiseGlTransactionAction: could not withdraw GL transaction ' . $transactionId, ['exception' => $e->getMessage()]);
		}
	}//end rollBack()

	/**
	 * The id of a GLTransaction already booked for this source, or ''.
	 *
	 * @param string $sourceReference `<Schema>:<id>`.
	 *
	 * @return string
	 */
	private function findExistingTransaction(string $sourceReference): string {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(['filters' => ['sourceReference' => $sourceReference], 'limit' => 1]);

		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true && ($data['sourceReference'] ?? null) === $sourceReference) {
				return $this->idOf(saved: $data);
			}
		}

		return '';
	}//end findExistingTransaction()

	/**
	 * The fiscal period id: the source's own, else YYYY-MM of the posting date.
	 *
	 * @param array<string,mixed> $object The source.
	 * @param string $postingDate The posting date.
	 *
	 * @return string
	 */
	private function periodId(array $object, string $postingDate): string {
		$own = (string)($object['periodId'] ?? '');
		if ($own !== '') {
			return $own;
		}

		if (preg_match('/^(\d{4})-(\d{2})/', $postingDate, $matches) === 1) {
			return $matches[1] . '-' . $matches[2];
		}

		return date('Y-m');
	}//end periodId()

	/**
	 * An amount in euros as integer cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 */
	private function cents(mixed $amount): int {
		if (is_numeric($amount) === false) {
			return 0;
		}

		return (int)round((float)$amount * 100);
	}//end cents()

	/**
	 * The id of a saved or found object.
	 *
	 * @param mixed $saved The serialised object.
	 *
	 * @return string
	 */
	private function idOf(mixed $saved): string {
		if (is_array($saved) === false) {
			return '';
		}

		return (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * A configured account number, falling back to the RGS 3.5 MKB default.
	 *
	 * @param string $key The app-config key.
	 *
	 * @return string
	 */
	private function account(string $key): string {
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_ACCOUNTS[$key];
	}//end account()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
