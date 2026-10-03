<?php

/**
 * Payment Request Finder
 *
 * Every object payment request standing on one subject, read to the last page.
 *
 * The leaf used to read one page of 200 object requests and filter it in PHP.
 * With school contributions a shillinq holds hundreds of object requests, so the
 * requests on a case, or on a fee item past the 200th guardian, fell off that
 * page and read as absent: a raise would bill a child twice, and a handler would
 * see a leges request go missing. The finder pages until a short page.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Reads the payment requests on one subject across every page.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
 */
final class PaymentRequestFinder {
	/**
	 * Rows per page.
	 *
	 * @var integer
	 */
	public const PAGE = 200;

	/**
	 * A ceiling on pages, so a store that ignores `offset` cannot loop forever.
	 * 500 pages is 100,000 object requests.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 500;

	/**
	 * The schema holding payment requests.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param ObjectPaymentRequestValidator $validator The subject key.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectPaymentRequestValidator $validator,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every object request whose subject is this object, with its `id`.
	 *
	 * @param string $register The subject's register.
	 * @param string $schema The subject's schema.
	 * @param string $objectId The subject's id.
	 * @param bool $asSystem True to read past the caller's rights, for a check
	 *                       that must see every request (the raise's duplicate
	 *                       check); false to read as the caller (the leaf).
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whose rights a read runs under
	 * is data the caller decides, not a mode that changes what the method does.
	 */
	public function onSubject(string $register, string $schema, string $objectId, bool $asSystem = false): array {
		$key = $this->validator->subjectKey(['register' => $register, 'schema' => $schema, 'id' => $objectId]);

		return $this->scan(
			keep: fn (array $request): bool => is_array($request['subject'] ?? null) === true
				&& $this->validator->subjectKey((array)$request['subject']) === $key,
			asSystem: $asSystem,
		);
	}//end onSubject()

	/**
	 * Every object request that carries this transfer reference, compared
	 * without case, whatever its subject (REQ-ORS-002).
	 *
	 * @param string $reference The transfer reference.
	 * @param bool $asSystem True to read past the caller's rights, so the
	 *                       uniqueness check sees requests the caller cannot.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whose rights a read runs under
	 * is data the caller decides, not a mode that changes what the method does.
	 */
	public function withReference(string $reference, bool $asSystem = false): array {
		$wanted = mb_strtolower(trim($reference));
		if ($wanted === '') {
			return [];
		}

		return $this->scan(
			keep: static fn (array $request): bool => mb_strtolower(trim((string)($request['paymentReference'] ?? ''))) === $wanted,
			asSystem: $asSystem,
		);
	}//end withReference()

	/**
	 * Read every object request to the last page and keep the ones asked for.
	 *
	 * @param callable(array<string, mixed>): bool $keep Decides per request.
	 * @param bool $asSystem Read past the caller's rights.
	 *
	 * @return array<int, array<string, mixed>> The kept requests, each with its `id`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) See onSubject().
	 */
	private function scan(callable $keep, bool $asSystem): array {
		$scoped = $this->objectService->setRegister($this->registerSlug())->setSchema(self::SCHEMA);

		$kept = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $scoped->findAll(
				['filters' => ['subjectKind' => 'object'], 'limit' => self::PAGE, 'offset' => ($page * self::PAGE)],
				_rbac: ($asSystem === false),
				_multitenancy: ($asSystem === false),
			);

			foreach ($rows as $row) {
				$request = ObjectIdentifier::recordWithId(candidate: $row);
				if ($request !== null && $keep($request) === true) {
					$kept[] = $request;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $kept;
	}//end scan()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
