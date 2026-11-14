<?php

/**
 * Shillinq MaterialiseGlTransactionAction
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 *     allowances), credit output VAT for `vatAmount`. A down-payment invoice
 *     (`downPayment.kind` down-payment) credits the advances account instead
 *     of revenue, and a deduction line of a final invoice
 *     (`invoiceLines[].downPaymentInvoiceId`) debits it (sales-down-payments).
 *   - APInvoice: debit each line's account, debit input VAT for `taxAmount`,
 *     credit the payables control account for `totalAmount`.
 *
 * ExpenseClaimEntry declares this action too. Its mapper needs the
 * ExpenseAccountResolver of expenses-category-mapping (task 3.2 there), so
 * until that change lands the declaration is refused by name. The
 * InventoryValuation declarations were removed: sale dispatch is booked per
 * stock move by CogsPosterService, and a running valuation snapshot cannot
 * key one posting per event.
 *
 * Every transaction written here carries the posting stamps (PostingStamps),
 * so it meets the same ledger rules as an entry posted from the ledger page.
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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\MaterialiseGlTransactionActionInterface;
use OCA\Shillinq\Lifecycle\PostingStamps;
use OCA\Shillinq\Service\Treasury\FxAtBookingResolver;
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Books a source document into one balanced, posted GLTransaction.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One small mapper per source schema, by design D2.
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */
class MaterialiseGlTransactionAction implements LifecycleActionInterface, MaterialiseGlTransactionActionInterface {

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
	 * App-config key: payables control account (RGS 3.5 MKB 2000 Crediteuren).
	 *
	 * @var string
	 */
	public const CFG_AP_CONTROL_ACCOUNT = 'ledger_ap_control_account';

	/**
	 * App-config key: advances received (RGS 3.5 MKB 2310 Vooruitontvangen
	 * bedragen), where a down payment is booked until the final invoice
	 * releases it (sales-down-payments REQ-SDP-002).
	 *
	 * @var string
	 */
	public const CFG_ADVANCES_ACCOUNT = 'ledger_advances_account';

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
		self::CFG_ADVANCES_ACCOUNT => '2310',
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
	public const SUPPORTED_SOURCES = ['JournalEntry', 'ARInvoice', 'APInvoice', 'APTransaction'];

	/**
	 * The tariff, box and VAT split, built on first use.
	 *
	 * @var VatLineStamper|null
	 */
	private ?VatLineStamper $vatStamper = null;

	/**
	 * The snapshot fields a foreign-currency line carries onto its GL line.
	 *
	 * @var array<int,string>
	 */
	private const FX_FIELDS = [
		'transactionAmount',
		'transactionCurrency',
		'baseCurrencyAmount',
		'baseCurrency',
		'fxRate',
		'fxRateSource',
		'fxRateDate',
	];

	/**
	 * The posting being booked at a rate: its currency, the administration's
	 * base currency, the booking date and the administration.
	 *
	 * @var array{currency:string,base:string,date:string,administrationId:string}
	 */
	private array $booking = ['currency' => '', 'base' => '', 'date' => '', 'administrationId' => ''];

	/**
	 * The booking-date rate of the posting being booked, looked up at the
	 * first line that has no typed rate.
	 *
	 * @var array{rate:float,source:string,date:string}|null
	 */
	private ?array $bookingRate = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig Register slug and account numbers.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param IUserSession|null $userSession The posting user, for the audit trail; none outside a session.
	 * @param FxAtBookingResolver|null $fxResolver The booking-date rate lookup; built on first use when not injected.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
		private ?FxAtBookingResolver $fxResolver = null,
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
	 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
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
		$keepBalanced = (($parameters['keepBalanced'] ?? true) !== false);
		if ($keepBalanced === true) {
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

		$booked = $this->bookAtRate(
			booking: ['lines' => $lines, 'header' => $header, 'keepBalanced' => $keepBalanced],
			currency: strtoupper(trim((string)($objectData['currency'] ?? '')))
		);
		$lines  = $booked['lines'];
		$header = $booked['header'];

		$header['periodId'] = $this->periodId(object: $objectData, postingDate: (string)$header['postingDate']);
		$header = (new PostingStamps())->apply(transaction: $header, user: $this->actor(), now: new DateTimeImmutable());

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

			$mapped = [
				'accountNumber' => (string)($line['accountNumber'] ?? ''),
				'side' => (string)($line['side'] ?? ''),
				'cents' => $this->cents(amount: ($line['amount'] ?? 0)),
				'description' => (string)($line['description'] ?? ($entry['description'] ?? '')),
			];
			// The line's cost centre and project reach the GL line
			// (ledger-booking-rules: restrictions and segment reports read them).
			foreach (['costCenterCode', 'projectCode'] as $dimension) {
				if ((string)($line[$dimension] ?? '') !== '') {
					$mapped[$dimension] = (string)$line[$dimension];
				}
			}

			// A rate typed on the line wins over the booking-date rate (REQ-BFX-003).
			$lines[] = array_merge($mapped, self::typedRateFields(line: $line));
		}//end foreach

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
		$advances = $this->account(key: self::CFG_ADVANCES_ACCOUNT);
		if ((string)(($invoice['downPayment'] ?? [])['kind'] ?? '') === 'down-payment') {
			// A down payment is not revenue until delivery: the advance is a liability (REQ-SDP-002).
			$revenue = $advances;
		}

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

		// Each line carries its tariff and box; the VAT is booked per tariff (REQ-VBTW-004).
		$vat    = $this->vatStamper();
		$groups = [];
		foreach ($invoiceLines as $line) {
			if (is_array($line) === true) {
				// A deduction line takes a down payment off: its negative net releases the advance (REQ-SDP-003).
				$lineAccount = $revenue;
				if ((string)($line['downPaymentInvoiceId'] ?? '') !== '') {
					$lineAccount = $advances;
				}

				$code    = $vat->saleTariff(line: $line);
				$lines[] = $this->revenueLine(
					account: $lineAccount,
					amount: ($line['netAmount'] ?? 0),
					description: (string)($line['itemName'] ?? $label)
				) + $vat->stamp(code: $code, kind: 'base', purchase: false);
				$groups  = $this->addToVatGroup(
					groups: $groups,
					code: $code,
					baseCents: $this->cents(amount: ($line['netAmount'] ?? 0)),
					vatCents: $vat->vatCents(baseCents: $this->cents(amount: ($line['netAmount'] ?? 0)), rate: ($line['vatRate'] ?? null))
				);
			}
		}//end foreach

		if ($invoiceLines !== []) {
			// Document-level charges add to revenue, allowances reduce it (EN 16931 BT-99 / BT-107).
			// With one tariff on the invoice they are at that tariff; with several no box is guessed.
			$only = '';
			if (count($groups) === 1) {
				$only = (string)array_key_first($groups);
			}

			$documentLevel = [
				$label . ' charges' => $this->cents(amount: ($invoice['chargesTotal'] ?? 0)),
				$label . ' allowances' => -$this->cents(amount: ($invoice['allowancesTotal'] ?? 0)),
			];
			foreach ($documentLevel as $description => $cents) {
				$lines[] = $this->revenueLine(account: $revenue, amount: ($cents / 100), description: $description)
					+ $vat->stamp(code: $only, kind: 'base', purchase: false);
				if ($only !== '') {
					$groups[$only]['base'] += $cents;
				}
			}
		}//end if

		$groups = $this->applyVatBreakdown(groups: $groups, breakdown: (array)($invoice['vatBreakdown'] ?? []));
		foreach ($vat->splitVat(groups: $groups, totalCents: $this->cents(amount: ($invoice['vatAmount'] ?? 0))) as $code => $cents) {
			$lines[] = [
				'accountNumber' => $this->account(key: self::CFG_OUTPUT_VAT_ACCOUNT),
				'side' => 'credit',
				'cents' => $cents,
				'description' => $label . ' VAT',
			] + $vat->stamp(code: (string)$code, kind: 'vat', purchase: false);
		}

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

		$lines  = [];
		$vat    = $this->vatStamper();
		$groups = [];
		foreach ((array)($invoice['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				throw new RuntimeException('A purchase invoice line is not an object.');
			}

			$code    = $vat->purchaseTariff(line: $line);
			$cents   = $this->cents(amount: ($line['amount'] ?? 0));
			$lines[] = [
				'accountNumber' => (string)($line['accountNumber'] ?? ''),
				'side' => 'debit',
				'cents' => $cents,
				'description' => (string)($line['description'] ?? $label),
			] + $vat->stamp(code: $code, kind: 'base', purchase: true);

			// A reverse-charged line's VAT is not on the supplier's invoice, so it takes no share of it.
			if ($vat->isReverseCharge(code: $code) === false) {
				$groups = $this->addToVatGroup(
					groups: $groups,
					code: $code,
					baseCents: $cents,
					vatCents: $vat->vatCents(baseCents: $cents, rate: $vat->ratePercentage(code: $code))
				);
			}
		}//end foreach

		// Input VAT per tariff, in box 5b (REQ-VBTW-004).
		foreach ($vat->splitVat(groups: $groups, totalCents: $this->cents(amount: ($invoice['taxAmount'] ?? 0))) as $code => $cents) {
			$lines[] = [
				'accountNumber' => $this->account(key: self::CFG_INPUT_VAT_ACCOUNT),
				'side' => 'debit',
				'cents' => $cents,
				'description' => $label . ' VAT',
			] + $vat->stamp(code: (string)$code, kind: 'vat', purchase: true);
		}
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
	 * Book a foreign-currency posting at its rate: each line's amount becomes
	 * the base amount and carries the rate snapshot (REQ-BFX-001..003).
	 *
	 * Lines of a posting in the administration's base currency, or with no
	 * currency, are returned untouched. A line with a typed rate is booked at
	 * it as source manual and needs a reason; the others at the booking-date
	 * rate the resolver picks, which refuses when there is none.
	 *
	 * @param array<string,mixed> $booking  `lines` (normalised, in transaction cents), `header`, and
	 *                                      `keepBalanced`: whether the base amounts must balance.
	 * @param string              $currency The posting's ISO 4217 currency, upper case.
	 *
	 * @return array{lines:array<int,array<string,mixed>>,header:array<string,mixed>,keepBalanced:bool}
	 *
	 * @throws RuntimeException When a rate is missing, a typed rate is invalid or has no reason, or rounding unbalances the entry.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private function bookAtRate(array $booking, string $currency): array {
		$administrationId = (string)$booking['header']['administrationId'];
		$base = $this->foreignBase(currency: $currency, administrationId: $administrationId);
		if ($base === null) {
			return $booking;
		}

		$this->booking     = [
			'currency' => $currency,
			'base' => $base,
			'date' => substr((string)$booking['header']['postingDate'], 0, 10),
			'administrationId' => $administrationId,
		];
		$this->bookingRate = null;
		foreach ($booking['lines'] as $index => $line) {
			$booking['lines'][$index] = $this->bookedLine(line: $line, number: ($index + 1));
		}

		if ($booking['keepBalanced'] === true) {
			$this->assertBalancedInBase(lines: $booking['lines'], label: (string)$booking['header']['description']);
		}

		$booking['header']['currency'] = $base;
		return $booking;

	}//end bookAtRate()

	/**
	 * The administration's base currency when the posting is in another one, else null.
	 *
	 * @param string $currency         The posting's currency, upper case; '' when it names none.
	 * @param string $administrationId The administration.
	 *
	 * @return string|null The base currency to book in, or null to leave the posting as it is.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private function foreignBase(string $currency, string $administrationId): ?string {
		if ($currency === '') {
			return null;
		}

		$base = $this->fxResolver()->baseCurrencyFor(administrationId: $administrationId);
		if ($currency === $base) {
			return null;
		}

		return $base;

	}//end foreignBase()

	/**
	 * One line booked at its rate: the amount in base cents, the rate snapshot under `fx`.
	 *
	 * @param array<string,mixed> $line   The line, in transaction cents.
	 * @param int                 $number Its 1-based position, for the messages.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the typed rate is invalid or no booking-date rate exists.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private function bookedLine(array $line, int $number): array {
		$snapshot  = ($this->typedRate(line: $line, number: $number) ?? $this->bookingDateRate());
		$baseCents = FxAtBookingResolver::toBaseCents(cents: (int)$line['cents'], rate: $snapshot['rate']);
		$line['fx'] = [
			'transactionAmount' => (int)$line['cents'],
			'transactionCurrency' => $this->booking['currency'],
			'baseCurrencyAmount' => $baseCents,
			'baseCurrency' => $this->booking['base'],
			'fxRate' => $snapshot['rate'],
			'fxRateSource' => $snapshot['source'],
			'fxRateDate' => $snapshot['date'],
		];
		$line['cents'] = $baseCents;
		unset($line['typedFxRate'], $line['fxRateReason']);
		return $line;

	}//end bookedLine()

	/**
	 * The booking-date rate of the posting being booked, looked up once.
	 *
	 * @return array{rate:float,source:string,date:string}
	 *
	 * @throws RuntimeException When no usable rate exists.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private function bookingDateRate(): array {
		$this->bookingRate ??= $this->fxResolver()->resolve(
			transactionCurrency: $this->booking['currency'],
			baseCurrency: $this->booking['base'],
			date: $this->booking['date'],
			administrationId: $this->booking['administrationId']
		);

		return $this->bookingRate;

	}//end bookingDateRate()

	/**
	 * The snapshot of a rate typed on the line, or null when none is typed.
	 *
	 * @param array<string,mixed> $line   The line.
	 * @param int                 $number Its 1-based position, for the message.
	 *
	 * @return array{rate:float,source:string,date:string}|null
	 *
	 * @throws RuntimeException When the typed rate is not positive or has no reason.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.2
	 */
	private function typedRate(array $line, int $number): ?array {
		if (array_key_exists('typedFxRate', $line) === false) {
			return null;
		}

		self::assertTypedRate(line: $line, number: $number);
		return ['rate' => (float)$line['typedFxRate'], 'source' => 'manual', 'date' => $this->booking['date']];

	}//end typedRate()

	/**
	 * Refuse a typed rate that is not a positive number or has no reason.
	 *
	 * @param array<string,mixed> $line   The line, carrying `typedFxRate`.
	 * @param int                 $number Its 1-based position, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the typed rate is not positive or has no reason.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.2
	 */
	private static function assertTypedRate(array $line, int $number): void {
		if (is_numeric($line['typedFxRate']) === false || (float)$line['typedFxRate'] <= 0.0) {
			throw new RuntimeException(sprintf('The exchange rate typed on line %d must be a positive number.', $number));
		}

		if (trim((string)($line['fxRateReason'] ?? '')) === '') {
			throw new RuntimeException(sprintf('The exchange rate typed on line %d needs a reason.', $number));
		}

	}//end assertTypedRate()

	/**
	 * The typed-rate fields a journal-entry line carries to booking, or none.
	 *
	 * @param array<string,mixed> $line The journal-entry line.
	 *
	 * @return array<string,mixed> `typedFxRate` and `fxRateReason`, or empty when no rate is typed.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.2
	 */
	private static function typedRateFields(array $line): array {
		$rate = ($line['fxRate'] ?? null);
		if ($rate === null || $rate === '') {
			return [];
		}

		return ['typedFxRate' => $rate, 'fxRateReason' => (string)($line['fxRateReason'] ?? '')];

	}//end typedRateFields()

	/**
	 * The rate snapshot columns a booked line writes onto its GL line.
	 *
	 * @param array<string,mixed> $line The booked line.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private static function fxColumns(array $line): array {
		$fx = array_intersect_key((array)($line['fx'] ?? []), array_flip(self::FX_FIELDS));
		return array_filter($fx, static fn (mixed $value): bool => $value !== null);

	}//end fxColumns()

	/**
	 * Refuse when the base amounts no longer balance after rounding, naming the difference.
	 *
	 * @param array<int,array<string,mixed>> $lines The lines in base cents.
	 * @param string                         $label The posting's description.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When debit and credit differ.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	private function assertBalancedInBase(array $lines, string $label): void {
		$debit  = 0;
		$credit = 0;
		foreach ($lines as $line) {
			if ($line['side'] === 'debit') {
				$debit += (int)$line['cents'];
				continue;
			}

			$credit += (int)$line['cents'];
		}

		if ($debit !== $credit) {
			throw new RuntimeException(
				sprintf(
					'%s does not balance in %s after booking at the exchange rate: debit %.2f, credit %.2f, difference %.2f. '
					. 'Type the rate on the lines or correct the amounts.',
					$label,
					$this->booking['base'],
					($debit / 100),
					($credit / 100),
					(abs($debit - $credit) / 100)
				)
			);
		}

	}//end assertBalancedInBase()

	/**
	 * The booking-date rate resolver, built on first use when not injected.
	 *
	 * @return FxAtBookingResolver
	 */
	private function fxResolver(): FxAtBookingResolver {
		if ($this->fxResolver === null) {
			$this->fxResolver = new FxAtBookingResolver(objectService: $this->objectService, appConfig: $this->appConfig);
		}

		return $this->fxResolver;

	}//end fxResolver()

	/**
	 * The tariff, box and VAT split for this posting.
	 *
	 * @return VatLineStamper
	 */
	private function vatStamper(): VatLineStamper {
		if ($this->vatStamper === null) {
			$this->vatStamper = new VatLineStamper(objectService: $this->objectService, register: $this->register());
		}

		return $this->vatStamper;
	}//end vatStamper()

	/**
	 * Add a line's base and VAT to its tariff's group.
	 *
	 * @param array<string, array{base: int, vat: float|null}> $groups    The groups so far, in document order.
	 * @param string                                           $code      The tariff code; empty for a line without one.
	 * @param int                                              $baseCents The line's base.
	 * @param float|null                                       $vatCents  The line's VAT, null when its rate is unknown.
	 *
	 * @return array<string, array{base: int, vat: float|null}>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	private function addToVatGroup(array $groups, string $code, int $baseCents, ?float $vatCents): array {
		$group = ($groups[$code] ?? ['base' => 0, 'vat' => null]);
		$group['base'] += $baseCents;
		if ($vatCents !== null) {
			$group['vat'] = (($group['vat'] ?? 0.0) + $vatCents);
		}

		$groups[$code] = $group;
		return $groups;
	}//end addToVatGroup()

	/**
	 * Take the VAT a sales invoice says it charged per tariff (EN 16931 BG-23)
	 * over a recalculation.
	 *
	 * @param array<string, array{base: int, vat: float|null}> $groups    The groups.
	 * @param array<int|string, mixed>                         $breakdown The invoice's vatBreakdown.
	 *
	 * @return array<string, array{base: int, vat: float|null}>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	private function applyVatBreakdown(array $groups, array $breakdown): array {
		$charged = [];
		foreach ($breakdown as $group) {
			if (is_array($group) === false) {
				continue;
			}

			$code = $this->vatStamper()->saleTariff(line: ['vatCategory' => ($group['category'] ?? ''), 'vatRate' => ($group['rate'] ?? null)]);
			if ($code !== '' && isset($groups[$code]) === true) {
				$charged[$code] = (($charged[$code] ?? 0) + $this->cents(amount: ($group['taxAmount'] ?? 0)));
			}
		}

		foreach ($charged as $code => $cents) {
			$groups[$code]['vat'] = (float)$cents;
		}

		return $groups;
	}//end applyVatBreakdown()

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
				foreach (['costCenterCode', 'projectCode'] as $dimension) {
					if (isset($line[$dimension]) === true) {
						$row[$dimension] = (string)$line[$dimension];
					}
				}

				foreach (['vatTariffCode', 'vatReturnBox', 'vatAmountKind'] as $vatField) {
					if (isset($line[$vatField]) === true) {
						$row[$vatField] = (string)$line[$vatField];
					}
				}

				$row = array_merge($row, self::fxColumns(line: $line));

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
		$configured = trim($this->appConfig->getValueString(AppIdentity::APP_ID, $key, ''));
		if ($configured !== '') {
			return $configured;
		}

		return self::DEFAULT_ACCOUNTS[$key];
	}//end account()

	/**
	 * The posting user id, `system` outside a session.
	 *
	 * @return string
	 */
	private function actor(): string {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
