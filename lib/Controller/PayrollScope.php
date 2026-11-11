<?php

/**
 * Payroll Scope
 *
 * The administration, period and (for a payslip) employee one payroll request is asked for.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The format-checked scope parameters of one payroll request, in the order they are read.
 *
 * A value that is blank or not a short slug is held as ''. This is input
 * validation only; it says nothing about whether the caller may see the
 * administration.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
final class PayrollScope {
	/**
	 * Identifier pattern shared by all scope parameters (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Hold the checked values.
	 *
	 * @param array<string,string> $values Parameter name => checked value, in read order.
	 */
	private function __construct(private readonly array $values) {
	}//end __construct()

	/**
	 * Read administration_id and periode_id.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public static function forPeriod(IRequest $request): self {
		$administrationId = self::scopeParam(request: $request, name: 'administration_id');
		$periodId = self::scopeParam(request: $request, name: 'periode_id');

		return new self(values: ['administration_id' => $administrationId, 'periode_id' => $periodId]);
	}//end forPeriod()

	/**
	 * Read administration_id, werknemer_id and periode_id.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public static function forEmployeePeriod(IRequest $request): self {
		$administrationId = self::scopeParam(request: $request, name: 'administration_id');
		$employeeId = self::scopeParam(request: $request, name: 'werknemer_id');
		$periodId = self::scopeParam(request: $request, name: 'periode_id');

		return new self(
			values: [
				'administration_id' => $administrationId,
				'werknemer_id' => $employeeId,
				'periode_id' => $periodId,
			]
		);
	}//end forEmployeePeriod()

	/**
	 * The administration id, or '' when blank or malformed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function administrationId(): string {
		return $this->values['administration_id'];
	}//end administrationId()

	/**
	 * The employee id, or '' when blank, malformed or not asked for.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function employeeId(): string {
		return $this->values['werknemer_id'] ?? '';
	}//end employeeId()

	/**
	 * The period id, or '' when blank or malformed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function periodId(): string {
		return $this->values['periode_id'];
	}//end periodId()

	/**
	 * The validation message for the first blank or malformed value.
	 *
	 * @return string|null The message, or null when every value is valid.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function firstBlank(): string|null {
		foreach ($this->values as $name => $value) {
			if ($value === '') {
				return sprintf('%s is verplicht en moet een geldige identifier zijn', $name);
			}
		}

		return null;
	}//end firstBlank()

	/**
	 * The log context for a failed computation.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function logContext(\Throwable $e): array {
		return [
			'administrationId' => $this->administrationId(),
			'periodId' => $this->periodId(),
			'exception' => $e->getMessage(),
		];
	}//end logContext()

	/**
	 * Read and validate one scope parameter, returning '' when blank or malformed.
	 *
	 * @param IRequest $request The request.
	 * @param string   $name    Query-parameter name.
	 *
	 * @return string The validated value or ''.
	 */
	private static function scopeParam(IRequest $request, string $name): string {
		$value = trim((string)$request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()
}//end class
