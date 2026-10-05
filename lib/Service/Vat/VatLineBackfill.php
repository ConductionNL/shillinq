<?php

/**
 * VAT line backfill
 *
 * Stamps the VAT tariff, return box and amount kind on ledger lines that were
 * posted before the posting mapper stamped them, or while the VatTariff
 * records were not there yet (REQ-VBTW-004, design D1 "A backfill does the
 * same for posted lines from their tariff code, else from the account's VAT
 * settings").
 *
 * A line's tariff is its own `vatTariffCode` when it has one. Without one it
 * comes from the account: a revenue or expense account that is VAT
 * applicable takes the tariff of its rate (21 high, 9 low, 0 zero for a
 * purchase), and a line on the output or input VAT account takes the one
 * tariff of its transaction's base lines. The box then follows from the
 * tariff exactly as the mapper sets it (VatLineStamper::stamp()). A
 * VAT-relevant line whose tariff cannot be found is logged by transaction,
 * line and account and left as it is, so the return's first check names it
 * instead of the return silently missing it.
 *
 * Idempotent: a line whose stamps already match is not written.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stamps posted ledger lines with their VAT tariff, box and amount kind.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
 */
class VatLineBackfill {
	use ReadsSourceRowsInBatches;

	/**
	 * The stamp fields, in the order the mapper writes them.
	 */
	private const FIELDS = ['vatTariffCode', 'vatReturnBox', 'vatAmountKind'];

	/**
	 * A tariff still to be taken from the transaction's base lines.
	 */
	private const FROM_BASE_LINES = '?';

	/**
	 * Accounts by administration and number, read once.
	 *
	 * @var array<string, array<string,mixed>|null>
	 */
	private array $accounts = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      Resolves the register slug.
	 * @param IAppConfig             $appConfig     The configured VAT accounts.
	 * @param LoggerInterface        $logger        Logs the lines it cannot resolve.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the lines of every posted and reversed transaction.
	 *
	 * @return array{stamped:int,unresolved:int} Lines written, and VAT-relevant lines left unresolved.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function run(): array {
		$register = $this->settings->getRegisterSlug();
		$stamper  = new VatLineStamper(objectService: $this->objectService, register: $register);
		$result   = ['stamped' => 0, 'unresolved' => 0];

		foreach (['posted', 'reversed'] as $state) {
			$transactions = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $register,
				schema: 'GLTransaction',
				filters: ['state' => $state]
			);
			foreach ($transactions as $transaction) {
				$counts = $this->stampTransaction(
					transaction: $this->rowPayload(row: $transaction),
					transactionId: ObjectIdentifier::resolve(saved: $transaction),
					stamper: $stamper,
					register: $register
				);
				$result['stamped']    += $counts['stamped'];
				$result['unresolved'] += $counts['unresolved'];
			}
		}

		return $result;
	}//end run()

	/**
	 * Stamp the lines of one transaction.
	 *
	 * @param array<string,mixed> $transaction   The GLTransaction.
	 * @param string              $transactionId Its id.
	 * @param VatLineStamper      $stamper       Tariff lookups.
	 * @param string              $register      The register slug.
	 *
	 * @return array{stamped:int,unresolved:int}
	 */
	private function stampTransaction(array $transaction, string $transactionId, VatLineStamper $stamper, string $register): array {
		$counts = ['stamped' => 0, 'unresolved' => 0];
		if ($transactionId === '') {
			return $counts;
		}

		$administrationId = (string)($transaction['administrationId'] ?? '');
		$purchaseSource   = $this->purchaseSource(sourceReference: (string)($transaction['sourceReference'] ?? ''));

		$plans = [];
		$lines = $this->readAllRows(
			objectService: $this->objectService,
			registerSlug: $register,
			schema: 'GLLine',
			filters: ['transactionId' => $transactionId]
		);
		foreach ($lines as $row) {
			$line = $this->rowPayload(row: $row);
			$plan = $this->plan(line: $line, administrationId: $administrationId, purchaseSource: $purchaseSource);
			if ($plan !== null) {
				$plans[] = ['id' => ObjectIdentifier::resolve(saved: $row), 'line' => $line] + $plan;
			}
		}

		$baseCodes = [];
		foreach ($plans as $plan) {
			if ($plan['kind'] === 'base' && $plan['code'] !== '' && $plan['code'] !== self::FROM_BASE_LINES) {
				$baseCodes[$plan['code']] = true;
			}
		}

		foreach ($plans as $plan) {
			$code = $plan['code'];
			if ($code === self::FROM_BASE_LINES && count($baseCodes) === 1) {
				$code = (string)array_key_first($baseCodes);
			}

			if ($code === '' || $code === self::FROM_BASE_LINES) {
				$counts['unresolved']++;
				$this->logger->warning(
					'VatLineBackfill: cannot resolve the VAT tariff of a posted ledger line; it stays out of the VAT return until it is corrected',
					['transactionId' => $transactionId, 'lineId' => $plan['id'], 'accountNumber' => (string)($plan['line']['accountNumber'] ?? '')]
				);
				continue;
			}

			$stamp = $stamper->stamp(code: $code, kind: $plan['kind'], purchase: $plan['purchase']);
			if ($this->write(line: $plan['line'], lineId: $plan['id'], stamp: $stamp, register: $register) === true) {
				$counts['stamped']++;
			}
		}//end foreach

		return $counts;
	}//end stampTransaction()

	/**
	 * What a line should be stamped from, or null when it is not VAT relevant.
	 *
	 * @param array<string,mixed> $line             The GLLine.
	 * @param string              $administrationId Its administration.
	 * @param bool|null           $purchaseSource   Whether the transaction is a purchase, null when its source does not say.
	 *
	 * @return array{code:string,kind:string,purchase:bool}|null The tariff code ('' when unresolvable), kind and side.
	 */
	private function plan(array $line, string $administrationId, ?bool $purchaseSource): ?array {
		$accountNumber = (string)($line['accountNumber'] ?? '');
		$vatAccount    = $this->vatAccountKind(accountNumber: $accountNumber);
		$code          = (string)($line['vatTariffCode'] ?? '');

		if ($code !== '') {
			$kind = (string)($line['vatAmountKind'] ?? '');
			if ($kind === '') {
				$kind = 'base';
				if ($vatAccount !== null) {
					$kind = 'vat';
				}
			}

			return ['code' => $code, 'kind' => $kind, 'purchase' => ($purchaseSource ?? ($vatAccount === 'input'))];
		}

		if ($vatAccount !== null) {
			return ['code' => self::FROM_BASE_LINES, 'kind' => 'vat', 'purchase' => ($vatAccount === 'input')];
		}

		$account = $this->account(administrationId: $administrationId, accountNumber: $accountNumber);
		if ($account === null || ($account['vatApplicable'] ?? false) !== true) {
			return null;
		}

		$accountType = (string)($account['accountType'] ?? '');
		if (in_array($accountType, ['revenue', 'expenses'], true) === false) {
			return ['code' => '', 'kind' => 'base', 'purchase' => false];
		}

		return [
			'code' => $this->tariffOfRate(rate: ($account['vatRate'] ?? null), purchase: ($accountType === 'expenses')),
			'kind' => 'base',
			'purchase' => ($accountType === 'expenses'),
		];
	}//end plan()

	/**
	 * The tariff of an account's VAT rate: 21 high, 9 low, and 0 zero for a purchase.
	 *
	 * A sale at 0 percent can be zero rated, exempt, an export or an EU
	 * supply, so it is not guessed.
	 *
	 * @param mixed $rate     The account's vatRate.
	 * @param bool  $purchase Whether the account is a cost account.
	 *
	 * @return string The tariff code, '' when the rate names none.
	 */
	private function tariffOfRate(mixed $rate, bool $purchase): string {
		if (is_numeric($rate) === false) {
			return '';
		}

		$percentage = (float)$rate;
		if ($percentage > 0 && $percentage < 1) {
			$percentage = ($percentage * 100);
		}

		$tariffs = ['21' => 'high', '9' => 'low'];
		if ($purchase === true) {
			$tariffs['0'] = 'zero';
		}

		$key = rtrim(rtrim(number_format(round($percentage, 2), 2, '.', ''), '0'), '.');

		return ($tariffs[$key] ?? '');
	}//end tariffOfRate()

	/**
	 * Patch a line when its stamps differ from what they should be.
	 *
	 * @param array<string,mixed>  $line     The GLLine as stored.
	 * @param string               $lineId   Its id.
	 * @param array<string,string> $stamp    The stamps it should carry.
	 * @param string               $register The register slug.
	 *
	 * @return bool True when the line was written.
	 */
	private function write(array $line, string $lineId, array $stamp, string $register): bool {
		$changes = [];
		foreach (self::FIELDS as $field) {
			$should = ($stamp[$field] ?? null);
			$has    = ($line[$field] ?? null);
			if ($has === '') {
				$has = null;
			}

			if ($should !== $has && $should !== null) {
				$changes[$field] = $should;
			}
		}

		if ($changes === [] || $lineId === '') {
			return false;
		}

		$this->objectService->patchObject(
			objectId: $lineId,
			data: $changes,
			register: $register,
			schema: 'GLLine',
			_rbac: false,
			_multitenancy: false
		);

		return true;
	}//end write()

	/**
	 * Whether a transaction's source is a purchase (true), a sale (false) or neither (null).
	 *
	 * @param string $sourceReference The transaction's `Schema:id` source reference.
	 *
	 * @return bool|null
	 */
	private function purchaseSource(string $sourceReference): ?bool {
		$schema = strstr($sourceReference, ':', true);

		return match ($schema) {
			'APInvoice' => true,
			'ARInvoice' => false,
			default => null,
		};
	}//end purchaseSource()

	/**
	 * Whether an account is the output (`output`) or input (`input`) VAT account, null otherwise.
	 *
	 * @param string $accountNumber The account number.
	 *
	 * @return string|null
	 */
	private function vatAccountKind(string $accountNumber): ?string {
		$accounts = [
			'output' => [MaterialiseGlTransactionAction::CFG_OUTPUT_VAT_ACCOUNT, '2110'],
			'input' => [MaterialiseGlTransactionAction::CFG_INPUT_VAT_ACCOUNT, '1230'],
		];
		foreach ($accounts as $kind => [$key, $default]) {
			$configured = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
			if ($configured === '') {
				$configured = $default;
			}

			if ($accountNumber !== '' && $accountNumber === $configured) {
				return $kind;
			}
		}

		return null;
	}//end vatAccountKind()

	/**
	 * The Account record of a number in an administration, null when there is none.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber    The account number.
	 *
	 * @return array<string,mixed>|null
	 */
	private function account(string $administrationId, string $accountNumber): ?array {
		$key = $administrationId . '|' . $accountNumber;
		if (array_key_exists($key, $this->accounts) === false) {
			$filters = ['accountNumber' => $accountNumber];
			if ($administrationId !== '') {
				$filters['administrationId'] = $administrationId;
			}

			$rows = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $this->settings->getRegisterSlug(),
				schema: 'Account',
				filters: $filters
			);
			$this->accounts[$key] = null;
			if ($rows !== []) {
				$this->accounts[$key] = $this->rowPayload(row: $rows[0]);
			}
		}

		return $this->accounts[$key];
	}//end account()
}//end class
