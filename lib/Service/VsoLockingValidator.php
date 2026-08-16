<?php

/**
 * VSO Locking Validator
 *
 * Enforces the Vaststellingsovereenkomst (VSO) lock per Wet AWR + REQ-IBA-008.
 * Once a Belastingdienst VSO is signed for a fiscal year, every IBProfitAttribution
 * and NexusCalculation record for that year becomes read-only — only an audit
 * trail of attempted amendments is acceptable.
 *
 * A year is considered VSO-locked when ANY IBProfitAttribution row for that
 * administration + boekjaar has the `vso_locked` flag set to true. The flag is
 * stamped by year-end finalisation (REQ-IBA-008); the validator never mutates
 * it. The check is intentionally cheap (one OR findAll keyed on the indexed
 * filter triple) so the listeners can call it on every update event without
 * blowing the write path.
 *
 * Pure read-side. Throws nothing — a transient OR fetch failure resolves to
 * "not locked", and the listener treats the absence of evidence as a green
 * write. This mirrors the safer-fails-open pattern already used by other
 * shillinq cache invalidators (a stale "not locked" is corrected by the next
 * read; a stale "locked" would block legitimate book-closing work).
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Determines whether an innovatiebox boekjaar is VSO-locked (REQ-IBA-008).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 */
class VsoLockingValidator {
	/**
	 * Construct the validator.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger (transient OR errors are downgraded to "not locked").
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the supplied administration + boekjaar is VSO-locked.
	 *
	 * A year is locked when at least one IBProfitAttribution row for that
	 * administration + boekjaar carries `vso_locked: true`. The check is
	 * filter-scoped to an O(1)-indexed lookup.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return bool TRUE when the year is VSO-locked.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
	 */
	public function isYearLocked(string $administrationId, int $financialYear): bool {
		if ($administrationId === '' || $financialYear <= 0) {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('IBProfitAttribution')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'financialYear' => $financialYear,
							'vso_locked' => true,
						],
					]
				);
		} catch (Throwable $e) {
			// Fail-soft: a transient OR fetch failure should not crash the
			// write path. Log + return "not locked" so the listener proceeds
			// (the audit-trail listener will still record the write attempt).
			$this->logger->warning(
				'VsoLockingValidator: lock-status fetch failed; assuming unlocked',
				[
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

		if (is_array($rows) === false) {
			return false;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if (($row['vso_locked'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end isYearLocked()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
