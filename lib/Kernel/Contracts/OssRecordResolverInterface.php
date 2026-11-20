<?php

/**
 * Contract of OssRecordResolver
 *
 * The public surface of OssRecordResolver that other components call, so they depend
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
 * Contract of OssRecordResolver.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OssRecordResolverInterface {

	/**
	 * Resolve the OssPayment settling an OssReturn.
	 *
	 * @param array<string,mixed> $ossReturn The return record.
	 *
	 * @return array<string,mixed>|null The linked payment, or null when none exists.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findPaymentForReturn(array $ossReturn): ?array;

	/**
	 * Resolve the OssReturn an OssPayment settles.
	 *
	 * @param array<string,mixed> $ossPayment The payment record.
	 *
	 * @return array<string,mixed>|null The linked return, or null when unresolvable.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findReturnForPayment(array $ossPayment): ?array;

	/**
	 * Persist an OssPayment through the real ObjectService API.
	 *
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record.
	 *
	 * @throws \RuntimeException When the row type is unsupported.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function savePayment(array $data): array;
}//end interface
