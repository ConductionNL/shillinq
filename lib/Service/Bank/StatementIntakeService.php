<?php

/**
 * Writes bank statements and their lines, for a file import and for the bank feed alike.
 *
 * Both channels hand over normalised lines (valueDate, amount, currency,
 * counterpartyName, counterpartyIban, endToEndRef, remittanceInfo) and get
 * the same records: one BankStatement and one BankStatementLine per line, each
 * with every field the merged register requires. A feed batch that was already
 * written writes nothing, and a line whose end-to-end reference is already on
 * file for the same account is skipped. After the write, each new line gets one
 * chance to book itself: see ExactMatchBooker.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Bank;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;

/**
 * One intake for statement files and bank feed batches (REQ-BCON-002).
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.1
 */
class StatementIntakeService {
	/**
	 * `bankConnectionId` of a statement no connection delivered.
	 *
	 * The merged schema requires the field in uuid format, so the nil uuid
	 * stands for "imported by file"; the old literal 'manual-import' failed
	 * validation.
	 *
	 * @var string
	 */
	public const NO_CONNECTION = '00000000-0000-0000-0000-000000000000';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Write one statement with its lines.
	 *
	 * @param string                         $administrationId The owning administration.
	 * @param string                         $iban             The bank account IBAN, '' when unknown.
	 * @param array<int,array<string,mixed>> $lines            Normalised lines.
	 * @param array<string,mixed>            $meta             `source` (file|feed), `format`, `bankConnectionId`,
	 *                                                         `sourceBatchUri`, `glAccountId`, `statementDate`,
	 *                                                         `closingBalance`.
	 *
	 * @return array{statementId:string,written:array<int,array<string,mixed>>,skipped:int,duplicateBatch:bool}
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.1
	 */
	public function ingest(string $administrationId, string $iban, array $lines, array $meta): array {
		$batchUri = trim((string)($meta['sourceBatchUri'] ?? ''));
		if ($batchUri !== '' && $this->batchAlreadyWritten(administrationId: $administrationId, batchUri: $batchUri) === true) {
			$this->logger->info('StatementIntakeService: batch already written, nothing to do', ['batchUri' => $batchUri]);
			return ['statementId' => '', 'written' => [], 'skipped' => count($lines), 'duplicateBatch' => true];
		}

		$known = $this->knownEndToEndRefs(administrationId: $administrationId, iban: $iban);
		$fresh = [];
		$skipped = 0;
		foreach ($lines as $line) {
			$ref = trim((string)($line['endToEndRef'] ?? ''));
			if ($ref !== '' && isset($known[$ref]) === true) {
				$skipped++;
				continue;
			}

			if ($ref !== '') {
				$known[$ref] = true;
			}

			$fresh[] = $line;
		}

		$statement = self::buildStatement(administrationId: $administrationId, iban: $iban, lineCount: count($fresh), meta: $meta);
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'BankStatement')->saveObject($statement));
		$statementId = (string)($saved['id'] ?? '');

		$written = [];
		$number = 0;
		foreach ($fresh as $line) {
			$number++;
			$payload = self::buildLine(line: $line, statementId: $statementId, administrationId: $administrationId, lineNumber: $number);
			$written[] = (ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'BankStatementLine')->saveObject($payload)) ?? $payload);
		}

		return ['statementId' => $statementId, 'written' => $written, 'skipped' => $skipped, 'duplicateBatch' => false];

	}//end ingest()

	/**
	 * The BankStatement payload.
	 *
	 * @param string              $administrationId The administration.
	 * @param string              $iban             The account IBAN.
	 * @param int                 $lineCount        Lines written.
	 * @param array<string,mixed> $meta             See ingest().
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.1
	 */
	public static function buildStatement(string $administrationId, string $iban, int $lineCount, array $meta): array {
		$source = (string)($meta['source'] ?? 'file');
		$format = (string)($meta['format'] ?? $source);
		$statement = [
			'bankConnectionId' => (string)($meta['bankConnectionId'] ?? self::NO_CONNECTION),
			// The merged schema admits one statementFormat; importFormat
			// carries the format the statement really arrived in.
			'statementFormat' => 'camt.053.001.08',
			'importFormat' => $format,
			'statementDate' => self::dateTime(value: (string)($meta['statementDate'] ?? '')),
			'transactionCount' => $lineCount,
			'administrationId' => $administrationId,
			'statementSource' => $source,
			'importedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'currency' => 'EUR',
		];
		$optional = [
			'bankAccountIban' => $iban,
			'sourceBatchUri' => (string)($meta['sourceBatchUri'] ?? ''),
			'glAccountId' => (string)($meta['glAccountId'] ?? ''),
		];
		foreach ($optional as $key => $value) {
			if ($value !== '') {
				$statement[$key] = $value;
			}
		}

		if (isset($meta['closingBalance']) === true && is_numeric($meta['closingBalance']) === true) {
			$statement['closingBalance'] = (float)$meta['closingBalance'];
		}

		return $statement;

	}//end buildStatement()

	/**
	 * The BankStatementLine payload, with every field the merged schema requires.
	 *
	 * @param array<string,mixed> $line             The normalised line.
	 * @param string              $statementId      The statement uuid.
	 * @param string              $administrationId The administration.
	 * @param int                 $lineNumber       1-based position.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.1
	 */
	public static function buildLine(array $line, string $statementId, string $administrationId, int $lineNumber): array {
		$ref = trim((string)($line['endToEndRef'] ?? ''));
		$remittance = trim((string)($line['remittanceInfo'] ?? ''));
		$payload = [
			'lineId' => substr($statementId, 0, 8) . '-' . str_pad((string)$lineNumber, 4, '0', STR_PAD_LEFT),
			'statementId' => $statementId,
			'lineNumber' => $lineNumber,
			'valueDate' => substr((string)($line['valueDate'] ?? ''), 0, 10),
			'amount' => round((float)($line['amount'] ?? 0), 2),
			'currency' => (string)($line['currency'] ?? 'EUR'),
			'matchState' => 'unmatched',
			'status' => 'unmatched',
			'administrationId' => $administrationId,
			'counterpartyName' => (string)($line['counterpartyName'] ?? ''),
			'counterpartyIban' => (string)($line['counterpartyIban'] ?? ''),
			'rawPayload' => (string)json_encode($line['raw'] ?? $line),
		];
		$optional = [
			'endToEndRef' => $ref,
			'reference' => $ref,
			'remittanceInfo' => $remittance,
			'narrative' => $remittance,
		];
		foreach ($optional as $key => $value) {
			if ($value !== '') {
				$payload[$key] = $value;
			}
		}

		return $payload;

	}//end buildLine()

	/**
	 * An ISO 8601 date-time: the schema declares `statementDate` as date-time.
	 *
	 * @param string $value A date, a date-time, or '' for now.
	 *
	 * @return string
	 */
	private static function dateTime(string $value): string {
		if ($value === '') {
			return gmdate('Y-m-d\TH:i:s\Z');
		}

		if (strlen($value) === 10) {
			return $value . 'T00:00:00Z';
		}

		return $value;

	}//end dateTime()

	/**
	 * Whether a feed batch was already written as a statement.
	 *
	 * @param string $administrationId The administration.
	 * @param string $batchUri         The batch uri.
	 *
	 * @return bool
	 */
	private function batchAlreadyWritten(string $administrationId, string $batchUri): bool {
		$rows = $this->scoped(schema: 'BankStatement')->findAll(
			['filters' => ['administrationId' => $administrationId, 'sourceBatchUri' => $batchUri], 'limit' => 1]
		);
		return $rows !== [];

	}//end batchAlreadyWritten()

	/**
	 * End-to-end references already on file for the account's statements.
	 *
	 * @param string $administrationId The administration.
	 * @param string $iban             The account IBAN; '' disables the check.
	 *
	 * @return array<string,bool>
	 */
	private function knownEndToEndRefs(string $administrationId, string $iban): array {
		if ($iban === '') {
			return [];
		}

		$known = [];
		$statements = $this->scoped(schema: 'BankStatement')->findAll(
			['filters' => ['administrationId' => $administrationId, 'bankAccountIban' => $iban], 'limit' => 500]
		);
		foreach ($statements as $candidate) {
			$statement = ObjectIdentifier::recordWithId(candidate: $candidate);
			$statementId = (string)($statement['id'] ?? '');
			if ($statementId === '') {
				continue;
			}

			$lines = $this->scoped(schema: 'BankStatementLine')->findAll(['filters' => ['statementId' => $statementId], 'limit' => 5000]);
			foreach ($lines as $row) {
				$ref = trim((string)(ObjectIdentifier::recordWithId(candidate: $row)['endToEndRef'] ?? ''));
				if ($ref !== '') {
					$known[$ref] = true;
				}
			}
		}

		return $known;

	}//end knownEndToEndRefs()

	/**
	 * The object service scoped to shillinq's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
