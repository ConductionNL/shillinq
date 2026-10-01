<?php

/**
 * Fixed Asset Depreciation Controller
 *
 * The asset page's missed depreciation (assets-method-change-and-reserve,
 * REQ-AMCR-001): lists the ended months the daily run did not post, with
 * their amounts, and posts them when asked.
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Missed depreciation of one asset.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class FixedAssetDepreciationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request   The request.
	 * @param AdministrationContextService $context   Who may see which administration.
	 * @param AssetRecords                 $records   The register.
	 * @param DepreciationScheduleService  $schedules The schedule.
	 * @param ITimeFactory                 $time      The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly AssetRecords $records,
		private readonly DepreciationScheduleService $schedules,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The months that ended without their depreciation posted, with the amounts.
	 *
	 * @param string $id The asset's id.
	 *
	 * @return JSONResponse {rows: [{period, amount}], total}
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	#[NoAdminRequired]
	public function missed(string $id): JSONResponse {
		$asset = $this->authorizeAsset(id: $id, post: false);
		if ($asset instanceof JSONResponse) {
			return $asset;
		}

		return new JSONResponse($this->summary(rows: $this->schedules->missed(asset: $asset, beforeMonth: $this->thisMonth())));

	}//end missed()

	/**
	 * Post the missed months, one journal entry per month.
	 *
	 * @param string $id The asset's id.
	 *
	 * @return JSONResponse {rows: [{period, amount}], total} of what was posted, or 422 with the reason.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	#[NoAdminRequired]
	public function postMissed(string $id): JSONResponse {
		$asset = $this->authorizeAsset(id: $id, post: true);
		if ($asset instanceof JSONResponse) {
			return $asset;
		}

		try {
			return new JSONResponse($this->summary(rows: $this->schedules->postMissed(asset: $asset, beforeMonth: $this->thisMonth())));
		} catch (DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

	}//end postMissed()

	/**
	 * The asset when the caller may see it (and post in its administration), else the refusal.
	 *
	 * @param string $id   The asset's id.
	 * @param bool   $post Whether the caller must be able to post journal entries.
	 *
	 * @return array<string,mixed>|JSONResponse The asset, or 401, 403 or 404.
	 */
	private function authorizeAsset(string $id, bool $post): array|JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$asset = $this->records->find(schema: DepreciationScheduleService::ASSET, id: $id);
		$administrationId = (string)($asset['administrationId'] ?? '');
		if ($asset === null || $this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($post === true && $this->context->canPostJournalEntry(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'You may not post journal entries in this administration.'], Http::STATUS_FORBIDDEN);
		}

		return $asset;

	}//end authorizeAsset()

	/**
	 * Rows and their total.
	 *
	 * @param list<array{period: string, amount: float, lineId: string}> $rows The months.
	 *
	 * @return array{rows: list<array{period: string, amount: float}>, total: float} The summary.
	 */
	private function summary(array $rows): array {
		$total = 0;
		$out = [];
		foreach ($rows as $row) {
			$total += (int)round($row['amount'] * 100);
			$out[] = ['period' => $row['period'], 'amount' => $row['amount']];
		}

		return ['rows' => $out, 'total' => ($total / 100)];

	}//end summary()

	/**
	 * The current month, YYYY-MM.
	 *
	 * @return string The month.
	 */
	private function thisMonth(): string {
		return date('Y-m', $this->time->getTime());

	}//end thisMonth()
}//end class
