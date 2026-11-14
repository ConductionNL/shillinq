<?php

/**
 * Contract of PaymentRequestFinder
 *
 * The public surface of PaymentRequestFinder that other components call, so they depend
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
 * Contract of PaymentRequestFinder.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentRequestFinderInterface {

	/**
	 * Every object request that carries this transfer reference, compared
	 * without case, whatever its subject (REQ-ORS-002).
	 *
	 * @param string $reference The transfer reference.
	 * @param bool $asSystem True to read past the caller's rights, so the
	 *                       uniqueness check sees requests the caller cannot.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whose rights a read runs under
	 * is data the caller decides, not a mode that changes what the method does.
	 */
	public function withReference(string $reference, bool $asSystem = false): array;

	/**
	 * Every object request whose subject is this object, with its `id`.
	 *
	 * @param string $register The subject's register.
	 * @param string $schema The subject's schema.
	 * @param string $objectId The subject's id.
	 * @param bool $asSystem True to read past the caller's rights, for a check
	 *                       that must see every request (the raise's duplicate
	 *                       check); false to read as the caller (the leaf).
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whose rights a read runs under
	 * is data the caller decides, not a mode that changes what the method does.
	 */
	public function onSubject(string $register, string $schema, string $objectId, bool $asSystem = false): array;
}//end interface
