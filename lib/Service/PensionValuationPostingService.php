<?php

/**
 * Pension Valuation Posting Service
 *
 * Books an approved actuarial valuation (REQ-PEN-011): reads the period's
 * PensionMovement for the valued plan, computes the profit-and-loss cost
 * (service cost + past service cost − settlement gain + net interest) and
 * the remeasurement for other comprehensive income (actuarial change on the
 * obligation − the return on plan assets above the expected return), writes
 * the computed figures back onto the movement, and posts one balanced
 * GLTransaction: P&L and OCI against the net pension liability.
 *
 * A figure the movement does not carry is computed from the IAS 19 formula
 * the schema documents: net interest = discount rate × opening net pension
 * liability; asset remeasurement = actual − expected return. The posting is
 * written once per valuation: a second approval after a reopen finds the
 * transaction carrying the valuation as its source and posts nothing.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts the IAS 19 journal entry of an approved actuarial valuation.
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */
class PensionValuationPostingService {

	/**
	 * App-config key of the profit-and-loss pension cost account.
	 */
	public const CFG_PENSION_COST_ACCOUNT = 'pension_cost_account';

	/**
	 * App-config key of the other comprehensive income remeasurement account.
	 */
	public const CFG_PENSION_OCI_ACCOUNT = 'pension_remeasurement_oci_account';

	/**
	 * App-config key of the net pension liability account.
	 */
	public const CFG_PENSION_LIABILITY_ACCOUNT = 'pension_liability_account';

	/**
	 * Placeholder account numbers used when the key is not configured.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_ACCOUNTS = [
		self::CFG_PENSION_COST_ACCOUNT => '4130',
		self::CFG_PENSION_OCI_ACCOUNT => '0560',
		self::CFG_PENSION_LIABILITY_ACCOUNT => '0810',
	];

	/**
	 * @param IAppConfig             $appConfig     Account numbers and the register slug.
	 * @param LoggerInterface        $logger        Diagnostics; never logs amounts.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Post the IAS 19 entry of one approved valuation.
	 *
	 * @param array<string, mixed> $valuation The ActuarialValuation, with its `id`.
	 *
	 * @return array{posted: bool, message: string, plCents?: int, ociCents?: int, transaction?: array<string, mixed>}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
	 */
	public function postForValuation(array $valuation): array {
		$valuationId = (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? ''));
		$plan = (string)($valuation['plan'] ?? '');
		$period = substr((string)($valuation['valuationDate'] ?? ''), 0, 4);
		if ((string)($valuation['approvalStatus'] ?? '') !== 'approved') {
			return ['posted' => false, 'message' => 'The valuation is not approved.'];
		}

		if ($valuationId === '' || $plan === '' || preg_match('/^\d{4}$/', $period) !== 1) {
			return ['posted' => false, 'message' => 'The valuation names no plan or valuation date.'];
		}

		if ($this->rows(schema: 'GLTransaction', filters: ['sourceReference' => $valuationId]) !== []) {
			return ['posted' => false, 'message' => 'This valuation is already booked.'];
		}

		$movement = $this->movement(plan: $plan, period: $period);
		if ($movement === null) {
			return ['posted' => false, 'message' => sprintf('No pension movement for this plan in %s.', $period)];
		}

		$figures = self::figures(movement: $movement, valuation: $valuation);
		if ($figures['plCents'] === 0 && $figures['ociCents'] === 0) {
			return ['posted' => false, 'message' => 'Nothing to book: the cost and the remeasurement are zero.'];
		}

		return $this->post(valuation: $valuation, movement: $movement, figures: $figures, period: $period);
	}//end postForValuation()

	/**
	 * The profit-and-loss cost and the OCI remeasurement of a movement, in cents.
	 *
	 * @param array<string, mixed> $movement  The PensionMovement of the period.
	 * @param array<string, mixed> $valuation The valuation, for its discount rate.
	 *
	 * @return array{plCents: int, ociCents: int, netInterest: float, assetRemeasurement: float}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
	 */
	public static function figures(array $movement, array $valuation): array {
		$netInterest = self::number(value: ($movement['netInterestCost'] ?? null));
		if ($netInterest === null) {
			$rate = (self::number(value: ($valuation['discountRate'] ?? null)) ?? 0.0) / 100;
			$dboOpening = (self::number(value: ($movement['dboOpening'] ?? null)) ?? 0.0);
			$openingNet = $dboOpening - (self::number(value: ($movement['planAssetsOpening'] ?? null)) ?? 0.0);
			$netInterest = round($rate * $openingNet, 2);
		}

		$assetRemeasurement = self::number(value: ($movement['actuarialGainLossAssets'] ?? null));
		if ($assetRemeasurement === null) {
			$actual = self::number(value: ($movement['actualReturnOnAssets'] ?? null));
			$expected = self::number(value: ($movement['expectedReturnOnAssets'] ?? null));
			$assetRemeasurement = 0.0;
			if ($actual !== null && $expected !== null) {
				$assetRemeasurement = round($actual - $expected, 2);
			}
		}

		$pl = (self::number(value: ($movement['serviceCostCurrent'] ?? null)) ?? 0.0)
			+ (self::number(value: ($movement['pastServiceCost'] ?? null)) ?? 0.0)
			- (self::number(value: ($movement['gainOnSettlement'] ?? null)) ?? 0.0)
			+ $netInterest;
		$oci = (self::number(value: ($movement['actuarialLossGainDBO'] ?? null)) ?? 0.0) - $assetRemeasurement;

		return [
			'plCents' => (int)round($pl * 100),
			'ociCents' => (int)round($oci * 100),
			'netInterest' => $netInterest,
			'assetRemeasurement' => $assetRemeasurement,
		];
	}//end figures()

	/**
	 * The journal lines: cost and remeasurement against the liability. A
	 * positive amount is a debit, a negative one a credit of its absolute
	 * value; zero-amount legs are left out. The lines always balance.
	 *
	 * @param int                   $plCents  The profit-and-loss cost.
	 * @param int                   $ociCents The OCI remeasurement (positive: loss).
	 * @param array<string, string> $accounts The three account numbers by config key.
	 *
	 * @return array<int, array{accountNumber: string, side: string, cents: int}>
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
	 */
	public static function lines(int $plCents, int $ociCents, array $accounts): array {
		$legs = [
			[$accounts[self::CFG_PENSION_COST_ACCOUNT], $plCents],
			[$accounts[self::CFG_PENSION_OCI_ACCOUNT], $ociCents],
			[$accounts[self::CFG_PENSION_LIABILITY_ACCOUNT], -($plCents + $ociCents)],
		];
		$lines = [];
		foreach ($legs as [$account, $cents]) {
			if ($cents === 0) {
				continue;
			}

			$side = 'credit';
			if ($cents > 0) {
				$side = 'debit';
			}

			$lines[] = ['accountNumber' => $account, 'side' => $side, 'cents' => abs($cents)];
		}

		return $lines;
	}//end lines()

	/**
	 * Write the transaction, its lines and the computed figures on the movement.
	 *
	 * @param array<string, mixed>                                                     $valuation The valuation.
	 * @param array<string, mixed>                                                     $movement  The movement.
	 * @param array{plCents: int, ociCents: int, netInterest: float, assetRemeasurement: float} $figures   The computed figures.
	 * @param string                                                                   $period    The year.
	 *
	 * @return array{posted: bool, message: string, plCents?: int, ociCents?: int, transaction?: array<string, mixed>}
	 */
	private function post(array $valuation, array $movement, array $figures, string $period): array {
		$valuationId = (string)($valuation['id'] ?? ($valuation['@self']['id'] ?? ''));
		$administrationId = (string)($valuation['administrationId'] ?? ($movement['administrationId'] ?? ''));
		$postingDate = substr((string)$valuation['valuationDate'], 0, 10);
		$description = sprintf('IAS 19 pensioenlast %s', $period);
		try {
			$transaction = $this->save(
				schema: 'GLTransaction',
				data: [
					'transactionNumber' => sprintf('IAS19-%s-%s', $period, $valuationId),
					'postingDate' => $postingDate,
					'periodId' => $period,
					'currency' => 'EUR',
					'description' => $description,
					'sourceReference' => $valuationId,
					'state' => 'draft',
					'administrationId' => $administrationId,
				]
			);
			$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
			foreach (self::lines(plCents: $figures['plCents'], ociCents: $figures['ociCents'], accounts: $this->accounts()) as $index => $line) {
				$this->save(
					schema: 'GLLine',
					data: [
						'transactionId' => $transactionId,
						'administrationId' => $administrationId,
						'lineNumber' => ($index + 1),
						'accountNumber' => $line['accountNumber'],
						'side' => $line['side'],
						'amount' => round($line['cents'] / 100, 2),
						'currency' => 'EUR',
						'description' => $description,
					]
				);
			}

			$movement['netInterestCost'] = $figures['netInterest'];
			$movement['actuarialGainLossAssets'] = $figures['assetRemeasurement'];
			$movement['netPensionMovementPL'] = round($figures['plCents'] / 100, 2);
			$movement['netPensionMovementOCI'] = round($figures['ociCents'] / 100, 2);
			$movement['linkedJournalEntries'] = array_values(array_unique(array_merge((array)($movement['linkedJournalEntries'] ?? []), [$transactionId])));
			$this->save(schema: 'PensionMovement', data: $movement);
		} catch (Throwable $e) {
			$this->logger->warning('PensionValuationPostingService: posting failed', ['valuation' => $valuationId, 'exception' => $e->getMessage()]);
			return ['posted' => false, 'message' => 'The pension entry could not be posted.'];
		}//end try

		return [
			'posted' => true,
			'message' => 'Pension entry posted.',
			'plCents' => $figures['plCents'],
			'ociCents' => $figures['ociCents'],
			'transaction' => $transaction,
		];
	}//end post()

	/**
	 * The movement of a plan for a year, null when there is none.
	 *
	 * @param string $plan   The PensionPlan id.
	 * @param string $period The year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function movement(string $plan, string $period): ?array {
		foreach ($this->rows(schema: 'PensionMovement', filters: ['plan' => $plan, 'period' => $period]) as $row) {
			if ((string)($row['plan'] ?? '') === $plan && (string)($row['period'] ?? '') === $period) {
				return $row;
			}
		}

		return null;
	}//end movement()

	/**
	 * The configured account numbers, a placeholder default where unset.
	 *
	 * @return array<string, string>
	 */
	private function accounts(): array {
		$accounts = [];
		foreach (self::DEFAULT_ACCOUNTS as $key => $default) {
			$value = trim($this->appConfig->getValueString(AppIdentity::APP_ID, $key, ''));
			$accounts[$key] = $value;
			if ($value === '') {
				$accounts[$key] = $default;
			}
		}

		return $accounts;
	}//end accounts()

	/**
	 * Rows of a schema matching filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
		$rows = [];
		foreach ((array)($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Save one object and return it as an array.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $data): array {
		$id = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject(object: $data, uuid: $uuid);
		$out = $saved->jsonSerialize();
		if (is_array($out) === false) {
			return [];
		}

		return $out;
	}//end save()

	/**
	 * The register slug.
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

	/**
	 * A numeric value as float, null when absent or not a number.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float|null
	 */
	private static function number(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true || (is_string($value) === true && is_numeric($value) === true)) {
			return (float)$value;
		}

		return null;
	}//end number()
}//end class
