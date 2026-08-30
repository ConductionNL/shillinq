<?php

/**
 * Three-Way Match Guard
 *
 * Single-method lifecycle precondition for the APInvoice `received → matched`
 * transition. Thin PHP seam per ADR-031 §"PHP guards remain a legitimate
 * seam" — the conditional 3-way / 2-way match (REQ-AP-006) cannot yet be
 * expressed by the declarative lifecycle engine because it depends on the
 * runtime presence of the future PO/GR procurement registers (T4).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md (REQ-AP-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the APInvoice match transition with conditional 3-way / 2-way logic.
 *
 * Referenced as `OCA\Shillinq\Lifecycle\ThreeWayMatchGuard::matches` from the
 * APInvoice schema lifecycle. Exactly one public precondition method.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-004
 */
class ThreeWayMatchGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container for OR's ObjectService.
	 * @param IAppConfig $appConfig App config for register-slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the configured register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Determine whether an AP invoice may transition `received → matched`.
	 *
	 * - **3-way path** — when both `poRef` and `grRef` are present AND the
	 *   future PO/GR registers exist, verify the invoiced quantity/amount
	 *   matches the goods-receipt within tolerance.
	 * - **2-way path** — when PO/GR are absent (T2 baseline), the match reduces
	 *   to operator confirmation; the precondition passes because the operator
	 *   action that triggers the transition is itself the confirmation.
	 *
	 * Returns false only when a 3-way match is required and the referenced GR
	 * quantity/amount does not match the invoice within tolerance.
	 *
	 * @param array<string,mixed> $invoice APInvoice object array.
	 *
	 * @return bool True when the match precondition is satisfied.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-004
	 */
	public function matches(array $invoice): bool {
		$poRef = ($invoice['poRef'] ?? null);
		$grRef = ($invoice['grRef'] ?? null);

		// 2-way fallback: no PO/GR references → operator-confirmed match (REQ-AP-006).
		if ($poRef === null || $poRef === '' || $grRef === null || $grRef === '') {
			return true;
		}

		$goodsReceipt = $this->findGoodsReceipt(grRef: (string)$grRef, adminId: (string)($invoice['administrationId'] ?? ''));
		if ($goodsReceipt === null) {
			// 3-way requested but the GR register/record is not available (T4 not
			// shipped). Fail closed: a referenced-but-unresolvable GR is a genuine
			// mismatch the operator must resolve before posting.
			$this->logger->info(
				'ThreeWayMatchGuard: GR reference present but not resolvable — 3-way match rejected',
				['grRef' => $grRef]
			);
			return false;
		}

		// Compare amounts in integer cents within a 1-cent tolerance.
		$invoiceCents = (int)round(((float)($invoice['grossAmount'] ?? 0)) * 100);
		$grCents = (int)round(((float)($goodsReceipt['grossAmount'] ?? 0)) * 100);

		return (abs($invoiceCents - $grCents) <= 1);
	}//end matches()

	/**
	 * Look up a goods-receipt record by reference, if the GR register exists.
	 *
	 * @param string $grRef Goods-receipt reference.
	 * @param string $adminId Administration identifier.
	 *
	 * @return array<string,mixed>|null The GR record, or null when unavailable.
	 */
	private function findGoodsReceipt(string $grRef, string $adminId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$matches = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('GoodsReceipt')
				->findAll(
					[
						'filters' => [
							'grRef' => $grRef,
							'administrationId' => $adminId,
						],
						'limit' => 1,
					]
				);

			if (empty($matches) === true) {
				return null;
			}

			return $matches[0];
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ThreeWayMatchGuard: GoodsReceipt register not present (T4 not shipped)',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findGoodsReceipt()
}//end class
