<?php

/**
 * Register Record Finder
 *
 * Queries one schema of shillinq's configured OpenRegister register through
 * the lazily-resolved ObjectService — the lookup {@see RateCardResolver} and
 * {@see RetainerResolver} share. Failures propagate; each caller logs them
 * under its own name.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Filtered findAll() over the configured register.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
class RegisterRecordFinder {

	/**
	 * Construct the finder.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig         $appConfig App config for the register slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every record of a schema matching the filters; [] when the store answers
	 * with something other than a list.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \Throwable When the ObjectService is unavailable or the query fails.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function find(string $schema, array $filters): array {
		$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$rs = $svc->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
		if (is_array($rs) === true) {
			return $rs;
		}

		return [];
	}//end find()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
