<?php

/**
 * Turns an integriq bank feed batch into a statement, and books its exact matches.
 *
 * integriq's BankfeedSyncService persists every pull as an `integriq` /
 * `bankfeed_batch` object and emits `nl.conduction.bankfeed.transactions.synced`
 * with `{connectionId, accountIban, since, until, transactionCount, batchUri}`.
 * This service reads the batch behind the `batchUri`, finds the shillinq bank
 * account by IBAN (which names the administration), normalises the aggregator
 * rows, writes them through StatementIntakeService and offers each new line to
 * ExactMatchBooker. The bank account's `lastSyncAt` records the pull.
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
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
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
 * Bank feed batch intake (REQ-BCON-002, REQ-BCON-003).
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
 */
class BankfeedIntakeService {
	/**
	 * integriq's register.
	 *
	 * @var string
	 */
	public const INTEGRIQ_REGISTER = 'integriq';

	/**
	 * integriq's batch schema.
	 *
	 * @var string
	 */
	public const BATCH_SCHEMA = 'bankfeed_batch';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param StatementIntakeService $intake        Writes the statement and lines.
	 * @param ExactMatchBooker       $booker        Books exact matches on arrival.
	 * @param SettingsService        $settings      Register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly StatementIntakeService $intake,
		private readonly ExactMatchBooker $booker,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Take one synced event's data.
	 *
	 * @param array<string,mixed> $data The CloudEvent `data`.
	 *
	 * @return array{result:string,statementId:string,lines:int,booked:int}
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
	 */
	public function ingestSynced(array $data): array {
		$iban = self::normaliseIban(iban: (string)($data['accountIban'] ?? ''));
		$batchUri = trim((string)($data['batchUri'] ?? ''));
		$empty = ['statementId' => '', 'lines' => 0, 'booked' => 0];
		if ($iban === '' || $batchUri === '') {
			return ['result' => 'incomplete'] + $empty;
		}

		$account = $this->findBankAccount(iban: $iban);
		if ($account === null) {
			$this->logger->info('BankfeedIntakeService: no shillinq bank account for the fed IBAN; skipped', ['iban' => $iban]);
			return ['result' => 'unknown-account'] + $empty;
		}

		$batch = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister(self::INTEGRIQ_REGISTER)->setSchema(self::BATCH_SCHEMA),
			id: (string)basename($batchUri)
		);
		if ($batch === null) {
			$this->logger->warning('BankfeedIntakeService: batch not found', ['batchUri' => $batchUri]);
			return ['result' => 'batch-not-found'] + $empty;
		}

		$lines = array_map(
			static fn (array $row): array => self::normaliseTransaction(row: $row),
			array_values(array_filter((array)($batch['transactions'] ?? []), 'is_array'))
		);
		$administrationId = (string)($account['administrationId'] ?? '');
		$result = $this->intake->ingest(
			administrationId: $administrationId,
			iban: $iban,
			lines: $lines,
			meta: [
				'source' => 'feed',
				'format' => 'feed',
				'bankConnectionId' => (string)($data['connectionId'] ?? ($account['bankConnectionId'] ?? StatementIntakeService::NO_CONNECTION)),
				'sourceBatchUri' => $batchUri,
				'statementDate' => (string)($data['until'] ?? ''),
			]
		);
		if ($result['duplicateBatch'] === true) {
			return ['result' => 'duplicate-batch'] + $empty;
		}

		$booked = 0;
		foreach ($result['written'] as $line) {
			if ($this->booker->book(line: $line) !== null) {
				$booked++;
			}
		}

		$this->scoped(schema: 'BankAccount')->patchObject(
			(string)$account['id'],
			['lastSyncAt' => (string)($data['until'] ?? gmdate('Y-m-d\TH:i:s\Z'))]
		);

		return ['result' => 'written', 'statementId' => $result['statementId'], 'lines' => count($result['written']), 'booked' => $booked];

	}//end ingestSynced()

	/**
	 * One aggregator row as a normalised statement line.
	 *
	 * Reads the Berlin Group (NextGenPSD2) shape the aggregators return, and
	 * the flat shape a file parser produces.
	 *
	 * @param array<string,mixed> $row The aggregator row.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
	 */
	public static function normaliseTransaction(array $row): array {
		$amount = (float)($row['transactionAmount']['amount'] ?? ($row['amount'] ?? 0));
		$counterName = (string)($row['debtorName'] ?? '');
		$counterAccount = (array)($row['debtorAccount'] ?? []);
		if ($amount < 0) {
			$counterName = (string)($row['creditorName'] ?? '');
			$counterAccount = (array)($row['creditorAccount'] ?? []);
		}

		$remittance = $row['remittanceInformationUnstructured'] ?? ($row['remittanceInfo'] ?? ($row['description'] ?? ''));
		if (is_array($remittance) === true) {
			$remittance = implode(' ', array_map('strval', $remittance));
		}

		return [
			'valueDate' => (string)($row['valueDate'] ?? ($row['bookingDate'] ?? '')),
			'amount' => $amount,
			'currency' => (string)($row['transactionAmount']['currency'] ?? ($row['currency'] ?? 'EUR')),
			'counterpartyName' => (string)($row['counterpartyName'] ?? $counterName),
			'counterpartyIban' => (string)($row['counterpartyIban'] ?? ($counterAccount['iban'] ?? '')),
			'endToEndRef' => (string)($row['endToEndId'] ?? ($row['endToEndRef'] ?? ($row['transactionId'] ?? ''))),
			'remittanceInfo' => (string)$remittance,
			'raw' => $row,
		];

	}//end normaliseTransaction()

	/**
	 * The shillinq bank account with this IBAN.
	 *
	 * @param string $iban Normalised IBAN.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findBankAccount(string $iban): ?array {
		$rows = $this->scoped(schema: 'BankAccount')->findAll(['filters' => ['iban' => $iban], 'limit' => 1]);
		return ObjectIdentifier::recordWithId(candidate: ($rows[0] ?? null));

	}//end findBankAccount()

	/**
	 * IBAN without spaces, upper case.
	 *
	 * @param string $iban The IBAN.
	 *
	 * @return string
	 */
	private static function normaliseIban(string $iban): string {
		return strtoupper(str_replace(' ', '', trim($iban)));

	}//end normaliseIban()

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
