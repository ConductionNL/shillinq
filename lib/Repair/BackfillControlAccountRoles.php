<?php

/**
 * Backfill Control Account Roles
 *
 * `Account.controlAccountFor` names the ledger that owns a control account,
 * and a journal entry by hand on such an account is refused
 * (ledger-booking-rules REQ-LBR-001, REQ-LBR-002). New administrations get
 * the role from the RGS MKB seed. This step gives it to accounts that exist
 * already: the four accounts the sub-ledgers book to (receivables 1100,
 * payables 2000, input VAT 1230, output VAT 2110), and only where the
 * account still carries the seed's name, so an administration on another
 * chart whose 1100 means something else is left alone. An account that
 * already has a role is never changed. Every account it sets is logged.
 *
 * Payroll accounts get no role here: humaniq's payroll journal does not yet
 * say it comes from humaniq, so a payroll role would refuse it.
 *
 * Runs post-migration, after InitializeSettings has imported the property.
 * Best-effort: a failure warns and never blocks the upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
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

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets controlAccountFor on the existing control accounts of the seed chart.
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
class BackfillControlAccountRoles implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Account number => [the seed chart's name, the control role].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public const CONTROL_ACCOUNTS = [
		'1100' => ['Debiteuren', 'receivables'],
		'2000' => ['Crediteuren', 'payables'],
		'1230' => ['BTW-vordering', 'vat'],
		'2110' => ['BTW-schuld', 'vat'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register slug.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
	 */
	public function getName(): string {
		return 'Shillinq: mark the receivables, payables and VAT accounts as control accounts';
	}//end getName()

	/**
	 * Set the role on every matching account that has none.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$set = 0;
			foreach (self::CONTROL_ACCOUNTS as $number => [$name, $role]) {
				$rows = $this->readAllRows(
					objectService: $this->objectService,
					registerSlug: $registerSlug,
					schema: 'Account',
					filters: ['accountNumber' => (string)$number]
				);
				foreach ($rows as $row) {
					$account = $this->rowPayload(row: $row);
					$uuid = ObjectIdentifier::resolve(saved: $row);
					if ($uuid === '' || $this->needsRole(account: $account, name: $name) === false) {
						continue;
					}

					$account['controlAccountFor'] = $role;
					$this->objectService->saveObject(
						object: $this->withoutMetadata(row: $account),
						register: $registerSlug,
						schema: 'Account',
						uuid: $uuid,
						_rbac: false,
						_multitenancy: false,
					);
					$set++;
					$output->info(
						sprintf(
							'Shillinq: account %s %s in administration %s is now the %s control account.',
							(string)$number,
							$name,
							(string)($account['administrationId'] ?? ''),
							$role
						)
					);
				}//end foreach
			}//end foreach

			$output->info(sprintf('Shillinq: %d control account(s) marked.', $set));
		} catch (Throwable $e) {
			$output->warning('Shillinq: marking the control accounts failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: marking the control accounts failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * Whether an account still carries the seed's name and has no role yet.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string $name The seed chart's name for its number.
	 *
	 * @return bool
	 */
	private function needsRole(array $account, string $name): bool {
		if ((string)($account['controlAccountFor'] ?? '') !== '') {
			return false;
		}

		return mb_strtolower(trim((string)($account['name'] ?? ''))) === mb_strtolower($name);
	}//end needsRole()

	/**
	 * A row without its id and OpenRegister metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function withoutMetadata(array $row): array {
		$data = [];
		foreach ($row as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		return $data;
	}//end withoutMetadata()
}//end class
