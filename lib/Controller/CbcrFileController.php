<?php

/**
 * Shillinq country-by-country file controller
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IL10N;
use OCP\IRequest;
use RuntimeException;

/**
 * "Generate XML" on a country-by-country report, for any signed-in user; the
 * service answers only to a member of the group's administration.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
class CbcrFileController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request The request.
	 * @param CbcrFileService $files   Generates and links the file, for members of the group only.
	 * @param IL10N           $l10n    Translated answers.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		IRequest $request,
		private readonly CbcrFileService $files,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Generate the report's CbC file, refused while a blocking check fails or the file does not pass the schema.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return Http\JSONResponse 201 with the GeneratedReport and the return; 422 with the reason
	 *                      when refused; 404 for a report the caller may not see. Any other
	 *                      failure is left to the framework, which logs it and answers 500.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	#[NoAdminRequired]
	public function generateCbc(string $returnId): Http\JSONResponse {
		return $this->answer(generate: fn (): ?array => $this->files->generateCbc(returnId: $returnId));
	}//end generateCbc()

	/**
	 * Generate the minimum tax return's GloBE information return, refused while a
	 * blocking check fails, an element is missing or the file does not pass the schema.
	 *
	 * @param string $returnId The GlobeInformationReturn id.
	 *
	 * @return Http\JSONResponse 201 with the GeneratedReport and the return; 422 with the reason
	 *                      when refused; 404 for a return the caller may not see.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
	 */
	#[NoAdminRequired]
	public function generateGir(string $returnId): Http\JSONResponse {
		return $this->answer(generate: fn (): ?array => $this->files->generateGir(returnId: $returnId));
	}//end generateGir()

	/**
	 * The answer to a Generate XML: 201 with the result, 404 when null, 422 with the refusal.
	 *
	 * @param callable(): (array<string,mixed>|null) $generate Generates and links the file.
	 *
	 * @return Http\JSONResponse
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function answer(callable $generate): Http\JSONResponse {
		try {
			$result = $generate();
		} catch (RuntimeException $refused) {
			return new Http\JSONResponse(['error' => $refused->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($result === null) {
			return new Http\JSONResponse(['error' => $this->l10n->t('Not found')], Http::STATUS_NOT_FOUND);
		}

		return new Http\JSONResponse($result, Http::STATUS_CREATED);
	}//end answer()
}//end class
