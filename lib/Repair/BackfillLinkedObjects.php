<?php

/**
 * Backfill Linked Objects
 *
 * A payment request raised on an object named it in `subject` only. Every
 * financial document now names its objects in `linkedObjects`, which is what
 * the financial documents leaf reads. This step gives each request that has a
 * `subject` and no list a one-item list holding that subject.
 * `LinkedObjectsLockListener` allows exactly that change on a kept list.
 *
 * Idempotent: a request that already has a list is left as it is. The writes
 * are unscoped, because the repair context has no user.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives every payment request with a subject its one-item list of linked objects.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 */
class BackfillLinkedObjects implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema this step writes.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register slug.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
	 */
	public function getName(): string {
		return 'Shillinq: list the object each payment request is about in its linked objects';
	}//end getName()

	/**
	 * Write the list on every request that needs it.
	 *
	 * @param IOutput $output The repair-step output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
	 */
	public function run(IOutput $output): void {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();
			$rows = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $registerSlug,
				schema: self::SCHEMA,
				filters: ['subjectKind' => 'object']
			);

			$listed = 0;
			foreach ($rows as $row) {
				$request = $this->rowPayload(row: $row);
				$uuid = ObjectIdentifier::resolve(saved: $row);
				$subject = $request['subject'] ?? null;
				if ($uuid === '' || is_array($subject) === false || $subject === [] || empty($request['linkedObjects']) === false) {
					continue;
				}

				$payload = $this->withoutMetadata(row: $request);
				$payload['linkedObjects'] = [$subject];
				$this->objectService->saveObject(
					object: $payload,
					register: $registerSlug,
					schema: self::SCHEMA,
					uuid: $uuid,
					_rbac: false,
					_multitenancy: false,
				);
				$listed++;
			}

			$output->info(sprintf('Shillinq: %d payment request(s) given their linked object.', $listed));
		} catch (Throwable $e) {
			$output->warning('Shillinq: the linked objects backfill failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: the linked objects backfill failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end run()

	/**
	 * A row without its id and OpenRegister metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function withoutMetadata(array $row): array {
		$data = [];
		foreach ($row as $key => $value) {
			if ($key === 'id' || str_starts_with((string)$key, '@') === true) {
				continue;
			}

			$data[$key] = $value;
		}

		return $data;
	}//end withoutMetadata()
}//end class
