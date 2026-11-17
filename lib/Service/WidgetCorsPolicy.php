<?php

/**
 * Widget CORS policy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decides which website may call the booking widget API from a browser: the
 * origins registered on the business's active widget key (REQ-WSW-011). An
 * origin is a scheme, a host and an optional port; a wildcard, a path or
 * anything else is never accepted, so a key only ever opens the API to the
 * websites an administrator named. Without an allowed origin the API answers
 * without CORS headers, which the browser refuses, exactly as before.
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
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-self-service-widget/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Resolves and stores the browser origins a widget key allows.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-self-service-widget/spec.md
 */
class WidgetCorsPolicy {
	/**
	 * At most this many origins per key.
	 */
	public const MAX_ORIGINS = 20;

	/**
	 * A scheme, a host and an optional port; nothing after it.
	 */
	private const ORIGIN_PATTERN = '#^(https?)://([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:([0-9]{1,5}))?$#';

	/**
	 * Constructor.
	 *
	 * @param WidgetAuthService $keys The widget keys.
	 */
	public function __construct(
		private readonly WidgetAuthService $keys,
	) {
	}//end __construct()

	/**
	 * An origin in its canonical form (lower case, no trailing slash, no default port), or null when it is none.
	 *
	 * @param string $origin The origin as given.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
	 */
	public static function normaliseOrigin(string $origin): ?string {
		$origin = strtolower(trim($origin));
		if (str_ends_with($origin, '/') === true) {
			$origin = substr($origin, 0, -1);
		}

		if (preg_match(self::ORIGIN_PATTERN, $origin, $parts) !== 1) {
			return null;
		}

		$scheme = $parts[1];
		$host = $parts[2];
		$port = ($parts[7] ?? '');
		if ($port === '') {
			return $scheme . '://' . $host;
		}

		$number = (int)$port;
		if ($number < 1 || $number > 65535) {
			return null;
		}

		$default = ['http' => 80, 'https' => 443][$scheme];
		if ($number === $default) {
			return $scheme . '://' . $host;
		}

		return $scheme . '://' . $host . ':' . $number;
	}//end normaliseOrigin()

	/**
	 * The origin to echo in Access-Control-Allow-Origin, or null when the business's key does not allow it.
	 *
	 * @param string $businessId The widget's business id.
	 * @param string $origin     The request's Origin header.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-self-service-widget/spec.md#requirement-the-widget-books-from-another-website-req-wsw-011
	 */
	public function allowedOrigin(string $businessId, string $origin): ?string {
		$wanted = self::normaliseOrigin(origin: $origin);
		if ($wanted === null || trim($businessId) === '') {
			return null;
		}

		$key = $this->keys->findActiveKeyByBusinessId(businessId: trim($businessId));
		if ($key === null || is_array($key['allowedOrigins'] ?? null) === false) {
			return null;
		}

		foreach ($key['allowedOrigins'] as $allowed) {
			if (is_string($allowed) === true && self::normaliseOrigin(origin: $allowed) === $wanted) {
				return $wanted;
			}
		}

		return null;
	}//end allowedOrigin()

	/**
	 * Replace the origins of a business's active key; every origin must be valid.
	 *
	 * @param string            $businessId The widget's business id.
	 * @param array<int, mixed> $origins    The origins.
	 * @param string            $actor      The administrator making the change.
	 *
	 * @return array{success: bool, message?: string, allowedOrigins?: array<int, string>}
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
	 */
	public function setAllowedOrigins(string $businessId, array $origins, string $actor): array {
		if (count($origins) > self::MAX_ORIGINS) {
			return ['success' => false, 'message' => 'A widget key allows at most ' . self::MAX_ORIGINS . ' origins.'];
		}

		$clean = [];
		foreach ($origins as $origin) {
			$normal = null;
			if (is_string($origin) === true) {
				$normal = self::normaliseOrigin(origin: $origin);
			}

			if ($normal === null) {
				return ['success' => false, 'message' => 'Each origin is a scheme and a host, such as https://www.example.com, with no path or wildcard.'];
			}

			$clean[$normal] = $normal;
		}

		return $this->keys->updateAllowedOrigins(businessId: $businessId, origins: array_values($clean), actor: $actor);
	}//end setAllowedOrigins()
}//end class
