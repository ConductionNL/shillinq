<?php

/**
 * Shillinq Digipoort SBR adapter binding
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Digipoort
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

namespace OCA\Shillinq\Service\External\Digipoort;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Which adapter answers DigipoortSbrAdapterInterface: integriq's hand-off
 * when integriq is enabled, else the log-only adapter (which the hand-off
 * action refuses, so nothing is marked submitted without integriq).
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
final class DigipoortSbrBinding {

	/**
	 * The app that carries filings to Digipoort.
	 */
	public const INTEGRIQ = 'integriq';

	/**
	 * The adapter for this instance.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return DigipoortSbrAdapterInterface The bound adapter.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.1
	 */
	public function adapter(ContainerInterface $container): DigipoortSbrAdapterInterface {
		$appManager = $container->get(IAppManager::class);
		if ($appManager instanceof IAppManager && $appManager->isEnabledForAnyone(self::INTEGRIQ) === true) {
			return $container->get(IntegriqSbrHandoffAdapter::class);
		}

		return $container->get(LogDigipoortSbrAdapter::class);
	}//end adapter()
}//end class
