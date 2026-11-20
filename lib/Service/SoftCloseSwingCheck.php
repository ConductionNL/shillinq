<?php

/**
 * Soft Close Swing Check
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The swing check that follows the nightly soft close (REQ-CLS-011): for one
 * administration and period it reads each account's net movement in the
 * period and in the period before it from the trial balance, and hands those
 * pairs to FluxService::run() with the administration's materiality policy,
 * so the stored flux run lists every account whose swing passes its
 * threshold.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-soft-close-flags-the-swings-per-account-req-cls-011
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\FluxServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the swing check for a soft-closed period against the period before it.
 */
class SoftCloseSwingCheck {

	/**
	 * The comparison basis of the nightly run: the period before.
	 *
	 * @var string
	 */
	public const COMPARISON_BASIS = 'prior-period';

	/**
	 * The account group whose materiality policy applies when none is named.
	 *
	 * @var string
	 */
	public const DEFAULT_ACCOUNT_GROUP = 'operational';

	/**
	 * Construct the check.
	 *
	 * @param TrialBalanceServiceInterface $trialBalance  Per-account movements per period.
	 * @param FluxServiceInterface         $flux          The swing check that classifies and stores the run.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service, for the materiality policy.
	 * @param IAppConfig                   $appConfig     App config for the register slug.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly TrialBalanceServiceInterface $trialBalance,
		private readonly FluxServiceInterface $flux,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the swing check for one administration and period and store the run.
	 *
	 * @param string            $administrationId The administration.
	 * @param string            $periodId         The yyyy-mm period that was soft-closed.
	 * @param DateTimeImmutable $asOf             When the run happens.
	 *
	 * @return array<string,mixed>|null FluxService::run()'s summary, or null when the period is not yyyy-mm.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-soft-close-flags-the-swings-per-account-req-cls-011
	 */
	public function run(string $administrationId, string $periodId, DateTimeImmutable $asOf): ?array {
		$priorPeriodId = self::priorPeriod(periodId: $periodId);
		if ($priorPeriodId === null) {
			return null;
		}

		$actual = $this->netMovements(administrationId: $administrationId, periodId: $periodId);
		$basis = $this->netMovements(administrationId: $administrationId, periodId: $priorPeriodId);

		return $this->flux->run(
			[
				'administrationId' => $administrationId,
				'periodId' => $periodId,
				'scope' => 'administration',
				'comparisonBasis' => self::COMPARISON_BASIS,
				'accounts' => self::accountPairs(actual: $actual, basis: $basis),
				'materialityPolicy' => $this->materialityPolicy(administrationId: $administrationId),
				'runTimestamp' => $asOf,
			]
		);
	}//end run()

	/**
	 * The yyyy-mm period before the given one.
	 *
	 * @param string $periodId A yyyy-mm period.
	 *
	 * @return string|null The prior period, or null when the input is not yyyy-mm.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-soft-close-flags-the-swings-per-account-req-cls-011
	 */
	public static function priorPeriod(string $periodId): ?string {
		if (preg_match('/^(\d{4})-(\d{2})$/', $periodId, $parts) !== 1) {
			return null;
		}

		$year = (int)$parts[1];
		$month = (int)$parts[2];
		if ($month < 1 || $month > 12) {
			return null;
		}

		if ($month === 1) {
			return sprintf('%04d-12', ($year - 1));
		}

		return sprintf('%04d-%02d', $year, ($month - 1));
	}//end priorPeriod()

	/**
	 * The flux inputs per account: this period's movement against the period before.
	 *
	 * An account that did not move in either period is left out; an account that
	 * moved in one of them is compared against zero for the other.
	 *
	 * @param array<string,int> $actual Net movement in cents per account, this period.
	 * @param array<string,int> $basis  Net movement in cents per account, the period before.
	 *
	 * @return array<int,array<string,mixed>> The accounts, by account number.
	 *
	 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-soft-close-flags-the-swings-per-account-req-cls-011
	 */
	public static function accountPairs(array $actual, array $basis): array {
		$numbers = array_map('strval', array_unique(array_merge(array_keys($actual), array_keys($basis))));
		sort($numbers);

		$accounts = [];
		foreach ($numbers as $number) {
			$actualCents = (int)($actual[$number] ?? 0);
			$basisCents = (int)($basis[$number] ?? 0);
			if ($actualCents === 0 && $basisCents === 0) {
				continue;
			}

			$accounts[] = [
				'glAccountNumber' => $number,
				'accountGroupCode' => self::DEFAULT_ACCOUNT_GROUP,
				'actualCents' => $actualCents,
				'basisCents' => $basisCents,
				'attributions' => [],
			];
		}

		return $accounts;
	}//end accountPairs()

	/**
	 * The net movement (debit minus credit) in cents of every account in a period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $periodId         The period.
	 *
	 * @return array<string,int> Cents per account number.
	 */
	private function netMovements(string $administrationId, string $periodId): array {
		$balance = $this->trialBalance->compute(administrationId: $administrationId, periodId: $periodId);

		$movements = [];
		foreach ((array)($balance['data'] ?? []) as $row) {
			$number = (string)($row['accountNumber'] ?? '');
			if ($number === '') {
				continue;
			}

			$debit = (int)round(((float)($row['debitMovement'] ?? 0)) * 100);
			$credit = (int)round(((float)($row['creditMovement'] ?? 0)) * 100);
			$movements[$number] = (($movements[$number] ?? 0) + $debit - $credit);
		}

		return $movements;
	}//end netMovements()

	/**
	 * The administration's materiality policy, for its default account group when it has one.
	 *
	 * Without a policy FluxService surfaces every account that moved, which is
	 * the safe answer: nothing is hidden for lack of a threshold.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed> The policy record, or an empty array.
	 */
	private function materialityPolicy(string $administrationId): array {
		try {
			$found = $this->objectService
				->setRegister($this->register())
				->setSchema('MaterialityPolicy')
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SoftCloseSwingCheck: materiality policy lookup failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$first = [];
		foreach ($found as $raw) {
			$policy = self::asArray(row: $raw);
			if (($policy['administrationId'] ?? null) !== $administrationId) {
				continue;
			}

			if (($policy['accountGroupCode'] ?? '') === self::DEFAULT_ACCOUNT_GROUP) {
				return $policy;
			}

			if ($first === []) {
				$first = $policy;
			}
		}

		return $first;
	}//end materialityPolicy()

	/**
	 * Normalise a findAll() row (array or ObjectEntity) to its payload.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
