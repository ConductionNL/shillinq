<?php

/**
 * Reinvestment Reserves
 *
 * A disposal gain kept for a replacement asset (assets-method-change-and-reserve,
 * REQ-AMCR-004, REQ-AMCR-005; art. 3.54 Wet IB 2001). Forming one records the
 * gain and an expiry at the end of the third year after the year it was
 * formed; the disposal journal credits the reserve account instead of profit.
 * Applying one to a replacement lowers that asset's fiscal cost basis, debits
 * the reserve account and credits the asset account. The daily run releases
 * an open reserve past its expiry: the remainder goes from the reserve account
 * to profit. Amounts are summed in cents.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

use DomainException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;

/**
 * Form, apply and release reinvestment reserves.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class ReinvestmentReserves {

	public const SCHEMA = 'ReinvestmentReserve';

	/**
	 * App config key of the equity account the reserve is kept on.
	 */
	public const CFG_RESERVE_ACCOUNT = 'fixed_asset_reinvestment_reserve_account';

	/**
	 * App config key of the account an expired remainder is released to.
	 */
	public const CFG_RELEASE_ACCOUNT = 'fixed_asset_reinvestment_release_account';

	/**
	 * The reserve account when none is configured (RGS herinvesteringsreserve range).
	 */
	public const DEFAULT_RESERVE_ACCOUNT = '0640';

	/**
	 * Constructor.
	 *
	 * @param AssetRecords $records   The register.
	 * @param IAppConfig   $appConfig The account settings.
	 */
	public function __construct(
		private readonly AssetRecords $records,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The account the reserve is kept on.
	 *
	 * @return string The account number.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function reserveAccount(): string {
		$account = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_RESERVE_ACCOUNT, ''));
		if ($account === '') {
			return self::DEFAULT_RESERVE_ACCOUNT;
		}

		return $account;

	}//end reserveAccount()

	/**
	 * The end of the third year after the year a reserve is formed.
	 *
	 * @param string $formedOn YYYY-MM-DD.
	 *
	 * @return string YYYY-12-31.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function expiryFor(string $formedOn): string {
		return sprintf('%04d-12-31', ((int)substr($formedOn, 0, 4) + 3));

	}//end expiryFor()

	/**
	 * Record a reserve for a disposal gain.
	 *
	 * @param array<string,mixed> $asset    The sold asset.
	 * @param float               $gain     The gain in euros.
	 * @param string              $formedOn The disposal date.
	 *
	 * @return array<string,mixed> The reserve.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function form(array $asset, float $gain, string $formedOn): array {
		return $this->records->save(
			schema: self::SCHEMA,
			object: [
				'administrationId'     => (string)($asset['administrationId'] ?? ''),
				'disposedAssetRef'     => (string)($asset['id'] ?? ''),
				'disposedAssetNumber'  => (string)($asset['assetNumber'] ?? ''),
				'formedOn'             => $formedOn,
				'amount'               => round($gain, 2),
				'expiresOn'            => $this->expiryFor(formedOn: $formedOn),
				'appliedAmount'        => 0,
				'remainder'            => round($gain, 2),
				'applications'         => [],
				'reserveAccountNumber' => $this->reserveAccount(),
				'lifecycleState'       => 'open',
			]
		);

	}//end form()

	/**
	 * Apply a reserve to a replacement asset from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The replacement asset with reinvestmentReserveId and, optionally, reserveAmountApplied.
	 *
	 * @return array<string,mixed> The asset to save, with its fiscal cost basis.
	 *
	 * @throws DomainException When the reserve is not open, belongs to another administration, or the amount does not fit.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function apply(array $asset): array {
		$run = new ReserveApplicationRun(asset: $asset, reserve: $this->openReserveFor(asset: $asset));
		$this->decideAmount(run: $run);
		$this->postApplication(run: $run);
		$this->recordApplication(run: $run);

		$asset['fiscalCostBasis'] = (($run->basis - $run->applied) / 100);
		$asset['reserveAmountApplied'] = null;

		return $asset;

	}//end apply()

	/**
	 * The open reserve the asset names, within the asset's administration.
	 *
	 * @param array<string,mixed> $asset The replacement asset.
	 *
	 * @return array<string,mixed> The reserve.
	 *
	 * @throws DomainException When the reserve is not open or belongs to another administration.
	 */
	private function openReserveFor(array $asset): array {
		$reserve = $this->records->find(schema: self::SCHEMA, id: trim((string)($asset['reinvestmentReserveId'] ?? '')));
		if ($reserve === null || (string)($reserve['lifecycleState'] ?? '') !== 'open') {
			throw new DomainException('Choose an open reinvestment reserve.');
		}

		if ((string)($reserve['administrationId'] ?? '') !== (string)($asset['administrationId'] ?? '')) {
			throw new DomainException('The reinvestment reserve belongs to another administration.');
		}

		return $reserve;
	}//end openReserveFor()

	/**
	 * Decide the amount: the requested amount when one is given, else as much
	 * as both the reserve's remainder and the asset's fiscal basis allow.
	 *
	 * @param ReserveApplicationRun $run The application in progress.
	 *
	 * @return void
	 *
	 * @throws DomainException When the amount is not between 0.01 and what fits.
	 */
	private function decideAmount(ReserveApplicationRun $run): void {
		$run->basis = $this->fiscalBasis(asset: $run->asset);
		$left = (self::cents(amount: ($run->reserve['amount'] ?? 0)) - self::cents(amount: ($run->reserve['appliedAmount'] ?? 0)));
		$fits = min($left, $run->basis);
		$run->applied = $this->requestedOr(asset: $run->asset, default: $fits);

		if ($run->applied <= 0 || $run->applied > $left || $run->applied > $run->basis) {
			throw new DomainException(sprintf('Apply between 0.01 and %s.', number_format(($fits / 100), 2, '.', '')));
		}
	}//end decideAmount()

	/**
	 * The asset's fiscal cost basis in cents: its own when set, else its cost.
	 *
	 * @param array<string,mixed> $asset The replacement asset.
	 *
	 * @return int The basis.
	 */
	private function fiscalBasis(array $asset): int {
		if (isset($asset['fiscalCostBasis']) === true) {
			return self::cents(amount: $asset['fiscalCostBasis']);
		}

		return self::cents(amount: ($asset['acquisitionCost'] ?? ($asset['purchaseCost'] ?? 0)));
	}//end fiscalBasis()

	/**
	 * The requested amount in cents when a positive one is given, else the default.
	 *
	 * @param array<string,mixed> $asset   The replacement asset.
	 * @param int                 $default The amount that fits.
	 *
	 * @return int The amount to apply.
	 */
	private function requestedOr(array $asset, int $default): int {
		if (isset($asset['reserveAmountApplied']) === true && (float)$asset['reserveAmountApplied'] > 0) {
			return self::cents(amount: $asset['reserveAmountApplied']);
		}

		return $default;
	}//end requestedOr()

	/**
	 * Post the application: debit the reserve account, credit the asset
	 * account, dated at the asset's acquisition.
	 *
	 * @param ReserveApplicationRun $run The application with its amount.
	 *
	 * @return void
	 *
	 * @throws DomainException When the asset has no asset account.
	 */
	private function postApplication(ReserveApplicationRun $run): void {
		$asset = $run->asset;
		$assetAccount = (string)($asset['assetAccountNumber'] ?? ($asset['capitalizationAccountNumber'] ?? ''));
		if ($assetAccount === '') {
			throw new DomainException('The asset has no asset account to lower.');
		}

		$run->text = sprintf('Herinvesteringsreserve %s op %s', (string)($run->reserve['disposedAssetNumber'] ?? ''), (string)($asset['assetNumber'] ?? ''));
		$run->date = substr((string)($asset['acquisitionDate'] ?? ($asset['purchaseDate'] ?? date('Y-m-d'))), 0, 10);
		$run->journalId = $this->records->postJournal(
			journal: $this->journal(
				administrationId: (string)$asset['administrationId'],
				number: $this->applicationNumber(run: $run),
				date: $run->date,
				description: $run->text,
				lines: $this->applicationLines(run: $run, assetAccount: $assetAccount)
			)
		);
	}//end postApplication()

	/**
	 * The two journal lines: debit the reserve account (the reserve's own,
	 * else the configured one), credit the asset account.
	 *
	 * @param ReserveApplicationRun $run          The application with its amount and text.
	 * @param string                $assetAccount The asset account to lower.
	 *
	 * @return list<array<string,mixed>> The lines.
	 */
	private function applicationLines(ReserveApplicationRun $run, string $assetAccount): array {
		return [
			[
				'accountNumber' => (string)($run->reserve['reserveAccountNumber'] ?? $this->reserveAccount()),
				'side' => 'debit',
				'amount' => ($run->applied / 100),
				'description' => $run->text,
			],
			['accountNumber' => $assetAccount, 'side' => 'credit', 'amount' => ($run->applied / 100), 'description' => $run->text],
		];
	}//end applicationLines()

	/**
	 * The journal number: HIR-<date>-<hash of reserve and asset>.
	 *
	 * @param ReserveApplicationRun $run The application with its date.
	 *
	 * @return string The number.
	 */
	private function applicationNumber(ReserveApplicationRun $run): string {
		return sprintf(
			'HIR-%s-%s',
			str_replace('-', '', $run->date),
			substr(hash('sha256', (string)$run->reserve['id'] . '|' . (string)($run->asset['id'] ?? '')), 0, 8)
		);
	}//end applicationNumber()

	/**
	 * Record the application on the reserve; a fully used reserve is applied.
	 *
	 * @param ReserveApplicationRun $run The posted application.
	 *
	 * @return void
	 */
	private function recordApplication(ReserveApplicationRun $run): void {
		$reserve = $run->reserve;
		$appliedTotal = (self::cents(amount: ($reserve['appliedAmount'] ?? 0)) + $run->applied);
		$applications = (array)($reserve['applications'] ?? []);
		$applications[] = [
			'assetRef' => (string)($run->asset['id'] ?? ''),
			'assetNumber' => (string)($run->asset['assetNumber'] ?? ''),
			'amount' => ($run->applied / 100),
			'journalEntryId' => $run->journalId,
			'appliedOn' => $run->date,
		];
		$fields = [
			'appliedAmount' => ($appliedTotal / 100),
			'remainder' => ((self::cents(amount: $reserve['amount']) - $appliedTotal) / 100),
			'applications' => $applications,
		];
		if ($appliedTotal >= self::cents(amount: $reserve['amount'])) {
			$fields['lifecycleState'] = 'applied';
		}

		$this->records->patch(schema: self::SCHEMA, id: (string)$reserve['id'], fields: $fields);
	}//end recordApplication()

	/**
	 * Release every open reserve past its expiry with a remainder to profit.
	 *
	 * @param string $today YYYY-MM-DD.
	 *
	 * @return int The reserves released.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function releaseExpired(string $today): int {
		$released = 0;
		foreach ($this->records->records(schema: self::SCHEMA, filters: ['lifecycleState' => 'open']) as $reserve) {
			if ((string)($reserve['expiresOn'] ?? '9999-12-31') >= $today) {
				continue;
			}

			$left = (self::cents(amount: ($reserve['amount'] ?? 0)) - self::cents(amount: ($reserve['appliedAmount'] ?? 0)));
			$fields = ['lifecycleState' => 'released'];
			if ($left > 0) {
				$text = sprintf('Vrijval herinvesteringsreserve %s', (string)($reserve['disposedAssetNumber'] ?? ''));
				$fields['releaseJournalEntryId'] = $this->records->postJournal(
					journal: $this->journal(
						administrationId: (string)($reserve['administrationId'] ?? ''),
						number: sprintf('HIR-VRIJ-%s', substr(hash('sha256', (string)$reserve['id']), 0, 8)),
						date: (string)$reserve['expiresOn'],
						description: $text,
						lines: [
							[
								'accountNumber' => (string)($reserve['reserveAccountNumber'] ?? $this->reserveAccount()),
								'side' => 'debit',
								'amount' => ($left / 100),
								'description' => $text,
							],
							['accountNumber' => $this->releaseAccount(), 'side' => 'credit', 'amount' => ($left / 100), 'description' => $text],
						]
					)
				);
			}

			$this->records->patch(schema: self::SCHEMA, id: (string)$reserve['id'], fields: $fields);
			$released++;
		}//end foreach

		return $released;

	}//end releaseExpired()

	/**
	 * The account an expired remainder goes to: the configured release account, else the disposal gain account.
	 *
	 * @return string The account number.
	 */
	private function releaseAccount(): string {
		foreach ([self::CFG_RELEASE_ACCOUNT, 'fixed_asset_disposal_gain_account'] as $key) {
			$account = trim($this->appConfig->getValueString(AppIdentity::APP_ID, $key, ''));
			if ($account !== '') {
				return $account;
			}
		}

		return '8000-fa-gain';

	}//end releaseAccount()

	/**
	 * A draft journal entry posted without approval.
	 *
	 * @param string                          $administrationId The administration.
	 * @param string                          $number           The journal number.
	 * @param string                          $date             The entry date.
	 * @param string                          $description      The description.
	 * @param list<array<string,mixed>>       $lines            The lines.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function journal(string $administrationId, string $number, string $date, string $description, array $lines): array {
		return [
			'journalNumber'    => $number,
			'entryDate'        => $date,
			'description'      => $description,
			'lines'            => $lines,
			'journalType'      => 'manual',
			'approvalState'    => 'not-required',
			'administrationId' => $administrationId,
			'state'            => 'draft',
		];

	}//end journal()

	/**
	 * Euros to cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int The cents.
	 */
	private static function cents(mixed $amount): int {
		return (int)round(((float)$amount) * 100);

	}//end cents()
}//end class
