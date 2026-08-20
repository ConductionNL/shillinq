<?php

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

use OCA\Shillinq\Reporting\GeneratedFile;
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
		$administrationId = $this->contextString($context, 'administrationId');
		$period = $this->contextString($context, 'period');

		$accounts = $this->loadAll('Account', $this->administrationFilter($context));
		$transactions = $this->loadAll('GLTransaction', $this->lineFilters($context));
		$allLines = $this->loadAll('GLLine', $this->lineFilters($context));

		// Group lines by their parent transaction id (or transactionNumber).
		$linesByTransaction = [];
		foreach ($allLines as $line) {
			$key = (string)($line['transactionId'] ?? '');
			if ($key === '') {
				continue;
			}

			$linesByTransaction[$key][] = $line;
		}

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElementNs(null, 'AuditFile', self::SAFT_NS);

		// --- Header ---.
		$writer->startElement('Header');
		$writer->writeElement('AuditFileVersion', '2.00');
		$writer->writeElement('AuditFileCountry', 'NL');
		$writer->writeElement('AuditFileDateCreated', gmdate('Y-m-d'));
		$writer->writeElement('SoftwareCompanyName', 'Conduction B.V.');
		$writer->writeElement('SoftwareID', 'Shillinq');
		$writer->startElement('Company');
		$writer->writeElement('RegistrationNumber', ($administrationId !== '' ? $administrationId : 'UNKNOWN'));
		$writer->writeElement('Name', ($administrationId !== '' ? $administrationId : 'Shillinq administration'));
		$writer->endElement();
		$writer->writeElement('DefaultCurrencyCode', 'EUR');
		$writer->startElement('SelectionCriteria');
		$writer->writeElement('PeriodStart', $period);
		$writer->writeElement('PeriodEnd', $period);
		$writer->endElement();
		$writer->endElement();
		// End Header.
		// --- MasterFiles / GeneralLedgerAccounts ---.
		$writer->startElement('MasterFiles');
		$writer->startElement('GeneralLedgerAccounts');
		foreach ($accounts as $account) {
			$accountNumber = (string)($account['accountNumber'] ?? $account['code'] ?? '');
			if ($accountNumber === '') {
				continue;
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
		}//end foreach

		$writer->endElement();
		// End GeneralLedgerAccounts.
		$writer->endElement();
		// End MasterFiles.
		// --- GeneralLedgerEntries ---.
		$writer->startElement('GeneralLedgerEntries');
		$writer->writeElement('NumberOfEntries', (string)count($transactions));

		$totalDebit = 0.0;
		$totalCredit = 0.0;
		foreach ($transactions as $transaction) {
			$debit = 0.0;
			$credit = 0.0;
			$lines = $this->linesFor($transaction, $linesByTransaction);
			foreach ($lines as $line) {
				if ((string)($line['side'] ?? '') === 'debit') {
					$debit += $this->toFloat($line['amount'] ?? 0);
				} else {
					$credit += $this->toFloat($line['amount'] ?? 0);
				}
			}

			$totalDebit += $debit;
			$totalCredit += $credit;
		}

		$writer->writeElement('TotalDebit', $this->money($totalDebit));
		$writer->writeElement('TotalCredit', $this->money($totalCredit));

		// One Journal wrapping all entries (minimal single-journal SAF-T shape).
		$writer->startElement('Journal');
		$writer->writeElement('JournalID', 'GL');
		$writer->writeElement('Description', 'General ledger');
		foreach ($transactions as $transaction) {
			$transactionId = (string)($transaction['id'] ?? $transaction['@self']['id'] ?? $transaction['transactionNumber'] ?? '');
			$writer->startElement('Transaction');
			$writer->writeElement('TransactionID', $transactionId);
			$writer->writeElement('Period', $period);
			$writer->writeElement('TransactionDate', (string)($transaction['postingDate'] ?? $transaction['valueDate'] ?? ''));
			$writer->writeElement('Description', (string)($transaction['description'] ?? ''));
			$writer->writeElement('TransactionType', (string)($transaction['postingKind'] ?? 'N'));

			$lines = $this->linesFor($transaction, $linesByTransaction);
			$writer->startElement('Lines');
			foreach ($lines as $line) {
				$side = ((string)($line['side'] ?? '') === 'debit') ? 'DebitLine' : 'CreditLine';
				$writer->startElement($side);
				$writer->writeElement('RecordID', (string)($line['lineNumber'] ?? $line['id'] ?? ''));
				$writer->writeElement('AccountID', (string)($line['accountNumber'] ?? ''));
				$writer->writeElement('Description', (string)($line['description'] ?? ''));
				$writer->writeElement('Amount', $this->money($this->toFloat($line['amount'] ?? 0)));
				$writer->endElement();
			}

			$writer->endElement();
			// End Lines.
			$writer->endElement();
			// End Transaction.
		}//end foreach

		$writer->endElement();
		// End Journal.
		$writer->endElement();
		// End GeneralLedgerEntries.
		$writer->endElement();
		// End AuditFile.
		$writer->endDocument();

		return new GeneratedFile(
			fileName: $this->fileName('saft', $context, 'xml'),
			mimeType: 'text/xml',
			format: 'xml',
			content: $writer->outputMemory(),
		);

	}//end generate()

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
