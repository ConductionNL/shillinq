<?php

/**
 * Shillinq country-by-country file controller
 *
 * Authenticated (#[NoAdminRequired]) and authorised per administration: the
 * caller must be a member of the administration of the report's ultimate
 * parent, the same rule as the report's checks; any other report reads as
 * not found.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * "Generate XML" on a country-by-country report.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
class CbcrFileController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request   The request.
	 * @param CbcrFileService                       $files     Generates and links the file.
	 * @param ObjectServiceInterface                $objects   OpenRegister's object service.
	 * @param AdministrationContextServiceInterface $context   Which administrations the caller may use.
	 * @param IAppConfig                            $appConfig Names the register.
	 * @param IL10N                                 $l10n      Translated answers.
	 * @param LoggerInterface                       $logger    Logger.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		IRequest $request,
		private readonly CbcrFileService $files,
		private readonly ObjectServiceInterface $objects,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Generate the report's CbC file, refused while a blocking check fails or the file does not pass the schema.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return JSONResponse 201 with the GeneratedReport and the return; 422 with the reason
	 *                      when refused; 404 for a report the caller may not see.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	#[NoAdminRequired]
	public function generateCbc(string $returnId): JSONResponse {
		$return = $this->record(schema: 'CbcrReturn', id: $returnId);
		$upe    = $this->upeOf(entity: $this->record(schema: 'GroupEntityRegistry', id: (string)($return['reportingEntity'] ?? '')));
		$adminId = trim((string)($upe['administrationId'] ?? ''));
		if ($return === null || $adminId === '' || $this->context->canAccess(administrationId: $adminId) !== true) {
			return new JSONResponse(['error' => $this->l10n->t('Not found')], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse($this->files->generateCbc(return: $return, administrationId: $adminId), Http::STATUS_CREATED);
		} catch (RuntimeException $refused) {
			return new JSONResponse(['error' => $refused->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $error) {
			$this->logger->error('CbcrFileController: generating the file failed', ['exception' => $error->getMessage()]);
			return new JSONResponse(['error' => $this->l10n->t('The action could not be completed.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end generateCbc()

	/**
	 * The ultimate parent of an entity, the entity itself when it names none.
	 *
	 * @param array<string,mixed>|null $entity The entity.
	 *
	 * @return array<string,mixed>
	 */
	private function upeOf(?array $entity): array {
		if ($entity === null) {
			return [];
		}

		$upeId = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($upeId === '' || $upeId === (string)($entity['id'] ?? '')) {
			return $entity;
		}

		return ($this->record(schema: 'GroupEntityRegistry', id: $upeId) ?? []);
	}//end upeOf()

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
