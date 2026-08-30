<?php

/**
 * Shillinq AppendReopenHistoryAction
 *
 * Handler for the `append-reopen-history` lifecycle action declared on the
 * FiscalPeriod `reopen` transition (`closed` → `open`) in
 * `lib/Settings/register.d/bookkeeping-period-close.json`, per REQ-PC-006.
 *
 * The action was declared but never implemented, and OpenRegister's
 * LifecycleActionRegistry is deliberately fail-loud: an action name it cannot
 * resolve throws
 * `Lifecycle action "append-reopen-history" is declared but no handler is
 * registered`. Measured on a live Nextcloud 32 + OpenRegister instance, that
 * turned every successful reopen into an HTTP 500 — `POST
 * /apps/shillinq/api/period-close/{id}/reopen` with a valid reason answered
 * `{"error":"An unexpected error occurred"}` even though the state change
 * itself was legitimate and every precondition had passed.
 *
 * Idempotency
 * -----------
 * The reopen can arrive by two routes, and this handler has to be correct on
 * both:
 *
 *   - Through `PeriodCloseService::reopenPeriod()`, which already composes the
 *     history entry in PHP and persists it. The declarative action then runs on
 *     the very same write and must NOT append a second, duplicate entry.
 *   - Through a purely declarative transition (OpenRegister's transition
 *     endpoint / the generic UI), where nothing has written the entry yet and
 *     this handler is the only thing that records the reopen.
 *
 * The entry is therefore appended only when the tail of `reopenedHistory` does
 * not already record this very reopen (same prior `closedAt` + `closedBy`).
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
 * @spec openspec/specs/bookkeeping-period-close/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUserSession;

/**
 * Appends the prior close to `reopenedHistory` and clears `closedAt` / `closedBy`.
 *
 * @spec openspec/specs/bookkeeping-period-close/spec.md
 */
class AppendReopenHistoryAction implements LifecycleActionInterface {

	/**
	 * Default target property when `actionParameters.target` is absent.
	 *
	 * @var string
	 */
	private const DEFAULT_TARGET = 'reopenedHistory';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Supplies the acting user for `reopenedBy`.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Record the reopen on the transitioning FiscalPeriod (REQ-PC-006).
	 *
	 * @param array<string,mixed> $objectData Payload after the state moved to `open`.
	 * @param array<string,mixed> $previousData Payload before the transition (carries the prior close stamps).
	 * @param array<string,mixed> $parameters Declared `actionParameters` (`target`).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The payload with the history appended and the close stamps cleared.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $actionName is required by
	 * OpenRegister's LifecycleActionInterface; this handler serves exactly one
	 * declared action and has no dispatch to do on the name.
	 *
	 * @spec openspec/specs/bookkeeping-period-close/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$target = trim((string)($parameters['target'] ?? self::DEFAULT_TARGET));
		if ($target === '') {
			$target = self::DEFAULT_TARGET;
		}

		$history = ($objectData[$target] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$closedAt = ($previousData['closedAt'] ?? ($objectData['closedAt'] ?? null));
		$closedBy = ($previousData['closedBy'] ?? ($objectData['closedBy'] ?? null));

		if ($this->alreadyRecorded(history: $history, closedAt: $closedAt, closedBy: $closedBy) === false) {
			$history[] = [
				'closedAt' => $closedAt,
				'closedBy' => $closedBy,
				'reopenedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'reopenedBy' => $this->actor(),
				'closeReason' => (string)($objectData['closeReason'] ?? ''),
			];
		}

		$objectData[$target] = $history;
		$objectData['closedAt'] = null;
		$objectData['closedBy'] = null;

		return $objectData;
	}//end execute()

	/**
	 * Whether the tail of the history already records this same reopen.
	 *
	 * @param array<int,mixed> $history The current reopenedHistory entries.
	 * @param mixed $closedAt The prior close timestamp.
	 * @param mixed $closedBy The prior closing actor.
	 *
	 * @return bool True when the last entry already describes this reopen.
	 */
	private function alreadyRecorded(array $history, mixed $closedAt, mixed $closedBy): bool {
		if ($history === []) {
			return false;
		}

		$last = end($history);
		if (is_array($last) === false) {
			return false;
		}

		return ($last['closedAt'] ?? null) === $closedAt
			&& ($last['closedBy'] ?? null) === $closedBy
			&& ($last['reopenedAt'] ?? null) !== null;

	}//end alreadyRecorded()

	/**
	 * Resolve the acting user id, falling back to `system` outside a session.
	 *
	 * @return string The acting user id.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()
}//end class
