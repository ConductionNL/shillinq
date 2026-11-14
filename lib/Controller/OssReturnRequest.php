<?php

/**
 * Oss Return Request
 *
 * The administration, period and registration one OSS return draft is asked for.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The OSS return parameters, read in order: administration, year, quarter, registration.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
final class OssReturnRequest {
	/**
	 * Short slug identifiers only.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Hold the values.
	 *
	 * @param array{administrationId: string, periodYear: int, periodQuarter: string, registrationId: string} $values The parameters.
	 */
	private function __construct(private readonly array $values) {
	}//end __construct()

	/**
	 * Read the parameters.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return self
	 */
	public static function fromRequest(IRequest $request): self {
		$administrationId = trim((string)$request->getParam('administration_id', ''));
		$periodYear = (int)$request->getParam('period_year', 0);
		$periodQuarter = trim((string)$request->getParam('period_quarter', ''));
		$registrationId = trim((string)$request->getParam('registration_id', ''));

		return new self(
			values: [
				'administrationId' => $administrationId,
				'periodYear' => $periodYear,
				'periodQuarter' => $periodQuarter,
				'registrationId' => $registrationId,
			]
		);
	}//end fromRequest()

	/**
	 * The administration id.
	 *
	 * @return string
	 */
	public function administrationId(): string {
		return $this->values['administrationId'];
	}//end administrationId()

	/**
	 * The period year.
	 *
	 * @return int
	 */
	public function periodYear(): int {
		return $this->values['periodYear'];
	}//end periodYear()

	/**
	 * The period quarter (Q1..Q4).
	 *
	 * @return string
	 */
	public function periodQuarter(): string {
		return $this->values['periodQuarter'];
	}//end periodQuarter()

	/**
	 * The OSS registration id.
	 *
	 * @return string
	 */
	public function registrationId(): string {
		return $this->values['registrationId'];
	}//end registrationId()

	/**
	 * The message for the first invalid parameter, in read order. A valid slug
	 * is a character-class test, not a membership check.
	 *
	 * @return string|null Null when every parameter is valid.
	 */
	public function firstInvalid(): ?string {
		if (preg_match(self::ID_PATTERN, $this->administrationId()) !== 1) {
			return 'administration_id is required';
		}

		$periodError = $this->periodError();
		if ($periodError !== null) {
			return $periodError;
		}

		if (preg_match(self::ID_PATTERN, $this->registrationId()) !== 1) {
			return 'registration_id is required';
		}

		return null;
	}//end firstInvalid()

	/**
	 * The message for a year outside 2000–2200, then for a quarter other than Q1..Q4.
	 *
	 * @return string|null
	 */
	private function periodError(): ?string {
		if ($this->periodYear() < 2000 || $this->periodYear() > 2200) {
			return 'period_year must be a valid year';
		}

		if (in_array($this->periodQuarter(), ['Q1', 'Q2', 'Q3', 'Q4'], true) === false) {
			return 'period_quarter must be one of Q1..Q4';
		}

		return null;
	}//end periodError()
}//end class
