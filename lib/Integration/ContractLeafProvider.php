<?php

/**
 * Contract Leaf Provider
 *
 * A case is handled under a contract: a maintenance agreement, a framework, a
 * subsidy. The case app should hold the reference and nothing else, and read
 * the term, the counterparty, the status and what is left of the value from
 * here. A case app that copied those fields would carry a stale contract the
 * day somebody extends the term, and nobody would know which copy was right.
 *
 * `list` answers the contracts a host object is linked to. It reads shillinq's
 * own store under the CALLING user's rights, and a contract the caller may not
 * see is simply absent: not a partial record, not a stub with the counterparty
 * blanked out. A partial record tells the reader a contract exists and who it
 * is roughly with, which is most of what they were not allowed to know.
 *
 * @category Integration
 * @package  OCA\Shillinq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Reads the contract a case is handled under, without letting it be copied.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 */
final class ContractLeafProvider implements IntegrationProvider {
	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'shillinq-contracts';

	/**
	 * The schema holding contracts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'Contract';

	/**
	 * The statuses a reading app is told about explicitly, because they change
	 * what the handler should do next.
	 *
	 * @var array<int, string>
	 */
	public const ATTENTION_STATUSES = ['expiring', 'expired', 'terminated'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::LEAF_ID;
	}//end getId()

	/**
	 * The label shown on the leaf.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return 'Contract';
	}//end getLabel()

	/**
	 * The MDI icon name.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'FileDocumentOutline';
	}//end getIcon()

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string The group.
	 */
	public function getGroup(): string {
		return 'Finance';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 */
	public function getRequiredApp(): string {
		return 'shillinq';
	}//end getRequiredApp()

	/**
	 * Contracts live in shillinq's own register.
	 *
	 * @return string The storage strategy.
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever shillinq is installed.
	 *
	 * @return bool True.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Read only: no permission beyond being able to read the contract itself.
	 *
	 * @return string|null Null.
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * The contracts this host object is handled under.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $filters Optional list filters; unknown keys are ignored.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor}` envelope.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $filters is IntegrationProvider's;
	 * this leaf returns every request on the object and filters nothing yet.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$items = [];
		foreach ($this->contractsLinkedTo(register: $register, schema: $schema, objectId: $objectId) as $contract) {
			$items[] = $this->project(contract: $contract);
		}

		return ['items' => $items, 'total' => count($items), 'nextCursor' => null];
	}//end list()

	/**
	 * One contract behind this object, by id.
	 *
	 * A contract the caller may not read is NOT FOUND, not a partial record. The
	 * read runs through OpenRegister under the calling user, so a contract the
	 * caller cannot see never reaches this method at all; this refusal is what
	 * happens when it does not.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The contract id.
	 *
	 * @return array<string, mixed> The contract.
	 *
	 * @throws RuntimeException When the contract is not linked here, or not readable.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		foreach ($this->contractsLinkedTo(register: $register, schema: $schema, objectId: $objectId) as $contract) {
			if ((string)($contract['id'] ?? '') === $entityId) {
				return $this->project(contract: $contract);
			}
		}

		throw new RuntimeException('404 No contract with that id is readable behind this object.');
	}//end get()

	/**
	 * A contract is administered in shillinq, never created sideways from a case.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is
	 * IntegrationProvider's. The method refuses every call, so it reads none of
	 * its arguments; dropping them would break the contract.
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		throw new RuntimeException('A contract is administered in shillinq; the leaf reads it and does not create one.');
	}//end create()

	/**
	 * The same for an edit.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The contract id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is
	 * IntegrationProvider's. The method refuses every call, so it reads none of
	 * its arguments; dropping them would break the contract.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new RuntimeException('A contract is edited in shillinq, where its lifecycle and its audit trail are.');
	}//end update()

	/**
	 * The same for a delete.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The contract id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is
	 * IntegrationProvider's. The method refuses every call, so it reads none of
	 * its arguments; dropping them would break the contract.
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new RuntimeException('A contract is an agreement and is terminated, never deleted from a case.');
	}//end delete()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health report.
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => self::LEAF_ID];
	}//end health()

	/**
	 * What the reading app is given. Deliberately a projection and not the
	 * contract: the reading app holds the reference, not a copy.
	 *
	 * @param array<string, mixed> $contract The stored contract.
	 *
	 * @return array<string, mixed> The projection.
	 */
	private function project(array $contract): array {
		$status = (string)($contract['status'] ?? '');

		return [
			'id' => (string)($contract['id'] ?? ''),
			'contractNumber' => (string)($contract['contractNumber'] ?? ''),
			'title' => (string)($contract['title'] ?? ''),
			'counterpartyReference' => (string)($contract['counterpartyReference'] ?? ''),
			'startDate' => (string)($contract['startDate'] ?? ''),
			'endDate' => (string)($contract['endDate'] ?? ''),
			'status' => $status,
			'needsAttention' => in_array($status, self::ATTENTION_STATUSES, true),
			'currency' => (string)($contract['currency'] ?? 'EUR'),
			'totalContractValue' => $this->money(value: ($contract['totalContractValue'] ?? null)),
			// Null, never 0.00. A contract that has not been rolled up yet, or
			// one whose roll-up came back incomplete, has no remaining value;
			// rendering that as 0.00 says the budget is spent and stops work
			// that is in fact funded (REQ-FPCR-005).
			'incurredCost' => $this->money(value: ($contract['incurredCost'] ?? null)),
			'remainingValue' => $this->money(value: ($contract['remainingValue'] ?? null)),
			'incurredCostComputedAt' => (string)($contract['incurredCostComputedAt'] ?? ''),
			'incurredCostComplete' => ($contract['incurredCostComplete'] ?? null) !== false,
			'incurredCostUnreadableLinks' => (int)($contract['incurredCostUnreadableLinks'] ?? 0),
		];
	}//end project()

	/**
	 * One money field as a number, or null when it is not one.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float|null The amount, or null when there is none to report.
	 */
	private function money(mixed $value): ?float {
		if (is_bool($value) === true || is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end money()

	/**
	 * Every readable contract that names this object among its linked objects.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 *
	 * @return array<int, array<string, mixed>> The contracts.
	 */
	private function contractsLinkedTo(string $register, string $schema, string $objectId): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['limit' => 500]);

		$key = implode('|', [$register, $schema, $objectId]);

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || is_array($row['linkedObjects'] ?? null) === false) {
				continue;
			}

			foreach ($row['linkedObjects'] as $link) {
				if (is_array($link) === false) {
					continue;
				}

				$candidate = implode(
					'|',
					[
						(string)($link['register'] ?? ''),
						(string)($link['schema'] ?? ''),
						(string)($link['id'] ?? ''),
					]
				);

				if ($candidate === $key) {
					$mine[] = $row;
					break;
				}
			}
		}

		return $mine;
	}//end contractsLinkedTo()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
