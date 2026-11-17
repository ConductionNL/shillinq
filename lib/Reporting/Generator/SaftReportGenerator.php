<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * SAF-T audit-file data-report generator
 *
 * Renders a minimal OECD SAF-T (Standard Audit File for Tax) document natively as
 * XML using XMLWriter. It assembles the Header, the MasterFiles
 * GeneralLedgerAccounts (from the Account schema) and the GeneralLedgerEntries
 * (from GLTransaction + their GLLine children). Rendering is byte-native — no XML
 * DOM or office library is involved. When a schema has no rows the corresponding
 * container element is still emitted (empty but well-formed) so the file always
 * validates against the SAF-T structure rather than fataling.
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
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. Note that
 * bookkeeping-multi-administratie REQ-MA-011 mentions this generator only in
 * passing, as the OECD surface that coexists with XAF — it does not specify
 * SAF-T, so it is not a home for this tag either.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use XMLWriter;

/**
 * Minimal OECD SAF-T XML generator built natively from Account + GLTransaction + GLLine.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class SaftReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * SAF-T OECD namespace URI (schema version 2.00 family).
	 *
	 * @var string
	 */
	private const SAFT_NS = 'urn:OECD:StandardAuditFile-Tax:2.00';

	/**
	 * Construct the SAF-T report generator.
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
		return 'saft';
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
	 * Render the SAF-T audit file for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'xml'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$data   = $this->loadSaftData(context: $context);
		$writer = $this->openXmlDocument();
		$this->writeAuditFile(writer: $writer, data: $data);
		$writer->endDocument();

		return new GeneratedFile(
			fileName: $this->fileName('saft', $context, 'xml'),
			mimeType: 'text/xml',
			format: 'xml',
			content: $writer->outputMemory(),
		);

	}//end generate()

	/**
	 * Write the AuditFile element: header, master files and ledger entries.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string, mixed> $data The loaded data.
	 *
	 * @return void
	 */
	private function writeAuditFile(XMLWriter $writer, array $data): void {
		$writer->startElementNs(null, 'AuditFile', self::SAFT_NS);
		$this->writeHeader(writer: $writer, data: $data);
		$this->writeMasterFiles(writer: $writer, accounts: $data['accounts']);
		$this->writeLedgerEntries(writer: $writer, data: $data);
		$writer->endElement();
	}//end writeAuditFile()

	/**
	 * Load the accounts and the transactions with their lines.
	 *
	 * @param array<string,mixed> $context `{ period?, administrationId? }`.
	 *
	 * @return array<string, mixed> The loaded rows.
	 */
	private function loadSaftData(array $context): array {
		$data = [
			'administrationId' => $this->contextString($context, 'administrationId'),
			'period' => $this->contextString($context, 'period'),
			'accounts' => $this->loadAll('Account', $this->administrationFilter($context)),
		];

		$transactions = $this->loadAll('GLTransaction', $this->lineFilters($context));
		$linesByTransaction = $this->groupLinesByTransaction(lines: $this->loadAll('GLLine', $this->lineFilters($context)));

		$data['entries'] = [];
		foreach ($transactions as $transaction) {
			$data['entries'][] = ['transaction' => $transaction, 'lines' => $this->linesFor($transaction, $linesByTransaction)];
		}

		return $data;
	}//end loadSaftData()

	/**
	 * Group lines by their parent transaction id (or transactionNumber).
	 *
	 * @param array<int, array<string,mixed>> $lines The GLLine rows.
	 *
	 * @return array<string, array<int, array<string,mixed>>>
	 */
	private function groupLinesByTransaction(array $lines): array {
		$linesByTransaction = [];
		foreach ($lines as $line) {
			$key = (string)($line['transactionId'] ?? '');
			if ($key !== '') {
				$linesByTransaction[$key][] = $line;
			}
		}

		return $linesByTransaction;
	}//end groupLinesByTransaction()

	/**
	 * Write the Header block.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string, mixed> $data The loaded data.
	 *
	 * @return void
	 */
	private function writeHeader(XMLWriter $writer, array $data): void {
		$writer->startElement('Header');
		$writer->writeElement('AuditFileVersion', '2.00');
		$writer->writeElement('AuditFileCountry', 'NL');
		$writer->writeElement('AuditFileDateCreated', gmdate('Y-m-d'));
		$writer->writeElement('SoftwareCompanyName', 'Conduction B.V.');
		$writer->writeElement('SoftwareID', 'Shillinq');
		$this->writeCompany(writer: $writer, administrationId: $data['administrationId']);
		$writer->writeElement('DefaultCurrencyCode', 'EUR');
		$this->writeSelectionCriteria(writer: $writer, period: $data['period']);
		$writer->endElement();
	}//end writeHeader()

	/**
	 * Write the Company block of the header.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param string $administrationId The administration, '' when none.
	 *
	 * @return void
	 */
	private function writeCompany(XMLWriter $writer, string $administrationId): void {
		$writer->startElement('Company');
		$writer->writeElement('RegistrationNumber', ($administrationId !== '' ? $administrationId : 'UNKNOWN'));
		$writer->writeElement('Name', ($administrationId !== '' ? $administrationId : 'Shillinq administration'));
		$writer->endElement();
	}//end writeCompany()

	/**
	 * Write the SelectionCriteria block of the header.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param string $period The report period.
	 *
	 * @return void
	 */
	private function writeSelectionCriteria(XMLWriter $writer, string $period): void {
		$writer->startElement('SelectionCriteria');
		$writer->writeElement('PeriodStart', $period);
		$writer->writeElement('PeriodEnd', $period);
		$writer->endElement();
	}//end writeSelectionCriteria()

	/**
	 * Write MasterFiles / GeneralLedgerAccounts.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<int, array<string,mixed>> $accounts The Account rows.
	 *
	 * @return void
	 */
	private function writeMasterFiles(XMLWriter $writer, array $accounts): void {
		$writer->startElement('MasterFiles');
		$writer->startElement('GeneralLedgerAccounts');
		foreach ($accounts as $account) {
			$this->writeAccount(writer: $writer, account: $account);
		}

		$writer->endElement();
		// End GeneralLedgerAccounts.
		$writer->endElement();
	}//end writeMasterFiles()

	/**
	 * Write one Account; an account without a number is skipped.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string,mixed> $account The Account row.
	 *
	 * @return void
	 */
	private function writeAccount(XMLWriter $writer, array $account): void {
		$accountNumber = $this->accountNumber($account);
		if ($accountNumber === '') {
			return;
		}

		$writer->startElement('Account');
		$writer->writeElement('AccountID', $accountNumber);
		$writer->writeElement('AccountDescription', (string)($account['name'] ?? ''));
		$writer->writeElement('AccountType', (string)($account['accountType'] ?? 'GL'));
		$parent = (string)($account['parentAccountNumber'] ?? '');
		if ($parent !== '') {
			$writer->writeElement('StandardAccountID', $parent);
		}

		$writer->endElement();
	}//end writeAccount()

	/**
	 * Write GeneralLedgerEntries: the totals and one Journal wrapping all
	 * entries (minimal single-journal SAF-T shape).
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string, mixed> $data The loaded data.
	 *
	 * @return void
	 */
	private function writeLedgerEntries(XMLWriter $writer, array $data): void {
		$totals = $this->entryTotals(entries: $data['entries']);
		$writer->startElement('GeneralLedgerEntries');
		$writer->writeElement('NumberOfEntries', (string)count($data['entries']));
		$writer->writeElement('TotalDebit', $this->money($totals['debit']));
		$writer->writeElement('TotalCredit', $this->money($totals['credit']));
		$writer->startElement('Journal');
		$writer->writeElement('JournalID', 'GL');
		$writer->writeElement('Description', 'General ledger');
		foreach ($data['entries'] as $entry) {
			$this->writeTransaction(writer: $writer, entry: $entry + ['period' => $data['period']]);
		}

		$writer->endElement();
		// End Journal.
		$writer->endElement();
	}//end writeLedgerEntries()

	/**
	 * Sum the debit and credit amounts, per transaction first.
	 *
	 * @param array<int, array{transaction: array<string,mixed>, lines: array<int, array<string,mixed>>}> $entries The transactions with their lines.
	 *
	 * @return array{debit: float, credit: float}
	 */
	private function entryTotals(array $entries): array {
		$totals = ['debit' => 0.0, 'credit' => 0.0];
		foreach ($entries as $entry) {
			$sums = ['debit' => 0.0, 'credit' => 0.0];
			foreach ($entry['lines'] as $line) {
				$sums[$this->isDebitLine($line) === true ? 'debit' : 'credit'] += $this->lineAmountValue($line);
			}

			$totals['debit']  += $sums['debit'];
			$totals['credit'] += $sums['credit'];
		}

		return $totals;
	}//end entryTotals()

	/**
	 * Write one Transaction with its debit and credit lines.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array{transaction: array<string,mixed>, lines: array<int, array<string,mixed>>, period: string} $entry The entry and the period.
	 *
	 * @return void
	 */
	private function writeTransaction(XMLWriter $writer, array $entry): void {
		$transaction = $entry['transaction'];
		$writer->startElement('Transaction');
		$writer->writeElement('TransactionID', $this->transactionId($transaction));
		$writer->writeElement('Period', $entry['period']);
		$writer->writeElement('TransactionDate', (string)($transaction['postingDate'] ?? $transaction['valueDate'] ?? ''));
		$writer->writeElement('Description', (string)($transaction['description'] ?? ''));
		$writer->writeElement('TransactionType', (string)($transaction['postingKind'] ?? 'N'));
		$writer->startElement('Lines');
		foreach ($entry['lines'] as $line) {
			$this->writeLine(writer: $writer, line: $line);
		}

		$writer->endElement();
		// End Lines.
		$writer->endElement();
	}//end writeTransaction()

	/**
	 * Write one DebitLine or CreditLine.
	 *
	 * @param XMLWriter $writer The active writer.
	 * @param array<string,mixed> $line The GLLine row.
	 *
	 * @return void
	 */
	private function writeLine(XMLWriter $writer, array $line): void {
		$writer->startElement($this->isDebitLine($line) === true ? 'DebitLine' : 'CreditLine');
		$writer->writeElement('RecordID', $this->lineRecordId($line));
		$writer->writeElement('AccountID', (string)($line['accountNumber'] ?? ''));
		$writer->writeElement('Description', (string)($line['description'] ?? ''));
		$writer->writeElement('Amount', $this->money($this->lineAmountValue($line)));
		$writer->endElement();
	}//end writeLine()

	/**
	 * Resolve the GLLine children for a transaction by id or transactionNumber.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction row.
	 * @param array<string,array<int,array<string,mixed>>> $linesByTransaction Lines indexed by transactionId key.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function linesFor(array $transaction, array $linesByTransaction): array {
		// Lines embedded on the transaction take precedence.
		if (is_array($transaction['lines'] ?? null) === true && $transaction['lines'] !== []) {
			return $this->normaliseRows($transaction['lines']);
		}

		foreach ([(string)($transaction['id'] ?? $transaction['@self']['id'] ?? ''), (string)($transaction['transactionNumber'] ?? '')] as $key) {
			if ($key !== '' && isset($linesByTransaction[$key]) === true) {
				return $linesByTransaction[$key];
			}
		}

		return [];
	}//end linesFor()
}//end class
