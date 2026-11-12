<?php

/**
 * LeafAdoption stub mirroring OCA\OpenRegister\AppHost\LeafAdoption (openregister #9078).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

/**
 * Stub for OCA\OpenRegister\AppHost\LeafAdoption used by shillinq tests.
 */
final class LeafAdoption {
	/**
	 * Hold the app id and the overrides.
	 *
	 * @param string               $appId   Leaf app id.
	 * @param array<string, mixed> $options The overrides documented on Bootstrap::register().
	 */
	public function __construct(
		public readonly string $appId,
		private readonly array $options=[],
	) {
	}//end __construct()

	/**
	 * The StudlyCase form of the app id.
	 *
	 * @return string
	 */
	public function studly(): string {
		$studly = '';
		foreach (preg_split(pattern: '/[_\-]+/', subject: $this->appId) ?: [$this->appId] as $part) {
			$studly .= ucfirst($part);
		}

		return $studly;
	}//end studly()

	/**
	 * A leaf namespace: its override, or the base namespace plus the suffix.
	 *
	 * @param string $option The override key (e.g. `controllerNamespace`).
	 * @param string $suffix The segment under the base namespace (e.g. `Controller`).
	 *
	 * @return string
	 */
	public function namespace(string $option, string $suffix): string {
		$base = rtrim(string: (string)($this->options['namespace'] ?? ('OCA\\' . $this->studly())), characters: '\\');
		return (string)($this->options[$option] ?? ($base . '\\' . $suffix));
	}//end namespace()
}//end class
