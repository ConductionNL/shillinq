<?php

/**
 * OSS Payment Guard
 *
 * Change revive-gl-tax-capabilities, design D2. The `OssReturn.pay` and
 * `OssPayment.reconcile` transitions both declare
 * `"requires": "OCA\\Shillinq\\Service\\OssPaymentReconciliation::canMarkPaid"`
 * in `register.d/bookkeeping-btw-oss-eu.json` — but no DI service was ever
 * registered under that literal tag. OpenRegister's
 * `LifecycleGuardRegistry::resolve()` treats the ENTIRE string (`::method`
 * suffix included) as one container tag and requires the resolved service to
 * implement `LifecycleGuardInterface`; `OssPaymentReconciliation` is a plain
 * pure-logic class and its `canMarkPaid()` takes TWO arrays. The tag could
 * therefore never resolve: `resolve()` threw, uncaught, inside
 * `LifecycleValidationListener`, and **both OSS money transitions hard-failed
 * with HTTP 500** (the shillinq#425 / #433 defect class).
 *
 * This guard is the adapter the register.d string always implied: it accepts
 * either side of the pair (an `OssPayment`, which carries `ossReturnId`, or an
 * `OssReturn`, whose `OssPayment` is looked up by that same FK), resolves the
 * counterpart through the real OpenRegister ObjectService API and delegates
 * the decision to the existing, unmodified
 * {@see \OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid()}.
 *
 * Fail-closed: a missing or unresolvable counterpart denies the transition
 * (a payment cannot be proven to settle a return that cannot be read).
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Service\OssPaymentReconciliation;
use OCA\Shillinq\Service\OssRecordResolver;

/**
 * Precondition for `OssReturn.pay` / `OssPayment.reconcile`.
 *
 * Registered as the literal DI tag
 * `OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid` in
 * Application.php (via RegisterRequiresGuardAdapter), which is exactly the
 * string the two transitions name.
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class OssPaymentGuard {
	/**
	 * Construct the guard.
	 *
	 * @param OssRecordResolver $resolver Reads the counterpart OssReturn / OssPayment.
	 * @param OssPaymentReconciliation $reconciliation The pure-logic REQ-OSS-008 kernel.
	 */
	public function __construct(
		private readonly OssRecordResolver $resolver,
		private readonly OssPaymentReconciliation $reconciliation,
	) {

	}//end __construct()

	/**
	 * Precondition: a matching bank transaction must settle the return in full.
	 *
	 * @param array<string,mixed> $object Either an OssPayment or the OssReturn being paid.
	 *
	 * @return bool True when the transition is permitted.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function canMarkPaid(array $object): bool {
		$payment = $object;
		if (trim((string)($object['ossReturnId'] ?? '')) === '') {
			// The object is the OssReturn side: find the payment settling it.
			$payment = $this->resolver->findPaymentForReturn(ossReturn: $object);
			if ($payment === null) {
				return false;
			}

			return $this->reconciliation->canMarkPaid(ossPayment: $payment, ossReturn: $object);
		}

		$ossReturn = $this->resolver->findReturnForPayment(ossPayment: $payment);
		if ($ossReturn === null) {
			return false;
		}

		return $this->reconciliation->canMarkPaid(ossPayment: $payment, ossReturn: $ossReturn);
	}//end canMarkPaid()
}//end class
