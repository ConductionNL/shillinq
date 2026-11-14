<?php

/**
 * Shillinq CbC and Minimum Tax Controller
 *
 * The actions of the Group entities, Country-by-country reports and
 * Minimum tax returns pages: assess a group's scope, read an entity's
 * figures from the books, prepare a CbC report's figures for every entity
 * of the group, run the safe harbour tests, apply the Dutch QDMTT, and show
 * the checks of a CbC report or GloBE information return (design D6,
 * REQ-CBC-011).
 *
 * Authenticated (#[NoAdminRequired]) and authorised per administration:
 * the caller must be a member of the administration that keeps the books
 * the action reads, and for a group action of the ultimate parent's
 * administration, through AdministrationContextService::canAccess(). A
 * refusal answers 404, as for an unknown record (ADR-005, REQ-MA-001).
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
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcrFiguresService;
use OCA\Shillinq\Service\Cbcr\GroupScopeService;
use OCA\Shillinq\Service\Cbcr\Pillar2SafeHarbourService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * CbC and minimum tax page actions.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 */
class CbcrController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request     The request.
	 * @param GroupScopeService                     $scope       The scope assessment.
	 * @param CbcrFiguresService                    $figures     Figures from the books.
	 * @param Pillar2SafeHarbourService             $safeHarbour Safe harbour tests and QDMTT.
	 * @param CbcrCheckRunner                       $checks      The CbC and GIR checks.
	 * @param ObjectServiceInterface                $objects     OpenRegister's object service.
	 * @param AdministrationContextServiceInterface $context     Which administrations the caller may use.
	 * @param IUserSession                          $session     The caller.
	 * @param IAppConfig                            $appConfig   Names the register.
	 * @param IL10N                                 $l10n        Translated refusals.
	 * @param LoggerInterface                       $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly GroupScopeService $scope,
		private readonly CbcrFiguresService $figures,
		private readonly Pillar2SafeHarbourService $safeHarbour,
		private readonly CbcrCheckRunner $checks,
		private readonly ObjectServiceInterface $objects,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $session,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Assess the group's scope for a fiscal year ("Assess scope" on the ultimate parent).
	 *
	 * @param string $entityId The ultimate parent's GroupEntityRegistry id.
	 *
	 * @return JSONResponse The stored assessment.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	#[NoAdminRequired]
	public function assessScope(string $entityId): JSONResponse {
		$entity = $this->entityFor(id: $entityId);
		if ($entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		$entered = [];
		foreach ((array)$this->request->getParam('enteredRevenue', []) as $year => $amount) {
			if (is_numeric($year) === true && is_numeric($amount) === true) {
				$entered[(int)$year] = (float)$amount;
			}
		}

		return $this->run(
			work: fn (int $year): array => $this->scope->assess(
				upeId: $entityId,
				fiscalYear: $year,
				entered: $entered,
				actor: (string)($this->session->getUser()?->getUID() ?? 'system')
			)
		);
	}//end assessScope()

	/**
	 * Read one entity's figures for a fiscal year from its books ("Read figures from the books").
	 *
	 * @param string $entityId The GroupEntityRegistry id.
	 *
	 * @return JSONResponse The stored figures.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	#[NoAdminRequired]
	public function readFigures(string $entityId): JSONResponse {
		$entity = $this->entityFor(id: $entityId);
		if ($entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		return $this->run(work: fn (int $year): array => $this->figures->readFromBooks(entityId: $entityId, fiscalYear: $year));
	}//end readFigures()

	/**
	 * Read the figures of every group entity that keeps its books here, and sum
	 * them per jurisdiction ("Prepare from the books" on a CbC report).
	 *
	 * @param string $entityId The ultimate parent's GroupEntityRegistry id.
	 *
	 * @return JSONResponse The summaries, the skipped jurisdictions, the entities without figures and the entities that could not be read.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
	 */
	#[NoAdminRequired]
	public function prepare(string $entityId): JSONResponse {
		$entity = $this->entityFor(id: $entityId);
		if ($entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		return $this->run(work: fn (int $year): array => $this->prepareYear(upeId: $entityId, year: $year));
	}//end prepare()

	/**
	 * Run the safe harbour tests of a minimum tax year ("Run safe harbour tests").
	 *
	 * @param string $entityId The ultimate parent's GroupEntityRegistry id.
	 *
	 * @return JSONResponse Per jurisdiction: the tests passed and the draft computation.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
	 */
	#[NoAdminRequired]
	public function safeHarbour(string $entityId): JSONResponse {
		$entity = $this->entityFor(id: $entityId);
		if ($entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		return $this->run(work: fn (int $year): array => ['jurisdictions' => $this->safeHarbour->test(upeId: $entityId, fiscalYear: $year)]);
	}//end safeHarbour()

	/**
	 * Apply the Dutch QDMTT to a jurisdiction computation.
	 *
	 * @param string $computationId The Pillar2JurisdictionComputation id.
	 *
	 * @return JSONResponse The QDMTT return, or null when no QDMTT applies.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
	 */
	#[NoAdminRequired]
	public function qdmtt(string $computationId): JSONResponse {
		$computation = $this->record(schema: 'Pillar2JurisdictionComputation', id: $computationId);
		$entity      = $this->entityFor(id: (string)($computation['ultimateParentEntity'] ?? ''));
		if ($computation === null || $entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		try {
			return new JSONResponse(['qdmttReturn' => $this->safeHarbour->applyQdmtt(computationId: $computationId)]);
		} catch (Throwable $e) {
			return $this->failed(error: $e);
		}
	}//end qdmtt()

	/**
	 * The checks of a CbC report.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return JSONResponse Every check with pass or fail, and the blocking failures.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
	 */
	#[NoAdminRequired]
	public function cbcChecks(string $returnId): JSONResponse {
		$return = $this->record(schema: 'CbcrReturn', id: $returnId);
		$entity = $this->entityFor(id: (string)($return['reportingEntity'] ?? ''));
		if ($return === null || $entity === null || $this->canAccessBooks(entity: $this->upeOf(entity: $entity)) === false) {
			return $this->notFound();
		}

		return $this->checksResponse(results: $this->checks->runCbc(return: $return));
	}//end cbcChecks()

	/**
	 * The checks of a GloBE information return.
	 *
	 * @param string $returnId The GlobeInformationReturn id.
	 *
	 * @return JSONResponse Every check with pass or fail, and the blocking failures.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
	 */
	#[NoAdminRequired]
	public function girChecks(string $returnId): JSONResponse {
		$return = $this->record(schema: 'GlobeInformationReturn', id: $returnId);
		$entity = $this->entityFor(id: (string)($return['ultimateParent'] ?? ''));
		if ($return === null || $entity === null || $this->canAccessBooks(entity: $entity) === false) {
			return $this->notFound();
		}

		return $this->checksResponse(results: $this->checks->runGir(return: $return));
	}//end girChecks()

	/**
	 * Read every bookkeeping entity of the group, then summarise.
	 *
	 * @param string $upeId The ultimate parent.
	 * @param int    $year  The fiscal year.
	 *
	 * @return array<string,mixed>
	 */
	private function prepareYear(string $upeId, int $year): array {
		$failed = [];
		foreach ($this->groupEntities(upeId: $upeId) as $member) {
			$id = (string)($member['id'] ?? '');
			if (trim((string)($member['administrationId'] ?? '')) === '') {
				continue;
			}

			if ($this->canAccessBooks(entity: $member) === false) {
				$failed[$id] = $this->l10n->t('You have no access to this entity\'s administration.');
				continue;
			}

			try {
				$this->figures->readFromBooks(entityId: $id, fiscalYear: $year);
			} catch (InvalidArgumentException | RuntimeException $e) {
				$failed[$id] = $e->getMessage();
			}
		}

		return $this->figures->summarise(upeId: $upeId, fiscalYear: $year) + ['notRead' => $failed];
	}//end prepareYear()

	/**
	 * Run one year-bound action and answer with its result.
	 *
	 * @param callable(int):array<string,mixed> $work The action, given the fiscal year.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $work): JSONResponse {
		$year = (int)$this->request->getParam('fiscalYear', 0);
		if ($year < 2000 || $year > 2100) {
			return new JSONResponse(['error' => $this->l10n->t('Give the fiscal year, for example 2026.')], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($work($year));
		} catch (Throwable $e) {
			return $this->failed(error: $e);
		}
	}//end run()

	/**
	 * The answer for a refused or failed action: the reason for a refusal, a generic line otherwise.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return JSONResponse
	 */
	private function failed(Throwable $error): JSONResponse {
		if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('CbcrController: action failed', ['exception' => $error->getMessage()]);

		return new JSONResponse(['error' => $this->l10n->t('The action could not be completed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failed()

	/**
	 * The checks answer.
	 *
	 * @param array<int,array{id:string,severity:string,passed:bool,source:string,statement:string}> $results The results.
	 *
	 * @return JSONResponse
	 */
	private function checksResponse(array $results): JSONResponse {
		return new JSONResponse(['checks' => $results, 'blocking' => CbcrCheckRunner::blockingFailures(results: $results)]);
	}//end checksResponse()

	/**
	 * Whether the caller is a member of the entity's administration.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return bool
	 */
	private function canAccessBooks(array $entity): bool {
		$administrationId = trim((string)($entity['administrationId'] ?? ''));

		return $administrationId !== '' && $this->context->canAccess(administrationId: $administrationId) === true;
	}//end canAccessBooks()

	/**
	 * The ultimate parent of an entity, the entity itself when it names none.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return array<string,mixed>
	 */
	private function upeOf(array $entity): array {
		$upeId = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($upeId === '' || $upeId === (string)($entity['id'] ?? '')) {
			return $entity;
		}

		return ($this->entityFor(id: $upeId) ?? []);
	}//end upeOf()

	/**
	 * The entities of a group.
	 *
	 * @param string $upeId The ultimate parent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function groupEntities(string $upeId): array {
		$members = [];
		foreach ($this->objects->setRegister($this->register())->setSchema('GroupEntityRegistry')->findAll(['limit' => 10000]) as $row) {
			$entity = ObjectIdentifier::recordWithId(candidate: $row);
			if ($entity !== null && ((string)($entity['id'] ?? '') === $upeId || (string)($entity['ultimateParentEntity'] ?? '') === $upeId)) {
				$members[] = $entity;
			}
		}

		return $members;
	}//end groupEntities()

	/**
	 * A group entity by id, null when absent.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function entityFor(string $id): ?array {
		return $this->record(schema: 'GroupEntityRegistry', id: $id);
	}//end entityFor()

	/**
	 * A record by id, null when absent or unreadable.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function record(string $schema, string $id): ?array {
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $id) !== 1) {
			return null;
		}

		try {
			return ObjectIdentifier::findOne(scoped: $this->objects->setRegister($this->register())->setSchema($schema), id: $id);
		} catch (Throwable) {
			return null;
		}
	}//end record()

	/**
	 * The 404 every refusal answers with.
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
