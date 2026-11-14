<?php

/**
 * Backfill the country-by-country fields of group entities
 *
 * The CbC file needs the OECD business activity codes and a tax
 * identification number per entity. Group entities written before
 * tax-cbcr-and-pillar2-returns carry one `mainBusinessActivity` from an
 * older list and no TIN. This step maps the old value onto `bizActivities`
 * and fills `tin` (issued by NL) from the RSIN of the entity's
 * administration, only where the new field is still empty, so a rerun or a
 * value someone already typed is left alone (design D2, REQ-CBC-002).
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Maps `mainBusinessActivity` into `bizActivities` and fills `tin` from the RSIN. Idempotent.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-1.2
 */
class BackfillGroupEntityCbcFields implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * The old activity values and the OECD code each one becomes. Values the
	 * OECD list has no match for (a plain "financial", "other") become CBC513
	 * Other, for the user to refine on the entity page.
	 *
	 * @var array<string,string>
	 */
	public const ACTIVITY_CODES = [
		'research-development' => 'CBC501',
		'purchasing-procurement' => 'CBC503',
		'manufacturing-production' => 'CBC504',
		'sales-marketing-distribution' => 'CBC505',
		'services' => 'CBC507',
		'internal-group-finance' => 'CBC508',
		'regulated-financial-service' => 'CBC509',
		'insurance' => 'CBC510',
		'holding' => 'CBC511',
		'financial' => 'CBC513',
		'other' => 'CBC513',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface    $objectService OpenRegister's object service.
	 * @param RegisterLocationInterface $settings      Where the register lives.
	 * @param LoggerInterface           $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-1.2
	 */
	public function getName(): string {
		return 'Shillinq: fill the country-by-country activity codes and TIN of group entities';

	}//end getName()

	/**
	 * Fill `bizActivities` and `tin` where they are empty.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-1.2
	 */
	public function run(IOutput $output): void {
		$this->withSystemIdentity(
			objectService: $this->objectService,
			work: function () use ($output): void {
				$this->backfill(output: $output);
			}
		);

	}//end run()

	/**
	 * The work, under the system identity.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function backfill(IOutput $output): void {
		try {
			$register = $this->settings->getRegisterSlug();
			$entities = $this->objectService->setRegister($register)->setSchema('GroupEntityRegistry')->findAll(['limit' => 100000]);
			$changed  = 0;
			foreach ($entities as $row) {
				$entity = ObjectIdentifier::recordWithId(candidate: $row);
				$id     = (string)($entity['id'] ?? '');
				if ($entity === null || $id === '') {
					continue;
				}

				$patch = $this->patchFor(entity: $entity, register: $register);
				if ($patch === []) {
					continue;
				}

				$this->objectService->patchObject(objectId: $id, data: $patch, register: $register, schema: 'GroupEntityRegistry');
				$changed++;
			}

			$output->info(sprintf('Shillinq: %d group entit(y/ies) given country-by-country activity codes or a TIN.', $changed));
		} catch (Throwable $e) {
			$output->warning('Shillinq: filling the country-by-country fields of group entities failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: filling the country-by-country fields of group entities failed', ['exception' => $e->getMessage()]);
		}//end try

	}//end backfill()

	/**
	 * The fields to write on one entity; empty when it needs nothing.
	 *
	 * @param array<string,mixed> $entity   The group entity.
	 * @param string              $register The register slug.
	 *
	 * @return array<string,mixed>
	 */
	private function patchFor(array $entity, string $register): array {
		$patch = [];

		$current = $entity['bizActivities'] ?? [];
		$code    = self::ACTIVITY_CODES[(string)($entity['mainBusinessActivity'] ?? '')] ?? null;
		if ((is_array($current) === false || $current === []) && $code !== null) {
			$patch['bizActivities'] = [$code];
		}

		$administrationId = trim((string)($entity['administrationId'] ?? ''));
		if (trim((string)($entity['tin'] ?? '')) === '' && $administrationId !== '') {
			$rsin = $this->rsinOf(administrationId: $administrationId, register: $register);
			if ($rsin !== '') {
				$patch['tin'] = $rsin;
				if (trim((string)($entity['tinIssuedBy'] ?? '')) === '') {
					$patch['tinIssuedBy'] = 'NL';
				}
			}
		}

		return $patch;

	}//end patchFor()

	/**
	 * The administration's RSIN, digits only and padded to nine; empty when it has none.
	 *
	 * @param string $administrationId The administration.
	 * @param string $register         The register slug.
	 *
	 * @return string
	 */
	private function rsinOf(string $administrationId, string $register): string {
		try {
			$administration = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($register)->setSchema('Administration'),
				id: $administrationId,
				fallbackProperty: 'administrationCode'
			);
		} catch (Throwable) {
			return '';
		}

		$digits = preg_replace('/\D/', '', (string)($administration['rsin'] ?? ''));
		if ($digits === null || $digits === '' || strlen($digits) > 9) {
			return '';
		}

		return str_pad($digits, 9, '0', STR_PAD_LEFT);

	}//end rsinOf()
}//end class
