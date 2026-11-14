<?php

/**
 * Record Cancellation Action
 *
 * Runs on both end transitions of a RecurringInvoiceProfile (REQ-SCX-001):
 * writes the cancellation record from the reason asked on End.
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
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Lifecycle\CancellationReasonGuard;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Write `cancellation`: reason, note, who ended it, when it was asked and when it takes effect.
 *
 * A staff end is recorded as `staff` at the moment of the transition. A
 * customer end keeps the `cancelledBy`, `requestedAt` and `effectiveDate` the
 * portal endpoint set. The effective date is the end of the current billing
 * period: the day before the next run, or today when no later run is planned
 * (design, Open Questions).
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 */
class RecordCancellationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Return the profile with its cancellation record.
	 *
	 * @param array<string, mixed> $objectData   The profile being ended, with the inputs merged in.
	 * @param array<string, mixed> $previousData The profile before the transition.
	 * @param array<string, mixed> $parameters   The action's parameters (none).
	 * @param string               $actionName   The transition.
	 *
	 * @return array<string, mixed> The profile to save.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$now = $this->time->getDateTime();
		$preset = $objectData['cancellation'] ?? [];
		if (is_array($preset) === false) {
			$preset = [];
		}

		$byCustomer = (($preset['cancelledBy'] ?? null) === 'customer');
		$code = trim((string)($objectData['cancellationReasonCode'] ?? ''));
		if (in_array($code, CancellationReasonGuard::REASON_CODES, true) === false) {
			$code = CancellationReasonGuard::NOT_GIVEN;
		}

		$text = trim((string)($objectData['cancellationReasonText'] ?? ''));
		if ($text === '') {
			$text = null;
		}

		$cancelledBy = 'staff';
		if ($byCustomer === true) {
			$cancelledBy = 'customer';
		}

		$objectData['cancellation'] = [
			'reasonCode' => $code,
			'reasonText' => $text,
			'cancelledBy' => $cancelledBy,
			'requestedAt' => $this->presetOr(preset: $preset, key: 'requestedAt', byCustomer: $byCustomer, fallback: $now->format(DateTimeInterface::ATOM)),
			'effectiveDate' => $this->presetOr(
				preset: $preset,
				key: 'effectiveDate',
				byCustomer: $byCustomer,
				fallback: self::effectiveDate(nextRunDate: ($objectData['nextRunDate'] ?? null), today: $now)
			),
		];

		return $objectData;
	}//end execute()

	/**
	 * A value the portal set on a customer end, else the fallback.
	 *
	 * @param array<string, mixed> $preset     The cancellation the portal set.
	 * @param string               $key        The field.
	 * @param bool                 $byCustomer Whether this is a customer end.
	 * @param string               $fallback   The value otherwise.
	 *
	 * @return string The value.
	 */
	private function presetOr(array $preset, string $key, bool $byCustomer, string $fallback): string {
		$value = trim((string)($preset[$key] ?? ''));
		if ($byCustomer === true && $value !== '') {
			return $value;
		}

		return $fallback;
	}//end presetOr()

	/**
	 * The end of the current billing period: the day before the next run, or today.
	 *
	 * @param mixed             $nextRunDate The profile's next run date (YYYY-MM-DD), if any.
	 * @param DateTimeInterface $today       The current moment.
	 *
	 * @return string The effective date, YYYY-MM-DD.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
	 */
	public static function effectiveDate(mixed $nextRunDate, DateTimeInterface $today): string {
		$todayDate = $today->format('Y-m-d');
		if (is_string($nextRunDate) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $nextRunDate) !== 1) {
			return $todayDate;
		}

		$next = DateTimeImmutable::createFromFormat('!Y-m-d', substr($nextRunDate, 0, 10));
		if ($next === false || $next->format('Y-m-d') <= $todayDate) {
			return $todayDate;
		}

		return $next->modify('-1 day')->format('Y-m-d');
	}//end effectiveDate()
}//end class
