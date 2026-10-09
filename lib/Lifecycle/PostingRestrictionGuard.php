<?php

/**
 * Shillinq PostingRestrictionGuard
 *
 * The two booking rules a tender asks for, checked on every posting a person
 * makes (ledger-booking-rules):
 *
 *   - A line on a control account (`Account.controlAccountFor` set) is
 *     refused unless the posting comes from the sub-ledger that owns it
 *     (REQ-LBR-002). A bank booking may post its VAT line; a humaniq payroll
 *     journal may post on payroll control accounts. Sub-ledger postings that
 *     MaterialiseGlTransactionAction writes never pass this guard.
 *   - A line whose account starts with an active PostingRestriction's
 *     `accountPattern` and whose cost centre and project match the
 *     restriction's on the posting date is refused, naming the reason
 *     (REQ-LBR-005). An empty cost centre or project on the restriction
 *     matches any.
 *
 * JournalEntryGuard::canPost and RuleComplianceGuard::validateTransaction
 * delegate to it, because a transition takes one `requires` value. A refusal
 * is thrown as PostingRefusedException, so its message reaches the
 * bookkeeper.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Refuses manual postings on control accounts and blocked combinations.
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
class PostingRestrictionGuard {

	/**
	 * Control roles a sub-ledger may post on, keyed by the posting's source app.
	 *
	 * @var array<string, list<string>>
	 */
	public const SUB_LEDGER_ROLES = [
		'bank' => ['vat'],
		'humaniq' => ['payroll'],
		// An imported opening balance carries the balances of every control
		// account over from the previous package (platform-administration-import).
		'import' => ['receivables', 'payables', 'vat', 'expense-claims', 'payroll'],
	];

	/**
	 * The most restrictions read for one administration.
	 *
	 * @var int
	 */
	private const RESTRICTION_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IL10N $l10n Translates the refusals.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Refuse the posting when a line breaks a booking rule.
	 *
	 * @param list<array<string,mixed>> $lines The posting's lines (accountNumber, costCenterCode, projectCode).
	 * @param string $administrationId The administration the posting belongs to.
	 * @param string $postingDate The posting date (Y-m-d).
	 * @param string $sourceApp The sub-ledger the posting comes from, '' for a person.
	 *
	 * @return void
	 *
	 * @throws PostingRefusedException With the account or the reason in its message.
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
	 */
	public function assertAllowed(array $lines, string $administrationId, string $postingDate, string $sourceApp = ''): void {
		$this->assertNoControlAccount(lines: $lines, administrationId: $administrationId, sourceApp: $sourceApp);
		$this->assertNoBlockedCombination(lines: $lines, administrationId: $administrationId, postingDate: $postingDate);
	}//end assertAllowed()

	/**
	 * Refuse a line on a control account its source may not post on.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 * @param string $administrationId The administration.
	 * @param string $sourceApp The posting's sub-ledger, '' for a person.
	 *
	 * @return void
	 *
	 * @throws PostingRefusedException Naming the account and its control role.
	 */
	private function assertNoControlAccount(array $lines, string $administrationId, string $sourceApp): void {
		$allowed = (self::SUB_LEDGER_ROLES[$sourceApp] ?? []);
		$seen = [];
		foreach ($lines as $line) {
			$number = trim((string)($line['accountNumber'] ?? ''));
			if ($number === '' || isset($seen[$number]) === true) {
				continue;
			}

			$seen[$number] = true;
			$account = $this->account(administrationId: $administrationId, accountNumber: $number);
			$role = (string)($account['controlAccountFor'] ?? '');
			if ($role === '' || in_array($role, $allowed, true) === true) {
				continue;
			}

			throw new PostingRefusedException(
				$this->l10n->t(
					'Account %1$s %2$s is the %3$s control account. Only its own ledger posts to it; book this through that ledger instead.',
					[$number, (string)($account['name'] ?? ''), $this->roleLabel(role: $role)]
				)
			);
		}//end foreach
	}//end assertNoControlAccount()

	/**
	 * Refuse a line whose account, cost centre and project an active
	 * restriction blocks on the posting date.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 * @param string $administrationId The administration.
	 * @param string $postingDate The posting date (Y-m-d).
	 *
	 * @return void
	 *
	 * @throws PostingRefusedException Naming the restriction's reason.
	 */
	private function assertNoBlockedCombination(array $lines, string $administrationId, string $postingDate): void {
		$restrictions = $this->restrictions(administrationId: $administrationId);
		foreach ($restrictions as $restriction) {
			if ($this->inForce(restriction: $restriction, postingDate: $postingDate) === false) {
				continue;
			}

			foreach ($lines as $line) {
				if ($this->blocks(restriction: $restriction, line: $line) === true) {
					throw new PostingRefusedException(
						$this->l10n->t(
							'This combination of account, cost centre and project may not be booked: %s',
							[(string)($restriction['reason'] ?? '')]
						)
					);
				}
			}
		}
	}//end assertNoBlockedCombination()

	/**
	 * Whether a restriction blocks a line.
	 *
	 * @param array<string,mixed> $restriction The PostingRestriction.
	 * @param array<string,mixed> $line The line.
	 *
	 * @return bool
	 */
	private function blocks(array $restriction, array $line): bool {
		$pattern = trim((string)($restriction['accountPattern'] ?? ''));
		$account = trim((string)($line['accountNumber'] ?? ''));
		if ($pattern === '' || str_starts_with($account, $pattern) === false) {
			return false;
		}

		return $this->matches(expected: (string)($restriction['costCenterCode'] ?? ''), actual: (string)($line['costCenterCode'] ?? ''))
			&& $this->matches(expected: (string)($restriction['projectCode'] ?? ''), actual: (string)($line['projectCode'] ?? ''));
	}//end blocks()

	/**
	 * An empty expected value matches any; otherwise the values are equal.
	 *
	 * @param string $expected The restriction's value.
	 * @param string $actual The line's value.
	 *
	 * @return bool
	 */
	private function matches(string $expected, string $actual): bool {
		$expected = trim($expected);
		if ($expected === '') {
			return true;
		}

		return $expected === trim($actual);
	}//end matches()

	/**
	 * Whether a restriction is in force on the posting date.
	 *
	 * @param array<string,mixed> $restriction The PostingRestriction.
	 * @param string $postingDate The posting date (Y-m-d).
	 *
	 * @return bool
	 */
	private function inForce(array $restriction, string $postingDate): bool {
		if ((string)($restriction['lifecycleState'] ?? 'active') !== 'active') {
			return false;
		}

		$date = substr($postingDate, 0, 10);
		$from = substr((string)($restriction['validFrom'] ?? ''), 0, 10);
		$until = substr((string)($restriction['validTo'] ?? ''), 0, 10);
		if ($date === '') {
			return true;
		}

		return ($from === '' || $from <= $date) && ($until === '' || $date <= $until);
	}//end inForce()

	/**
	 * The label of a control role in the refusal.
	 *
	 * @param string $role The controlAccountFor value.
	 *
	 * @return string
	 */
	private function roleLabel(string $role): string {
		$labels = [
			'receivables' => $this->l10n->t('receivables'),
			'payables' => $this->l10n->t('payables'),
			'vat' => $this->l10n->t('VAT'),
			'expense-claims' => $this->l10n->t('expense claims'),
			'payroll' => $this->l10n->t('payroll'),
		];
		return ($labels[$role] ?? $role);
	}//end roleLabel()

	/**
	 * One account of the administration by number, [] when absent.
	 *
	 * @param string $administrationId The administration.
	 * @param string $accountNumber The account number.
	 *
	 * @return array<string,mixed>
	 */
	private function account(string $administrationId, string $accountNumber): array {
		$filters = ['accountNumber' => $accountNumber];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$rows = $this->objectService->setRegister($this->register())->setSchema('Account')->findAll(['filters' => $filters, 'limit' => 1]);
		foreach ($rows as $row) {
			return $this->asArray(row: $row);
		}

		return [];
	}//end account()

	/**
	 * The administration's active posting restrictions.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function restrictions(string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}

		$rows = $this->objectService->setRegister($this->register())->setSchema('PostingRestriction')->findAll(
			['filters' => ['administrationId' => $administrationId, 'lifecycleState' => 'active'], 'limit' => self::RESTRICTION_LIMIT]
		);
		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->asArray(row: $row);
		}

		return $out;
	}//end restrictions()

	/**
	 * A found row as a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end asArray()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
