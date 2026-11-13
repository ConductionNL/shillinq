<?php

/**
 * XAF 3.2 Auditfile Financieel generator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Renders the Dutch **Auditfile Financieel (XAF 3.2)** — the national hand-over
 * format between a bookkeeping package and an accountant or the Belastingdienst,
 * maintained by XBRL Nederland / the Belastingdienst under the namespace
 * `http://www.auditfiles.nl/XAF/3.2`. It is built byte-native with XMLWriter
 * (no XML DOM, no office library) on the exact pattern proven by
 * {@see SaftReportGenerator}: `implements ReportGeneratorInterface`,
 * `use ReportDataTrait`, empty-but-well-formed containers when a block has no
 * rows so the document always validates against the XAF structure.
 *
 * This is DELIBERATELY a different file from the OECD SAF-T generator
 * (`SaftReportGenerator`, `urn:OECD:StandardAuditFile-Tax:2.00`). SAF-T is the
 * international OECD standard; XAF is the Dutch national standard. A request for
 * "het auditfile" in the Netherlands means XAF 3.2 — the two MUST NOT be
 * conflated (that conflation is exactly the readiness bug this change fixes).
 *
 * Block mapping (source schema → XAF element):
 *  - `header`               ← report context (fiscalYear/period, currency);
 *  - `company`              ← the administration identity;
 *  - `generalLedger`        ← `Account` (the RGS-coded chart of accounts);
 *  - `customersSuppliers`   ← `CustomerMaster` (AR, custSupTp `C`) + `Payee`
 *                             (AP, custSupTp `S`);
 *  - `transactions`         ← `GLTransaction` + their `GLLine` children.
 *
 * Every source query is scoped strictly to the context `administrationId`
 * (belt-and-suspenders: both the OpenRegister filter AND an in-PHP guard), and
 * journal lines are joined only to in-scope transactions — no account,
 * relation, or line belonging to another administration can appear in the file.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Kernel\Contracts\XafAuditfileGeneratorInterface;
use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use XMLWriter;

/**
 * Dutch XAF 3.2 Auditfile Financieel generator, byte-native via XMLWriter.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
final class XafAuditfileGenerator implements ReportGeneratorInterface, XafAuditfileGeneratorInterface {

	use ReportDataTrait;

	/**
	 * The Dutch XAF 3.2 namespace URI (Belastingdienst / XBRL Nederland).
	 *
	 * @var string
	 */
	public const XAF_NS = 'http://www.auditfiles.nl/XAF/3.2';

	/**
	 * Construct the XAF report generator.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function reportType(): string {
		return 'xaf';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['xml'];
	}//end supportedFormats()

	/**
	 * Render the XAF 3.2 audit file for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'xml'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$data   = $this->loadAuditData(context: $context);
		$writer = $this->openXmlDocument();
		$writer->startElementNs(null, 'auditfile', self::XAF_NS);
		$this->writeHeader(writer: $writer, data: $data);
		$this->writeCompany(writer: $writer, data: $data);
		$writer->endElement();
		// End auditfile.
		$writer->endDocument();

		return new GeneratedFile(
			fileName: $this->fileName('xaf', $context, 'xml'),
			mimeType: 'application/xml',
			format: 'xml',
			content: $writer->outputMemory(),
		);

	}//end generate()

	/**
	 * Load every source, then re-assert the administration scope in PHP so a
	 * mis-configured or filter-ignoring data layer still cannot leak a row.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 *
	 * @return array<string, mixed> The scoped rows plus the transactions with their lines.
	 */
	private function loadAuditData(array $context): array {
		$administrationId = $this->contextString($context, 'administrationId');
		$data = ['administrationId' => $administrationId, 'period' => $this->contextString($context, 'period')];
		foreach (['accounts' => 'Account', 'customers' => 'CustomerMaster', 'payees' => 'Payee'] as $key => $schema) {
			$data[$key] = $this->scopeToAdministration($this->loadAll($schema, $this->administrationFilter($context)), $administrationId);
		}

		$transactions = $this->scopeToAdministration($this->loadAll('GLTransaction', $this->lineFilters($context)), $administrationId);
		$lines = $this->loadAll('GLLine', $this->lineFilters($context));
		$data['entries'] = $this->transactionEntries(transactions: $transactions, lines: $lines);

		return $data;
	}//end loadAuditData()

	/**
	 * Pair every transaction with its lines. GLLine carries no
	 * administrationId, so its isolation is enforced purely by the join on
	 * the in-scope transaction ids.
	 *
	 * @param array<int, array<string,mixed>> $transactions The in-scope transactions.
	 * @param array<int, array<string,mixed>> $lines Every loaded GLLine.
	 *
	 * @return array<int, array{transaction: array<string,mixed>, lines: array<int, array<string,mixed>>}>
	 */
	private function transactionEntries(array $transactions, array $lines): array {
		$ids = array_filter(array_map($this->transactionId(...), $transactions), static fn (string $id): bool => $id !== '');
		$transactionIds = array_fill_keys($ids, true);

		$linesByTransaction = [];
		foreach ($lines as $line) {
			$key = (string)($line['transactionId'] ?? '');
			if ($key !== '' && isset($transactionIds[$key]) === true) {
				$linesByTransaction[$key][] = $line;
			}
		}

		return array_map(
			fn (array $transaction): array => ['transaction' => $transaction, 'lines' => $this->linesFor($transaction, $linesByTransaction)],
			$transactions
		);
	}//end transactionEntries()

	/**
	 * Write the header block.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string, mixed> $data The loaded audit data.
	 *
	 * @return void
	 */
	private function writeHeader(XMLWriter $writer, array $data): void {
		$fiscalYear = $this->fiscalYear($data['period']);
		$writer->startElement('header');
		$writer->writeElement('fiscalYear', $fiscalYear);
		$writer->writeElement('startDate', $fiscalYear . '-01-01');
		$writer->writeElement('endDate', $fiscalYear . '-12-31');
		$writer->writeElement('curCode', 'EUR');
		$writer->writeElement('dateCreated', gmdate('Y-m-d'));
		$writer->writeElement('softwareDesc', 'Shillinq');
		$writer->endElement();
	}//end writeHeader()

	/**
	 * Write the company block: ledger, customers and suppliers, transactions.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string, mixed> $data The loaded audit data.
	 *
	 * @return void
	 */
	private function writeCompany(XMLWriter $writer, array $data): void {
		$administrationId = $data['administrationId'];
		$writer->startElement('company');
		$writer->writeElement('companyName', ($administrationId !== '' ? $administrationId : 'Shillinq administration'));
		$writer->writeElement('companyIdent', ($administrationId !== '' ? $administrationId : 'UNKNOWN'));
		$writer->writeElement('currencyCode', 'EUR');
		$this->writeGeneralLedger(writer: $writer, accounts: $data['accounts']);
		$writer->startElement('customersSuppliers');
		$this->writeCustomers(writer: $writer, customers: $data['customers']);
		$this->writeSuppliers(writer: $writer, payees: $data['payees']);
		$writer->endElement();
		$this->writeTransactions(writer: $writer, entries: $data['entries']);
		$writer->endElement();
	}//end writeCompany()

	/**
	 * Write the generalLedger block (the chart of accounts).
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<int, array<string,mixed>> $accounts The Account rows.
	 *
	 * @return void
	 */
	private function writeGeneralLedger(XMLWriter $writer, array $accounts): void {
		$writer->startElement('generalLedger');
		foreach ($accounts as $account) {
			$this->writeLedgerAccount(writer: $writer, account: $account);
		}

		$writer->endElement();
	}//end writeGeneralLedger()

	/**
	 * Write one ledgerAccount; an account without a number is skipped.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string,mixed> $account The Account row.
	 *
	 * @return void
	 */
	private function writeLedgerAccount(XMLWriter $writer, array $account): void {
		$accountNumber = $this->accountNumber($account);
		if ($accountNumber === '') {
			return;
		}

		$writer->startElement('ledgerAccount');
		$writer->writeElement('accID', $accountNumber);
		$writer->writeElement('accDesc', (string)($account['name'] ?? $account['description'] ?? ''));
		$writer->writeElement('accTp', $this->accountType((string)($account['accountType'] ?? '')));
		$writer->endElement();
	}//end writeLedgerAccount()

	/**
	 * Write the AR customers as customerSupplier blocks of type C.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<int, array<string,mixed>> $customers The CustomerMaster rows.
	 *
	 * @return void
	 */
	private function writeCustomers(XMLWriter $writer, array $customers): void {
		foreach ($customers as $customer) {
			$this->writeCustomerSupplier(
				$writer,
				'C',
				(string)($customer['customerId'] ?? ($customer['customerNumber'] ?? '')),
				$customer
			);
		}
	}//end writeCustomers()

	/**
	 * Write the AP suppliers as customerSupplier blocks of type S.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<int, array<string,mixed>> $payees The Payee rows.
	 *
	 * @return void
	 */
	private function writeSuppliers(XMLWriter $writer, array $payees): void {
		foreach ($payees as $payee) {
			$this->writeCustomerSupplier($writer, 'S', (string)($payee['vendorNumber'] ?? ''), $payee);
		}
	}//end writeSuppliers()

	/**
	 * Write the transactions block: one journal wrapping all GL transactions.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<int, array{transaction: array<string,mixed>, lines: array<int, array<string,mixed>>}> $entries The transactions with their lines.
	 *
	 * @return void
	 */
	private function writeTransactions(XMLWriter $writer, array $entries): void {
		$writer->startElement('transactions');
		$writer->writeElement('linesCount', (string)array_sum(array_map(static fn (array $entry): int => count($entry['lines']), $entries)));
		$writer->writeElement('transactionCount', (string)count($entries));
		$writer->startElement('journal');
		$writer->writeElement('jrnID', 'GL');
		$writer->writeElement('desc', 'General ledger');
		$writer->writeElement('jrnTp', 'Z');
		foreach ($entries as $entry) {
			$this->writeTransaction(writer: $writer, entry: $entry);
		}

		$writer->endElement();
		// End journal.
		$writer->endElement();
	}//end writeTransactions()

	/**
	 * Write one transaction with its trLine children.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array{transaction: array<string,mixed>, lines: array<int, array<string,mixed>>} $entry The transaction and its lines.
	 *
	 * @return void
	 */
	private function writeTransaction(XMLWriter $writer, array $entry): void {
		$transaction = $entry['transaction'];
		$writer->startElement('transaction');
		$writer->writeElement('nr', (string)($transaction['transactionNumber'] ?? $this->transactionId($transaction)));
		$writer->writeElement('desc', (string)($transaction['description'] ?? ''));
		$writer->writeElement('trDt', (string)($transaction['postingDate'] ?? ''));
		foreach ($entry['lines'] as $line) {
			$this->writeTransactionLine(writer: $writer, line: $line);
		}

		$writer->endElement();
	}//end writeTransaction()

	/**
	 * Write one trLine.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string,mixed> $line The GLLine row.
	 *
	 * @return void
	 */
	private function writeTransactionLine(XMLWriter $writer, array $line): void {
		$writer->startElement('trLine');
		$writer->writeElement('nr', $this->lineRecordId($line));
		$writer->writeElement('accID', (string)($line['accountNumber'] ?? ''));
		$writer->writeElement('desc', (string)($line['description'] ?? ''));
		$writer->writeElement('amnt', $this->money($this->lineAmountValue($line)));
		$writer->writeElement('amntTp', ($this->isDebitLine($line) === true ? 'D' : 'C'));
		$writer->endElement();
	}//end writeTransactionLine()

	/**
	 * Write one customerSupplier block.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param string $type 'C' (customer) or 'S' (supplier).
	 * @param string $id The custSupID.
	 * @param array<string,mixed> $row The source master row.
	 *
	 * @return void
	 */
	private function writeCustomerSupplier(XMLWriter $writer, string $type, string $id, array $row): void {
		if ($id === '') {
			return;
		}

		$writer->startElement('customerSupplier');
		$writer->writeElement('custSupID', $id);
		$writer->writeElement('custSupTp', $type);
		$writer->writeElement(
			'companyName',
			(string)($row['name'] ?? ($row['legalName'] ?? ($row['tradingName'] ?? ($row['tradeName'] ?? ''))))
		);
		$kvk = (string)($row['kvkNumber'] ?? '');
		if ($kvk !== '') {
			$writer->writeElement('companyIdent', $kvk);
		}

		$this->writeTaxRegistration(writer: $writer, row: $row);
		$this->writeContact(writer: $writer, row: $row);

		$writer->endElement();
		// End customerSupplier.
	}//end writeCustomerSupplier()

	/**
	 * Write the taxRegistration element when the row carries a VAT number.
	 *
	 * @param XMLWriter $writer The writer.
	 * @param array<string,mixed> $row The customer or supplier row.
	 *
	 * @return void
	 */
	private function writeTaxRegistration(XMLWriter $writer, array $row): void {
		$vat = (string)($row['vatNumber'] ?? ($row['vatID'] ?? ($row['vatId'] ?? '')));
		if ($vat === '') {
			return;
		}

		$writer->startElement('taxRegistration');
		$writer->writeElement('taxRegIdent', $vat);
		$writer->endElement();

	}//end writeTaxRegistration()

	/**
	 * Write the contact element when the row carries an e-mail address or phone number.
	 *
	 * @param XMLWriter $writer The writer.
	 * @param array<string,mixed> $row The customer or supplier row.
	 *
	 * @return void
	 */
	private function writeContact(XMLWriter $writer, array $row): void {
		$email = (string)($row['email'] ?? '');
		$phone = (string)($row['phone'] ?? ($row['telephone'] ?? ''));
		if ($email === '' && $phone === '') {
			return;
		}

		$writer->startElement('contact');
		if ($email !== '') {
			$writer->writeElement('eMail', $email);
		}

		if ($phone !== '') {
			$writer->writeElement('telephone', $phone);
		}

		$writer->endElement();

	}//end writeContact()

	/**
	 * Keep only rows belonging to the context administration.
	 *
	 * When no administration is set the input is returned unchanged (a
	 * cross-administration export is a deliberate, separate concern). Rows
	 * without an administrationId are dropped when a scope is in force.
	 *
	 * @param array<int, array<string,mixed>> $rows Source rows.
	 * @param string $administrationId The scope, '' = no scope.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function scopeToAdministration(array $rows, string $administrationId): array {
		if ($administrationId === '') {
			return $rows;
		}

		$out = [];
		foreach ($rows as $row) {
			if ((string)($row['administrationId'] ?? '') === $administrationId) {
				$out[] = $row;
			}
		}

		return $out;
	}//end scopeToAdministration()

	/**
	 * Resolve the GLLine children for a transaction by id or transactionNumber.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction row.
	 * @param array<string,array<int,array<string,mixed>>> $linesByTransaction Lines indexed by transactionId key.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function linesFor(array $transaction, array $linesByTransaction): array {
		if (is_array($transaction['lines'] ?? null) === true && $transaction['lines'] !== []) {
			return $this->normaliseRows($transaction['lines']);
		}

		$key = $this->transactionId($transaction);
		if ($key !== '' && isset($linesByTransaction[$key]) === true) {
			return $linesByTransaction[$key];
		}

		return [];
	}//end linesFor()

	/**
	 * Map a shillinq account type to the XAF account-type code (B balans / P w&v).
	 *
	 * @param string $accountType The shillinq accountType.
	 *
	 * @return string 'B' or 'P'.
	 */
	private function accountType(string $accountType): string {
		$normalised = strtolower($accountType);
		foreach (['revenue', 'income', 'expense', 'cost', 'pnl', 'profit', 'loss', 'winst', 'verlies', 'opbrengst', 'kosten'] as $needle) {
			if (str_contains($normalised, $needle) === true) {
				return 'P';
			}
		}

		return 'B';
	}//end accountType()

	/**
	 * Derive the four-digit fiscal year from a period string (fallback: this year).
	 *
	 * @param string $period The report period (e.g. '2026', '2026-Q1', '2026-03').
	 *
	 * @return string
	 */
	private function fiscalYear(string $period): string {
		if (preg_match('/(\d{4})/', $period, $match) === 1) {
			return $match[1];
		}

		return gmdate('Y');
	}//end fiscalYear()
}//end class
