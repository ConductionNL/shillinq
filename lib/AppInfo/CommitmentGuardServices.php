<?php

/**
 * Commitment Guard Services
 *
 * Registers the two commitment guard tags the Commitment lifecycle names,
 * `MandateEnforcer::requiresApproval` and `BudgetBlocker::canCommit`
 * (planning-commitment-year-end, REQ-PCYE-001; shillinq#433 for these two).
 * Their methods take `(commitmentNumber, object)`, so they go through
 * CommitmentGuardAdapter, not RegisterRequiresGuardAdapter. Kept out of
 * Application so that class stays under the length the gate allows.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\CommitmentGuardAdapter;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * The container registrations of the commitment guard tags.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class CommitmentGuardServices {
	/**
	 * The tags, the guard class, its method and the refusal without a better reason.
	 *
	 * @var array<string,array{0:class-string,1:string,2:string}>
	 */
	private const TAGS = [
		'OCA\Shillinq\Lifecycle\MandateEnforcer::requiresApproval' => [
			MandateEnforcer::class,
			'requiresApproval',
			'This commitment is within your mandate. Enter into it directly.',
		],
		'OCA\Shillinq\Lifecycle\BudgetBlocker::canCommit'          => [
			BudgetBlocker::class,
			'canCommit',
			'The budget does not have room for this commitment.',
		],
	];

	/**
	 * Register every tag with its adapter.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-1.1
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::TAGS as $tag => [$guardClass, $method, $denyMessage]) {
			$context->registerService(
				$tag,
				static function ($c) use ($guardClass, $method, $denyMessage): CommitmentGuardAdapter {
					return new CommitmentGuardAdapter(
						guard: $c->get($guardClass),
						method: $method,
						denyMessage: $denyMessage,
						ledger: $c->get(CommitmentLedger::class),
						logger: $c->get(LoggerInterface::class),
					);
				}
			);
		}

	}//end register()
}//end class
