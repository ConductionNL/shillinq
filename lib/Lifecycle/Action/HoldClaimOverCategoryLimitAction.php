<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Hold Claim Over Category Limit Action
 *
 * On submit, every receipt line of an expense claim is checked against the
 * limit its category carries in the reimbursement policy (REQ-ECM-004). A
 * line over its limit holds the claim for approval: `approvalState` becomes
 * pending and `categoryLimitExceedances` lists each line with the limit it
 * passed, so the claim page shows it. A claim within every limit goes on
 * without that step, with an empty list.
 *
 * The policy is the one the claim names (`reimbursementPolicyId`, by id or
 * policyId), else the policy of the claim's administration. A limit `per`
 * night divides the receipt amount by its `nights`.
 *
 * When the policy or a line cannot be read the claim is held: a check that
 * cannot run must not let a claim skip approval.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;

/**
 * Holds a submitted expense claim for approval when a line passes its
 * category's limit.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 */
final class HoldClaimOverCategoryLimitAction implements LifecycleActionInterface {
	/**
	 * The approval state of a held claim.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * The claim field listing the lines over their limit.
	 *
	 * @var string
	 */
	public const EXCEEDANCES = 'categoryLimitExceedances';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param LoggerInterface        $logger           Logger for held claims.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check the submitted claim's lines against the category limits.
	 *
	 * @param array<string,mixed> $objectData   The claim after its state moved.
	 * @param array<string,mixed> $previousData The claim before the transition.
	 * @param array<string,mixed> $parameters   The declared actionParameters.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The claim, held when a line is over its limit.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		try {
			$exceedances = $this->exceedances(claim: $objectData);
		} catch (\Throwable $e) {
			$this->logger->error(
				'HoldClaimOverCategoryLimitAction: the category limits could not be checked; the claim waits for approval',
				['claimId' => (string)($objectData['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			$objectData['approvalState'] = self::PENDING;
			return $objectData;
		}

		$objectData[self::EXCEEDANCES] = $exceedances;
		if ($exceedances !== []) {
			$objectData['approvalState'] = self::PENDING;
		}

		return $objectData;
	}//end execute()

	/**
	 * The claim's receipt lines over their category's limit.
	 *
	 * @param array<string,mixed> $claim The claim.
	 *
	 * @return list<array{receiptId: string, line: string, category: string, amount: float, limit: float, per: string}>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	public function exceedances(array $claim): array {
		$limits = $this->limits(policy: $this->policy(claim: $claim));
		if ($limits === []) {
			return [];
		}

		$out = [];
		foreach ((array)($claim['receiptIds'] ?? []) as $id) {
			$id = trim((string)$id);
			$receipt = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'Receipt'), id: $id);
			$category = trim((string)($receipt['category'] ?? ''));
			if ($receipt === null || isset($limits[$category]) === false) {
				continue;
			}

			$limit = $limits[$category];
			$amount = (float)($receipt['amountInBaseCurrency'] ?? ($receipt['amount'] ?? 0));
			$unit = $amount;
			if ($limit['per'] === 'night') {
				$unit = ($amount / max(1, (int)($receipt['nights'] ?? 1)));
			}

			if ((int)round($unit * 100) <= (int)round($limit['limit'] * 100)) {
				continue;
			}

			$out[] = [
				'receiptId' => $id,
				'line' => trim((string)($receipt['receiptNumber'] ?? ($receipt['description'] ?? $id))),
				'category' => $category,
				'amount' => round($unit, 2),
				'limit' => $limit['limit'],
				'per' => $limit['per'],
			];
		}//end foreach

		if ($out !== []) {
			$this->logger->info(
				'HoldClaimOverCategoryLimitAction: a line is over its category limit; the claim waits for approval',
				['claimId' => (string)($claim['id'] ?? ''), 'lines' => array_column($out, 'line')]
			);
		}

		return $out;
	}//end exceedances()

	/**
	 * The policy's limits by category code.
	 *
	 * @param array<string,mixed>|null $policy The policy.
	 *
	 * @return array<string, array{limit: float, per: string}>
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	private function limits(?array $policy): array {
		$limits = [];
		foreach ((array)($policy['categoryLimits'] ?? []) as $entry) {
			if (is_array($entry) === false || is_numeric($entry['limit'] ?? null) === false) {
				continue;
			}

			$code = trim((string)($entry['category'] ?? ''));
			if ($code === '') {
				continue;
			}

			$per = 'line';
			if ((string)($entry['per'] ?? '') === 'night') {
				$per = 'night';
			}

			$limits[$code] = ['limit' => (float)$entry['limit'], 'per' => $per];
		}

		return $limits;
	}//end limits()

	/**
	 * The policy the claim names, else its administration's, else null.
	 *
	 * @param array<string,mixed> $claim The claim.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	private function policy(array $claim): ?array {
		$named = trim((string)($claim['reimbursementPolicyId'] ?? ''));
		if ($named !== '') {
			return ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'ReimbursementPolicy'), id: $named, fallbackProperty: 'policyId');
		}

		$administrationId = trim((string)($claim['administrationId'] ?? ''));
		if ($administrationId === '') {
			return null;
		}

		$rows = $this->scoped(schema: 'ReimbursementPolicy')->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 2]);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['administrationId'] ?? '') === $administrationId) {
				return $record;
			}
		}

		return null;
	}//end policy()

	/**
	 * The object service scoped to this app's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
