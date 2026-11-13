<?php

/**
 * Contract of XafAuditfileGenerator
 *
 * The public surface of XafAuditfileGenerator that other components call, so they depend
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

use OCA\Shillinq\Reporting\GeneratedFile;

/**
 * Contract of XafAuditfileGenerator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XafAuditfileGeneratorInterface {

	/**
	 * Render the XAF 3.2 audit file for the context administration + period.
	 *
	 * @param array<string, mixed> $context `{ period?, administrationId? }`.
	 * @param string $format Must be 'xml'.
	 *
	 * @return GeneratedFile
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function generate(array $context, string $format): GeneratedFile;
}//end interface
