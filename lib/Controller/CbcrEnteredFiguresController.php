<?php

/**
 * Shillinq CbC Entered Figures Controller
 *
 * "Enter figures" and "Import figures" on a group entity whose books are not
 * kept in Shillinq (REQ-CBC-002): the figures of a fiscal year as entered in
 * the form, or as pasted CSV text with one figure per line.
 *
 * Authenticated (#[NoAdminRequired]) and authorised through the ultimate
 * parent's administration: an entity of a group whose parent administration
 * the caller may not use is answered 404, as an unknown one is.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrEnteredFiguresService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entered and imported CbC figures of a group entity.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 */
class CbcrEnteredFiguresController extends Controller {

	/**
	 * The longest CSV text an import reads.
	 */
	private const MAX_TEXT = 65536;

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request   The request.
	 * @param CbcrEnteredFiguresService             $entered   Stores entered and imported figures.
	 * @param ObjectServiceInterface                $objects   OpenRegister's object service.
	 * @param AdministrationContextServiceInterface $context   Which administrations the caller may use.
	 * @param IAppConfig                            $appConfig Names the register.
	 * @param IL10N                                 $l10n      Translated refusals.
	 * @param LoggerInterface                       $logger    Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly CbcrEnteredFiguresService $entered,
		private readonly ObjectServiceInterface $objects,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store the figures of an entity for a fiscal year, as entered in the form ("Enter figures").
	 *
	 * @param string $entityId The GroupEntityRegistry id.
	 *
	 * @return JSONResponse The stored figures; 400 without a year, 404 outside the caller's groups, 422 on a refused figure.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	#[NoAdminRequired]
	public function enter(string $entityId): JSONResponse {
		$entity = $this->accessibleEntity(id: $entityId);
		if ($entity === null) {
			return $this->notFound();
		}

		$input = [];
		foreach (array_merge(CbcrEnteredFiguresService::REQUIRED_FIGURES, CbcrEnteredFiguresService::OPTIONAL_FIGURES, ['currency']) as $field) {
			$input[$field] = $this->request->getParam($field);
		}

		return $this->store(entity: $entity, work: fn (array $entity, int $year): array => $this->entered->enter(
			entity: $entity,
			fiscalYear: $year,
			input: $input
		));
	}//end enter()

	/**
	 * Store the figures of an entity for a fiscal year from pasted CSV text,
	 * one figure per line ("Import figures").
	 *
	 * @param string $entityId The GroupEntityRegistry id.
	 *
	 * @return JSONResponse The stored figures; 400 without a year or text, 404 outside the caller's groups, 422 on a refused line or figure.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	#[NoAdminRequired]
	public function import(string $entityId): JSONResponse {
		$entity = $this->accessibleEntity(id: $entityId);
		if ($entity === null) {
			return $this->notFound();
		}

		$text = $this->request->getParam('figures', '');
		if (is_string($text) === false || trim($text) === '' || strlen($text) > self::MAX_TEXT) {
			return new JSONResponse(['error' => $this->l10n->t('Paste the figures, one per line.')], Http::STATUS_BAD_REQUEST);
		}

		return $this->store(entity: $entity, work: fn (array $entity, int $year): array => $this->entered->enter(
			entity: $entity,
			fiscalYear: $year,
			input: $this->entered->parse(text: $text),
			source: 'imported'
		));
	}//end import()

	/**
	 * Run a store for the request's fiscal year and answer with its record or refusal.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param callable            $work   Stores the figures: fn (array $entity, int $year): array.
	 *
	 * @return JSONResponse
	 */
	private function store(array $entity, callable $work): JSONResponse {
		$year = $this->request->getParam('fiscalYear', '');
		if (is_numeric($year) === false || (int)$year < 2000 || (int)$year > 2100) {
			return new JSONResponse(['error' => $this->l10n->t('Give the fiscal year, for example 2026.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($work($entity, (int)$year));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('CbcrEnteredFiguresController: storing figures failed', ['exception' => $e->getMessage()]);

			return new JSONResponse(['error' => $this->l10n->t('The figures could not be stored.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end store()

	/**
	 * The entity, when it exists and the caller may use its ultimate parent's administration.
	 *
	 * @param string $id The GroupEntityRegistry id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function accessibleEntity(string $id): ?array {
		$entity = $this->entity(id: $id);
		if ($entity === null) {
			return null;
		}

		$upe   = $entity;
		$upeId = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($upeId !== '' && $upeId !== (string)($entity['id'] ?? '')) {
			$upe = $this->entity(id: $upeId);
		}

		$administrationId = trim((string)($upe['administrationId'] ?? ''));
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		return $entity;
	}//end accessibleEntity()

	/**
	 * A group entity by id, or null.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function entity(string $id): ?array {
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $id) !== 1) {
			return null;
		}

		try {
			return ObjectIdentifier::findOne(scoped: $this->objects->setRegister($this->register())->setSchema('GroupEntityRegistry'), id: $id);
		} catch (Throwable) {
			return null;
		}
	}//end entity()

	/**
	 * The 404 answer.
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('Not found')], Http::STATUS_NOT_FOUND);
	}//end notFound()

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
