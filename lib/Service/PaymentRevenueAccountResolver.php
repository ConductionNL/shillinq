<?php

/**
 * Payment Revenue Account Resolver
 *
 * ADR-107 puts the ledger in shillinq and only in shillinq: a case app asks a
 * citizen for money, and shillinq decides where the receipt lands. That
 * decision is a mapping from the request type (`leges`, `dwangsom`, `deposit`,
 * `other`) to a general-ledger account, held in one app-config value
 * `paymentRevenueAccounts` so an administrator can change it without a
 * release.
 *
 * A type with no mapping is not booked to a guessed account. It resolves to
 * null, the caller leaves the request in `captured_unapplied`, and the reason
 * names the type that is missing (REQ-SOPR-002). A receipt on the wrong
 * account is worse than a receipt that waits.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCP\IAppConfig;

/**
 * Resolves the revenue account a captured object request books against.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 */
final class PaymentRevenueAccountResolver {
	/**
	 * The app-config key holding the JSON map of request type to account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'paymentRevenueAccounts';

	/**
	 * The keys an administrator can map: every `PaymentRequest.requestType`
	 * plus `clearing`, the account the money arrives through.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = ['leges', 'dwangsom', 'deposit', 'other', 'contribution', 'event-fee', 'clearing'];

	/**
	 * What an account number may look like: digits, letters, dots, dashes and
	 * underscores, at most 32 characters.
	 *
	 * @var string
	 */
	private const ACCOUNT_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app config, holding the mapping.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The account a request type books against, or null when nothing is mapped.
	 *
	 * @param string $requestType One of leges, dwangsom, deposit, other, contribution, event-fee.
	 *
	 * @return string|null The account number, or null when the type is unmapped.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function resolve(string $requestType): ?string {
		$account = (string)($this->all()[$requestType] ?? '');

		if ($account === '') {
			return null;
		}

		return $account;
	}//end resolve()

	/**
	 * The whole mapping, for the administration screen and for a reason that
	 * wants to say which types ARE mapped.
	 *
	 * @return array<string, string> Request type to account number.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function all(): array {
		$raw = $this->appConfig->getValueString('shillinq', self::CONFIG_KEY, '');
		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $type => $account) {
			if (is_string($type) === false || is_scalar($account) === false) {
				continue;
			}

			$account = trim((string)$account);
			if ($account !== '') {
				$map[$type] = $account;
			}
		}

		return $map;
	}//end all()

	/**
	 * Store the submitted part of the mapping, as the administration screen
	 * sends it: one value per type. A submitted empty value removes that
	 * type's mapping, a type not submitted keeps what it had, and a key that
	 * is not a request type is ignored. Every value is checked before
	 * anything is written, so a refused save stores nothing.
	 *
	 * @param array<string, mixed> $submitted Request type to account number.
	 *
	 * @return array<string, string> The mapping as stored afterwards.
	 *
	 * @throws InvalidArgumentException When a value is not an account number.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function save(array $submitted): array {
		$map = $this->all();

		foreach (self::TYPES as $type) {
			if (array_key_exists($type, $submitted) === false || $submitted[$type] === null) {
				continue;
			}

			if (is_scalar($submitted[$type]) === false) {
				throw new InvalidArgumentException(sprintf('The account for "%s" must be an account number.', $type));
			}

			$account = trim((string)$submitted[$type]);
			if ($account === '') {
				unset($map[$type]);
				continue;
			}

			if (preg_match(self::ACCOUNT_PATTERN, $account) !== 1) {
				throw new InvalidArgumentException(
					sprintf('The account for "%s" must be an account number of letters, digits, dots or dashes.', $type)
				);
			}

			$map[$type] = $account;
		}

		$this->appConfig->setValueString('shillinq', self::CONFIG_KEY, json_encode($map, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES));

		return $map;
	}//end save()

	/**
	 * The mapping with every type present, empty where nothing is mapped, so
	 * the administration screen can show one field per type.
	 *
	 * @return array<string, string> Request type to account number or ''.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function form(): array {
		$map = $this->all();
		$form = [];
		foreach (self::TYPES as $type) {
			$form[$type] = ($map[$type] ?? '');
		}

		return $form;
	}//end form()
}//end class
