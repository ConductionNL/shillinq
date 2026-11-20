<?php

/**
 * Contract of InnovatieboxAuditEventLogger
 *
 * The public surface of InnovatieboxAuditEventLogger that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of InnovatieboxAuditEventLogger.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InnovatieboxAuditEventLoggerInterface {

	/**
	 * Event type constants — mirror the enum on the InnovatieboxAuditEvent
	 * schema (lib/Settings/register.d/bookkeeping-innovatiebox-administratie.json).
	 *
	 * @var string
	 */
	public const EVENT_NEXUS_CALCULATED = 'NexusCalculation.calculated';

	public const EVENT_PROFIT_CREATED = 'IBProfitAttribution.created';

	public const EVENT_PROFIT_FINALIZED = 'IBProfitAttribution.finalized';

	public const EVENT_PROFIT_AMENDMENT_BLOCKED = 'IBProfitAttribution.amendment_attempt_blocked';

	public const EVENT_LOSS_CREATED = 'CarryForwardLoss.created';

	public const EVENT_LOSS_OFFSET_APPLIED = 'CarryForwardLoss.offset_applied';

	public const EVENT_FORFAITAIR_CAP_APPLIED = 'flatRateCapApplied';
}//end interface
