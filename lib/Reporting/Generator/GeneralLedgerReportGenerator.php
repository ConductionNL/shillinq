<?php

/**
 * General-ledger (grootboekkaarten) data-report generator
 *
 * Renders the general ledger natively as CSV: every GLLine row, grouped (sorted) by
 * accountNumber, with the running per-account balance carried down each card. The
 * account name is joined from the Account schema. Rendering is byte-native
 * (fputcsv into php://temp) — no spreadsheet library is used.
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
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * General-ledger CSV generator built natively from GLLine + Account.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class GeneralLedgerReportGenerator implements ReportGeneratorInterface {

	use ReportDataTrait;

	/**
	 * Construct the general-ledger report generator.
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
		return 'general-ledger';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public static function supportedFormats(): array {
		return ['csv'];
	}//end supportedFormats()

	/**
	 * Render the general ledger for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'csv'.
	 *
	 * @return GeneratedFile
	 */
	public function generate(array $context, string $format): GeneratedFile {
		$lines = $this->loadAll('GLLine', $this->lineFilters($context));
		$accounts = $this->indexAccountsByNumber($this->loadAll('Account', $this->administrationFilter($context)));

		// Group lines by accountNumber.
		$byAccount = [];
		foreach ($lines as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber === '') {
				$accountNumber = '(unassigned)';
			}

			$byAccount[$accountNumber][] = $line;
		}

		ksort($byAccount);

		$handle = fopen('php://temp', 'r+');
		fputcsv(
			$handle,
			[
				'accountNumber',
				'accountName',
				'lineNumber',
				'transactionId',
				'date',
				'description',
				'debit',
				'credit',
				'runningBalance',
			]
		);

		foreach ($byAccount as $accountNumber => $accountLines) {
			$accountName = (string)($accounts[$accountNumber]['name'] ?? '');
			$running = 0.0;

			// Stable order within a card: by lineNumber then transactionId.
			usort(
				$accountLines,
				static function (array $left, array $right): int {
					$byLine = (((int)($left['lineNumber'] ?? 0)) <=> ((int)($right['lineNumber'] ?? 0)));
					if ($byLine !== 0) {
						return $byLine;
					}

					return strcmp((string)($left['transactionId'] ?? ''), (string)($right['transactionId'] ?? ''));
				}
			);

			foreach ($accountLines as $line) {
				$amount = $this->toFloat($line['amount'] ?? 0);
				$debit = 0.0;
				$credit = 0.0;
				if ((string)($line['side'] ?? '') === 'debit') {
					$debit = $amount;
					$running += $amount;
				} else {
					$credit = $amount;
					$running -= $amount;
				}

				fputcsv(
					$handle,
					[
						$accountNumber,
						$accountName,
						(string)($line['lineNumber'] ?? ''),
						(string)($line['transactionId'] ?? ''),
						(string)($line['postingDate'] ?? $line['valueDate'] ?? ''),
						(string)($line['description'] ?? ''),
						$this->money($debit),
						$this->money($credit),
						$this->money($running),
					]
				);
			}//end foreach
		}//end foreach

		rewind($handle);
		$content = (string)stream_get_contents($handle);
		fclose($handle);

		return new GeneratedFile(
			fileName: $this->fileName('general-ledger', $context, 'csv'),
			mimeType: 'text/csv',
			format: 'csv',
			content: $content,
		);

	}//end generate()
}//end class
