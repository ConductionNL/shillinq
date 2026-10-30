<?php

/**
 * Meter Reading Rating
 *
 * Prices one meter reading against its rate plan, so the amount a customer
 * will be billed shows on the reading before it reaches an invoice
 * (sales-usage-billing, REQ-USB-001).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Usage
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Usage;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Rates a reading against the rate plan of its own administration.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
class MeterReadingRating {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      Supplies the register slug.
	 * @param UsageRatingCalculator  $calculator    Flat and graduated rating.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly UsageRatingCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * The reading with its rated amount in euros, before VAT.
	 *
	 * @param array<string,mixed> $reading The meter reading.
	 *
	 * @return array<string,mixed> The reading with `ratedAmount` set.
	 *
	 * @throws DomainException When the reading's plan is not found in its administration.
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function rate(array $reading): array {
		$plan = $this->planFor(reading: $reading);
		if ($plan === null) {
			throw new DomainException('This reading has no rate plan in its administration.');
		}

		$priced = $this->calculator->rate(quantity: (float)($reading['quantity'] ?? 0), plan: $plan);
		$reading['ratedAmount'] = round($priced['costAmountCents'] / 100, 2);

		return $reading;

	}//end rate()

	/**
	 * The reading's rate plan, when it exists in the reading's administration.
	 *
	 * @param array<string,mixed> $reading The meter reading.
	 *
	 * @return array<string,mixed>|null The plan, or null.
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function planFor(array $reading): ?array {
		$planId = (string)($reading['ratePlanId'] ?? '');
		if ($planId === '') {
			return null;
		}

		$scoped = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('UsageRatePlan');
		$plan   = ObjectIdentifier::findOne(scoped: $scoped, id: $planId);
		if ($plan === null || (string)($plan['administrationId'] ?? '') !== (string)($reading['administrationId'] ?? '')) {
			return null;
		}

		return $plan;

	}//end planFor()
}//end class
