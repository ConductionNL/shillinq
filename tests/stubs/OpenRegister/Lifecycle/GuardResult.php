<?php

/**
 * Minimal test stub mirroring OCA\OpenRegister\Lifecycle\GuardResult.
 *
 * See LifecycleGuardInterface.php in this same directory for why this stub
 * exists. Mirrors the real openregister/lib/Lifecycle/GuardResult.php API
 * exactly (allow()/deny()/isAllowed()/getMessage()).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Allow / deny verdict from a guard, optionally with a deny message.
 */
final class GuardResult {

	/**
	 * Private constructor — use static factories.
	 *
	 * @param bool $allowed Whether the transition should be allowed.
	 * @param string|null $message Optional deny message.
	 */
	private function __construct(
		private readonly bool $allowed,
		private readonly ?string $message,
	) {
	}//end __construct()

	/**
	 * Allow the transition.
	 *
	 * @return self Allow verdict instance.
	 */
	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	/**
	 * Deny the transition with a user-visible message.
	 *
	 * @param string $message Human-readable reason.
	 *
	 * @return self Deny verdict instance.
	 */
	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	/**
	 * Read whether the verdict allows the transition.
	 *
	 * @return bool True when allowed, false when denied.
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Read the deny message, if any.
	 *
	 * @return string|null Deny message, or null when allowed or unset.
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
