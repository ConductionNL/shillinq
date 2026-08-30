<?php

/**
 * Stock Move Reason Guard
 *
 * ADR-031 exception-path lifecycle guard enforcing the mandatory
 * `movementReason` precondition on `StockMove.post` (draft → posted).
 * The declarative lifecycle DSL can express "field is not null" but
 * cannot — yet — combine that with "field value is one of the active
 * administration-configurable reason codes". This guard performs both
 * checks in PHP so REQ-SM-007 fails the transition cleanly when the
 * operator forgets the reason code or supplies a value not in the
 * administration's configured enum.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for StockMove.post — mandatory reason code.
 *
 * Referenced from inventory-stock-movement-ledger.json
 * StockMove.x-openregister-lifecycle.transitions.post.requires as
 * OCA\Shillinq\Lifecycle\StockMoveReasonGuard::requireReasonOnPost.
 *
 * Fail-closed: missing or empty `movementReason` denies the transition.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-11
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class StockMoveReasonGuard {

	/**
	 * Default reason codes always permitted on post, irrespective of
	 * per-administration overrides per REQ-SM-007 / REQ-SM-010. Mirrors the
	 * `movementReason` enum declared in the StockMove schema fragment.
	 *
	 * @var array<int,string>
	 */
	private const STANDARD_REASON_CODES = [
		'normal',
		'damaged',
		'expired',
		'shrinkage',
		'inter-warehouse',
		'adjustment',
		'sample',
		'demo',
		'theft',
		'loss',
		'cancellation',
		'manufacture',
		'repack',
	];

	/**
	 * Construct the guard.
	 *
	 * @param IAppConfig $appConfig App config — used to read per-administration custom reason codes.
	 * @param LoggerInterface $logger Logger for diagnostics; never leaks the secret list.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Returns true iff the supplied StockMove carries a valid `movementReason`
	 * that is permitted to post per REQ-SM-007.
	 *
	 * Accepts the lifecycle engine's standard payload: an associative array
	 * representing the StockMove. The administration scope is read from
	 * `administrationId`; configured custom codes are unioned with the standard
	 * set.
	 *
	 * Fail-closed: any missing field, empty value, unknown code, or
	 * unexpected exception denies the transition.
	 *
	 * @param array<string,mixed> $move The StockMove payload being transitioned.
	 *
	 * @return bool True when the move may be posted; false otherwise.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-11
	 */
	public function requireReasonOnPost(array $move): bool {
		try {
			if (isset($move['movementReason']) === true) {
				$reason = trim((string)$move['movementReason']);
			} else {
				$reason = '';
			}

			if ($reason === '') {
				$this->logger->info(
					'StockMoveReasonGuard: post denied — movementReason missing',
					['movementNumber' => ($move['movementNumber'] ?? null)]
				);
				return false;
			}

			if (isset($move['administrationId']) === true) {
				$administrationId = (string)$move['administrationId'];
			} else {
				$administrationId = '';
			}

			$allowed = $this->allowedReasonCodes(administrationId: $administrationId);

			return in_array(needle: $reason, haystack: $allowed, strict: true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StockMoveReasonGuard: reason check failed — denying post (fail-closed)',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end requireReasonOnPost()

	/**
	 * Compose the allowed reason-code set: standard codes plus per-administration
	 * extras read from app config (`stockmove_reason_codes_<adminId>`, CSV).
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<int,string>
	 */
	private function allowedReasonCodes(string $administrationId): array {
		$codes = self::STANDARD_REASON_CODES;
		if ($administrationId === '') {
			return $codes;
		}

		try {
			$key = 'stockmove_reason_codes_' . $administrationId;
			$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($raw === '') {
				return $codes;
			}

			$extras = array_filter(
				array_map('trim', explode(',', $raw)),
				static fn (string $c): bool => $c !== ''
			);

			return array_values(array_unique(array_merge($codes, $extras)));
		} catch (\Throwable $e) {
			// Fall back to the standard set; we never fail-closed for a config read failure here
			// — the standard codes always remain valid.
			$this->logger->debug(
				'StockMoveReasonGuard: failed to read per-administration reason codes; using defaults',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return self::STANDARD_REASON_CODES;
		}//end try

	}//end allowedReasonCodes()
}//end class
