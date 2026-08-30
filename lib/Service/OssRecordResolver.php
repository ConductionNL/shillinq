<?php

/**
 * OSS Record Resolver
 *
 * Change revive-gl-tax-capabilities: the storage-aware half of the OSS
 * payment reconciliation. {@see \OCA\Shillinq\Service\OssPaymentReconciliation}
 * is deliberately pure logic ("no persistence: the caller transitions the
 * OssReturn / OssPayment lifecycle on the returned decisions") — but no
 * caller was ever written, so `reconcileDistribution()` and, through the
 * broken guard tag, `canMarkPaid()` both had zero production reach
 * (shillinq#446, design D2).
 *
 * This resolver is the single place that reads the counterpart record, shared
 * by {@see \OCA\Shillinq\Guard\OssPaymentGuard} (which must resolve the
 * OssReturn behind an OssPayment, or vice versa) and
 * {@see \OCA\Shillinq\Listener\OssPaymentReconciliationListener} (which
 * reconciles the confirmed per-country distribution against the declared one).
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Reads OssReturn / OssPayment records through the real ObjectService API.
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class OssRecordResolver {

	/**
	 * OssReturn schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_RETURN = 'OssReturn';

	/**
	 * OssPayment schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_PAYMENT = 'OssPayment';

	/**
	 * Construct the resolver.
	 *
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Resolve the OssReturn an OssPayment settles.
	 *
	 * @param array<string,mixed> $ossPayment The payment record.
	 *
	 * @return array<string,mixed>|null The linked return, or null when unresolvable.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findReturnForPayment(array $ossPayment): ?array {
		$returnId = trim((string)($ossPayment['ossReturnId'] ?? ''));
		if ($returnId === '') {
			return null;
		}

		$rows = $this->findAll(
			schema: self::SCHEMA_RETURN,
			filters: ['id' => $returnId]
		);

		foreach ($rows as $row) {
			return $row;
		}

		return null;
	}//end findReturnForPayment()

	/**
	 * Resolve the OssPayment settling an OssReturn.
	 *
	 * @param array<string,mixed> $ossReturn The return record.
	 *
	 * @return array<string,mixed>|null The linked payment, or null when none exists.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findPaymentForReturn(array $ossReturn): ?array {
		$returnId = trim((string)($ossReturn['id'] ?? ((($ossReturn['@self'] ?? [])['id']) ?? '')));
		if ($returnId === '') {
			return null;
		}

		$rows = $this->findAll(
			schema: self::SCHEMA_PAYMENT,
			filters: ['ossReturnId' => $returnId]
		);

		foreach ($rows as $row) {
			return $row;
		}

		return null;
	}//end findPaymentForReturn()

	/**
	 * Persist an OssPayment through the real ObjectService API.
	 *
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record.
	 *
	 * @throws \RuntimeException When the row type is unsupported.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function savePayment(array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA_PAYMENT)
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, which
		// extends JsonSerializable and declares `getObject(): array` — so the
		// is_object()/method_exists() guards that used to wrap these two calls
		// could never be false, and the trailing throw was unreachable.
		// jsonSerialize() still returns mixed, so that check stays.
		$out = $saved->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return $saved->getObject();
	}//end savePayment()

	/**
	 * Find all records via the real ObjectService API (findAll).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'OssRecordResolver: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
