<?php

/**
 * Shillinq file-VAT-return lifecycle action
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\Sbr\VatReturnXbrlBuilder;
use RuntimeException;

/**
 * The action of BtwAangifte `submit`: files the return through its XBRL instance.
 *
 * It builds the return's instance on the OB entry point (or reuses it),
 * validates it and submits it, each a declared XbrlInstance transition run
 * as the user, so the validation guard and the hand-off action apply. The
 * return then waits in handed-off; it shows submitted only when integriq
 * reports the delivery (SbrFilingStatusListener). Without a filing
 * connection it refuses before building anything, so the return stays in
 * draft and its XBRL file can still be built and downloaded from the SBR
 * filings page. Any refusal (validation, hand-off) refuses the return's
 * transition with the same reason.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-vat-btw-filing/spec.md#requirement-a-vat-return-is-filed-through-its-xbrl-instance-req-tdf-001
 */
class FileVatReturnAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param DigipoortSbrAdapterInterface    $adapter     The Digipoort port: is filing configured.
	 * @param VatReturnXbrlBuilder            $builder     Builds the return's instance.
	 * @param ObjectTransitionRunnerInterface $transitions Validates and submits it.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.4
	 */
	public function __construct(
		private readonly DigipoortSbrAdapterInterface $adapter,
		private readonly VatReturnXbrlBuilder $builder,
		private readonly ObjectTransitionRunnerInterface $transitions,
	) {
	}//end __construct()

	/**
	 * Build, validate and hand off the return's instance.
	 *
	 * @param array<string, mixed> $objectData   The BtwAangifte as the transition leaves it.
	 * @param array<string, mixed> $previousData The BtwAangifte before.
	 * @param array<string, mixed> $parameters   Declared parameters (none).
	 * @param string               $actionName   The action name.
	 *
	 * @return array<string, mixed> The return with xbrlInstanceId set.
	 *
	 * @throws RuntimeException When filing is not configured, or the instance is refused or already done.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.4
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		if ($this->adapter->isDormant() === true) {
			throw new RuntimeException(HandToDigipoortAction::NOT_CONFIGURED);
		}

		$instance = $this->builder->instanceFor(vatReturn: $objectData);
		$id       = (string) $instance['id'];
		$state    = (string) ($instance['state'] ?? 'draft');
		if (in_array($state, ['accepted', 'submitted', 'handed-off'], true) === true) {
			throw new RuntimeException(sprintf('This VAT return is already being filed (its XBRL instance is %s).', $state));
		}

		if ($state === 'draft') {
			$this->transitions->run(objectId: $id, action: 'validate');
			$state = 'validated';
		}

		if ($state !== 'validated') {
			throw new RuntimeException(sprintf('The XBRL instance of this VAT return is %s; reopen and correct it first.', $state));
		}

		$this->transitions->run(objectId: $id, action: 'submit');

		$objectData['xbrlInstanceId'] = $id;
		return $objectData;
	}//end execute()
}//end class
