<?php

/**
 * XAF 3.2 Auditfile Financieel generator
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

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
final class XafAuditfileGenerator implements ReportGeneratorInterface {

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
		$administrationId = $this->contextString($context, 'administrationId');
		$period = $this->contextString($context, 'period');
		$fiscalYear = $this->fiscalYear($period);

		// Load every source, then re-assert the administration scope in PHP so a
		// mis-configured or filter-ignoring data layer still cannot leak a row.
		$accounts = $this->scopeToAdministration($this->loadAll('Account', $this->administrationFilter($context)), $administrationId);
		$customers = $this->scopeToAdministration($this->loadAll('CustomerMaster', $this->administrationFilter($context)), $administrationId);
		$payees = $this->scopeToAdministration($this->loadAll('Payee', $this->administrationFilter($context)), $administrationId);
		$transactions = $this->scopeToAdministration($this->loadAll('GLTransaction', $this->lineFilters($context)), $administrationId);
		$allLines = $this->loadAll('GLLine', $this->lineFilters($context));

		// In-scope transaction ids — GLLine carries no administrationId, so its
		// isolation is enforced purely by the transaction join.
		$transactionIds = [];
		foreach ($transactions as $transaction) {
			$tid = $this->transactionId($transaction);
			if ($tid !== '') {
				$transactionIds[$tid] = true;
			}
		}

		$linesByTransaction = [];
		foreach ($allLines as $line) {
			$key = (string)($line['transactionId'] ?? '');
			if ($key === '' || isset($transactionIds[$key]) === false) {
				continue;
			}

			$linesByTransaction[$key][] = $line;
		}

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElementNs(null, 'auditfile', self::XAF_NS);

		// --- header ---.
		$writer->startElement('header');
		$writer->writeElement('fiscalYear', $fiscalYear);
		$writer->writeElement('startDate', $fiscalYear . '-01-01');
		$writer->writeElement('endDate', $fiscalYear . '-12-31');
		$writer->writeElement('curCode', 'EUR');
		$writer->writeElement('dateCreated', gmdate('Y-m-d'));
		$writer->writeElement('softwareDesc', 'Shillinq');
		$writer->endElement();
		// End header.
		// --- company ---.
		$writer->startElement('company');
		$writer->writeElement('companyName', ($administrationId !== '' ? $administrationId : 'Shillinq administration'));
		$writer->writeElement('companyIdent', ($administrationId !== '' ? $administrationId : 'UNKNOWN'));
		$writer->writeElement('currencyCode', 'EUR');

		// GeneralLedger (chart of accounts from Account).
		$writer->startElement('generalLedger');
		foreach ($accounts as $account) {
			$accountNumber = (string)($account['accountNumber'] ?? $account['code'] ?? '');
			if ($accountNumber === '') {
				continue;
			}

			$writer->startElement('ledgerAccount');
			$writer->writeElement('accID', $accountNumber);
			$writer->writeElement('accDesc', (string)($account['name'] ?? $account['description'] ?? ''));
			$writer->writeElement('accTp', $this->accountType((string)($account['accountType'] ?? '')));
			$writer->endElement();
		}

		$writer->endElement();
		// End generalLedger.
		// customersSuppliers (AR customers + AP suppliers).
		$writer->startElement('customersSuppliers');
		foreach ($customers as $customer) {
			$this->writeCustomerSupplier(
				$writer,
				'C',
				(string)($customer['customerNumber'] ?? ''),
				$customer
			);
		}

		foreach ($payees as $payee) {
			$this->writeCustomerSupplier(
				$writer,
				'S',
				(string)($payee['vendorNumber'] ?? ''),
				$payee
			);
		}

		$writer->endElement();
		// End customersSuppliers.
		// transactions (one journal wrapping all GL transactions).
		$writer->startElement('transactions');
		$writer->writeElement('linesCount', (string)$this->countLines($transactions, $linesByTransaction));
		$writer->writeElement('transactionCount', (string)count($transactions));
		$writer->startElement('journal');
		$writer->writeElement('jrnID', 'GL');
		$writer->writeElement('desc', 'General ledger');
		$writer->writeElement('jrnTp', 'Z');
		foreach ($transactions as $transaction) {
			$writer->startElement('transaction');
			$writer->writeElement('nr', (string)($transaction['transactionNumber'] ?? $this->transactionId($transaction)));
			$writer->writeElement('desc', (string)($transaction['description'] ?? ''));
			$writer->writeElement('trDt', (string)($transaction['postingDate'] ?? ''));
			foreach ($this->linesFor($transaction, $linesByTransaction) as $line) {
				$writer->startElement('trLine');
				$writer->writeElement('nr', (string)($line['lineNumber'] ?? $line['id'] ?? ''));
				$writer->writeElement('accID', (string)($line['accountNumber'] ?? ''));
				$writer->writeElement('desc', (string)($line['description'] ?? ''));
				$writer->writeElement('amnt', $this->money($this->toFloat($line['amount'] ?? 0)));
				$writer->writeElement('amntTp', ((string)($line['side'] ?? '') === 'debit' ? 'D' : 'C'));
				$writer->endElement();
			}

			$writer->endElement();
			// End transaction.
		}//end foreach

		$writer->endElement();
		// End journal.
		$writer->endElement();
		// End transactions.
		$writer->endElement();
		// End company.
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
		$writer->writeElement('companyName', (string)($row['name'] ?? $row['tradingName'] ?? ''));
		$kvk = (string)($row['kvkNumber'] ?? '');
		if ($kvk !== '') {
			$writer->writeElement('companyIdent', $kvk);
		}

		$vat = (string)($row['vatNumber'] ?? '');
		if ($vat !== '') {
			$writer->startElement('taxRegistration');
			$writer->writeElement('taxRegIdent', $vat);
			$writer->endElement();
		}

		$email = (string)($row['email'] ?? '');
		$phone = (string)($row['phone'] ?? '');
		if ($email !== '' || $phone !== '') {
			$writer->startElement('contact');
			if ($email !== '') {
				$writer->writeElement('eMail', $email);
			}

			if ($phone !== '') {
				$writer->writeElement('telephone', $phone);
			}

			$writer->endElement();
		}

		$writer->endElement();
		// End customerSupplier.
	}//end writeCustomerSupplier()

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
	 * The stable id of a GLTransaction (object id, else transactionNumber).
	 *
	 * @param array<string,mixed> $transaction The transaction row.
	 *
	 * @return string
	 */
	private function transactionId(array $transaction): string {
		return (string)($transaction['id'] ?? $transaction['@self']['id'] ?? $transaction['transactionNumber'] ?? '');
	}//end transactionId()

	/**
	 * Total line count across all in-scope transactions (XAF linesCount).
	 *
	 * @param array<int, array<string,mixed>> $transactions In-scope transactions.
	 * @param array<string,array<int,array<string,mixed>>> $linesByTransaction Lines indexed by transactionId.
	 *
	 * @return int
	 */
	private function countLines(array $transactions, array $linesByTransaction): int {
		$count = 0;
		foreach ($transactions as $transaction) {
			$count += count($this->linesFor($transaction, $linesByTransaction));
		}

		return $count;
	}//end countLines()

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
