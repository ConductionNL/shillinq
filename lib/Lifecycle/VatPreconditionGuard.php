<?php

/**
 * VAT Precondition Guard
 *
 * ADR-031 lifecycle guard for ARInvoice.issue VAT precondition checks. This guard
 * validates service-category/vatRate compatibility (REQ-VAT-002) and confirms VAT
 * GL accounts are configured (REQ-VAT-006) before allowing invoice issuance. Referenced
 * from ARInvoice.x-openregister-lifecycle.transitions.issue.requires.
 *
 * ADR-031 exception reason: the precondition checks require cross-schema lookups
 * (InvoiceLine by invoiceId, ServiceCategoryOverride by administrationId, VATGLAccounts
 * by administrationId) that the declarative lifecycle DSL cannot yet express as combined
 * multi-schema precondition predicates. PHP guard is the correct pattern per ADR-031 §3.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for ARInvoice VAT validation on the issue transition.
 *
 * Checks REQ-VAT-002 (service-category permits vatRate) and REQ-VAT-006 (VAT GL
 * accounts configured) before allowing ARInvoice to transition from draft to issued.
 * Fail-closed: any exception returns false (denies issuance) per CWE-863 / ADR-005.
 *
 * Permitted vatRate values per serviceCategory (REQ-VAT-002):
 * - product: {0, 6, 21}
 * - service: {0, 9, 21}
 * - exempt: {0}
 *
 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
 */
class VatPreconditionGuard {

	/**
	 * Permitted VAT rates per service category per REQ-VAT-002.
	 *
	 * @var array<string, array<int>>
	 */
	private const PERMITTED_RATES = [
		'product' => [0, 6, 21],
		'service' => [0, 9, 21],
		'exempt' => [0],
	];

	/**
	 * Construct with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Validate all VAT preconditions for the given ARInvoice before issuance.
	 *
	 * Returns true only when:
	 * 1. All InvoiceLine.vatRate values are permitted by their serviceCategory,
	 *    or an active ServiceCategoryOverride exists for the administration.
	 * 2. VATGLAccounts record exists and all four rate accounts are configured
	 *    for the invoice's administrationId.
	 *
	 * @param string $invoiceId The ARInvoice.id to validate.
	 * @param string $administrationId The administration FK on the ARInvoice.
	 *
	 * @return bool True when all preconditions pass and issuance may proceed.
	 *
	 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
	 */
	public function validate(string $invoiceId, string $administrationId): bool {
		try {
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($register === '') {
				$register = 'shillinq';
			}

			if ($this->vatGlAccountsMissing(
				objectService: $this->objectService,
				register: $register,
				administrationId: $administrationId
			) === true
			) {
				$this->logger->warning(
					'VatPreconditionGuard: VAT GL accounts not configured — blocking issuance',
					['administrationId' => $administrationId, 'invoiceId' => $invoiceId]
				);
				return false;
			}

			$lines = $this->objectService
				->setRegister($register)
				->setSchema('InvoiceLine')
				->findAll(['filters' => ['invoiceId' => $invoiceId]]);

			$overrides = $this->loadOverrides(
				objectService: $this->objectService,
				register: $register,
				administrationId: $administrationId
			);

			foreach ($lines as $line) {
				$category = (string)($line['serviceCategory'] ?? 'product');
				$rate = (int)($line['vatRate'] ?? 21);
				$seq = (int)($line['lineSequence'] ?? 0);

				if ($this->ratePermitted(category: $category, rate: $rate, overrides: $overrides) === false) {
					$this->logger->warning(
						'VatPreconditionGuard: line rejected — serviceCategory does not permit vatRate',
						[
							'invoiceId' => $invoiceId,
							'lineSequence' => $seq,
							'serviceCategory' => $category,
							'vatRate' => $rate,
						]
					);
					return false;
				}
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'VatPreconditionGuard: precondition check failed — denying issue transition (fail-closed)',
				['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end validate()

	/**
	 * Check whether VAT GL accounts are missing for the given administration.
	 *
	 * @param object $objectService ObjectService instance.
	 * @param string $register Register slug.
	 * @param string $administrationId Administration FK.
	 *
	 * @return bool True when accounts are NOT configured (blocking condition).
	 */
	private function vatGlAccountsMissing(
		object $objectService,
		string $register,
		string $administrationId,
	): bool {
		$records = $objectService
			->setRegister($register)
			->setSchema('VATGLAccounts')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		if (count($records) === 0) {
			return true;
		}

		$config = $records[0];
		foreach (['vat21Account', 'vat9Account', 'vat6Account', 'vat0Account'] as $field) {
			if (empty($config[$field]) === true) {
				return true;
			}
		}

		return false;
	}//end vatGlAccountsMissing()

	/**
	 * Load active ServiceCategoryOverride records for this administration.
	 *
	 * Returns an indexed set of "category:rate" keys for fast O(1) lookup.
	 *
	 * @param object $objectService ObjectService instance.
	 * @param string $register Register slug.
	 * @param string $administrationId Administration FK.
	 *
	 * @return array<string, bool> Keys in format "{category}:{rate}" => true.
	 */
	private function loadOverrides(
		object $objectService,
		string $register,
		string $administrationId,
	): array {
		$overrideRecords = $objectService
			->setRegister($register)
			->setSchema('ServiceCategoryOverride')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$overrides = [];
		foreach ($overrideRecords as $override) {
			$category = (string)($override['serviceCategory'] ?? '');
			$rate = (int)($override['vatRate'] ?? -1);
			$overrides["{$category}:{$rate}"] = true;
		}

		return $overrides;
	}//end loadOverrides()

	/**
	 * Determine whether the given serviceCategory permits the given vatRate.
	 *
	 * Checks the built-in permitted-rates table first, then falls back to
	 * active administration override records.
	 *
	 * @param string $category The service category ("product", "service", "exempt").
	 * @param int $rate The VAT rate integer (0, 6, 9, 21).
	 * @param array<string,bool> $overrides Active override keys "{category}:{rate}" => true.
	 *
	 * @return bool True when the combination is permitted.
	 */
	private function ratePermitted(string $category, int $rate, array $overrides): bool {
		$permitted = self::PERMITTED_RATES[$category] ?? [];
		if (in_array($rate, $permitted, strict: true) === true) {
			return true;
		}

		return isset($overrides["{$category}:{$rate}"]);
	}//end ratePermitted()
}//end class
