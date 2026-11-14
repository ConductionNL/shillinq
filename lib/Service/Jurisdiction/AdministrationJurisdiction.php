<?php

/**
 * Shillinq AdministrationJurisdiction.
 *
 * Reads the jurisdiction an administration is kept under (ISO 3166 alpha-2,
 * NL when absent), refuses an output made for another jurisdiction, and says
 * whether the administration has posted transactions, which fixes its
 * jurisdiction.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Jurisdiction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Jurisdiction;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * The jurisdiction of an administration.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
class AdministrationJurisdiction {

	/**
	 * The jurisdiction of an administration that names none.
	 */
	public const DEFAULT = 'NL';

	/**
	 * The transaction states that count as posted.
	 */
	private const POSTED_STATES = ['posted', 'reversed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * A jurisdiction value as stored: two upper-case letters, NL when empty or malformed.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public static function normalise(mixed $value): string {
		$code = '';
		if (is_string($value) === true) {
			$code = strtoupper(trim($value));
		}

		if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
			return self::DEFAULT;
		}

		return $code;
	}//end normalise()

	/**
	 * The jurisdiction of an administration; NL when it names none or cannot be found.
	 *
	 * @param string $administrationId The administration's id.
	 *
	 * @return string ISO 3166 alpha-2.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function of(string $administrationId): string {
		$administrationId = trim($administrationId);
		if ($administrationId === '') {
			return self::DEFAULT;
		}

		// An administration is addressed by its uuid or, throughout the app, by its administrationCode.
		$data = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($this->register())->setSchema('Administration'),
			id: $administrationId,
			fallbackProperty: 'administrationCode'
		);
		if ($data === null) {
			return self::DEFAULT;
		}

		return self::normalise(value: ($data['jurisdiction'] ?? null));
	}//end of()

	/**
	 * Refuse an output made for one jurisdiction when the administration is under another.
	 *
	 * @param string $administrationId The administration's id.
	 * @param string $output           The output asked for, for the message.
	 * @param string $required         The jurisdiction the output is for.
	 *
	 * @return void
	 *
	 * @throws JurisdictionRefusedException When the administration is under another jurisdiction.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function assertIs(string $administrationId, string $output, string $required=self::DEFAULT): void {
		$jurisdiction = $this->of(administrationId: $administrationId);
		if ($jurisdiction !== $required) {
			throw new JurisdictionRefusedException(output: $output, jurisdiction: $jurisdiction, required: $required);
		}
	}//end assertIs()

	/**
	 * Whether the administration has a posted (or reversed) transaction.
	 *
	 * @param string $administrationId The id its transactions carry: the administrationCode or the uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function hasPostings(string $administrationId): bool {
		if (trim($administrationId) === '') {
			return false;
		}

		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('GLTransaction')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true && in_array((string)($data['state'] ?? ''), self::POSTED_STATES, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasPostings()

	/**
	 * The register slug.
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
