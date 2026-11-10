<?php

/**
 * Realise Reserve Mutation Action
 *
 * The executor of the `realise` transition a ReserveMutation declares
 * (public-sector-reserves-and-interest, REQ-PSRI-001): it posts the journal
 * entry that moves the amount between the reserve's balance account and its
 * result account, and links it on the mutation. A refusal (a missing account,
 * a zero amount) aborts the transition, so a mutation is never realised
 * without its booking.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;

/**
 * Posts a reserve mutation as it is realised.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class RealiseReserveMutationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ReserveBalances $balances Books the mutation.
	 */
	public function __construct(
		private readonly ReserveBalances $balances,
	) {
	}//end __construct()

	/**
	 * Book the mutation and answer it with its journal entry.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the mutation after the transition. Its
	 *                                        parameters are the declared actionParameters.
	 *
	 * @return array<string,mixed> The mutation with journalEntryId.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->balances->realise(mutation: $objectData);

	}//end execute()
}//end class
