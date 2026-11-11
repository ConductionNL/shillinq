<?php

/**
 * Characterisation tests for the outcomes of the WBSO account, document and
 * transaction APIs once access is allowed.
 *
 * Pins, per action, the arguments handed to the service, the success
 * response, and how each service failure is logged and answered.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\WbsoAccountApiController;
use OCA\Shillinq\Controller\WbsoDocumentApiController;
use OCA\Shillinq\Controller\WbsoTransactionApiController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the WBSO API outcomes.
 */
final class WbsoApiOutcomesTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = ['administration_id' => 'adm-1'];

	/**
	 * Logged errors as message and context.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * Whether the caller holds the administrator role (all others are held).
	 *
	 * @var boolean
	 */
	private bool $administrator = true;

	/**
	 * The service mock.
	 *
	 * @var MockObject
	 */
	private MockObject $service;

	/**
	 * Build a controller of a kind, with every access step allowed.
	 *
	 * @param string $kind account, document or transaction.
	 *
	 * @return object
	 */
	private function controller(string $kind): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$rbac = $this->createMock(WbsoRbacResolver::class);
		$rbac->method('hasAny')->willReturnCallback(
			fn (array $allowed): bool => ($this->administrator === true || $allowed !== ['administrator'])
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$context->method('canPostJournalEntry')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		if ($kind === 'account') {
			$this->service = $this->createMock(WbsoAccountService::class);
			return new WbsoAccountApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);
		}

		if ($kind === 'document') {
			$this->service = $this->createMock(WbsoDocumentService::class);
			return new WbsoDocumentApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);
		}

		$this->service = $this->createMock(WbsoTransactionService::class);
		return new WbsoTransactionApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);

	}//end controller()

	/**
	 * Call an action on a built controller.
	 *
	 * @param object $controller The controller.
	 * @param string $method The action.
	 * @param string|null $argument The route argument.
	 *
	 * @return JSONResponse
	 */
	private static function invoke(object $controller, string $method, ?string $argument): JSONResponse {
		if ($argument === null) {
			return $controller->$method();
		}

		return $controller->$method($argument);

	}//end invoke()

	/**
	 * Service failures per action and what they become.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function failures(): array {
		$invalid = new InvalidArgumentException('bad input');
		$conflict = new RuntimeException('wrong state');
		$error = new \Error('db down');
		$exception = ['exception' => 'db down'];
		$docCtx = ['administrationId' => 'adm-1', 'documentId' => 'doc-1', 'exception' => 'bad input'];
		$docCtx409 = ['administrationId' => 'adm-1', 'documentId' => 'doc-1', 'exception' => 'wrong state'];
		return [
			'account index error' => ['account', 'index', null, 'getAccountsByAdministration', $error, 500, ['error' => 'Failed to load accounts'], ['WbsoAccountApiController: Failed to load accounts', $exception]],
			'account hierarchy error' => ['account', 'hierarchy', null, 'getAccountHierarchy', $error, 500, ['error' => 'Failed to load chart-of-accounts'], ['WbsoAccountApiController: Failed to load chart-of-accounts', $exception]],
			'account show error' => ['account', 'show', '1000', 'getAccountByNumber', $error, 500, ['error' => 'Failed to load account'], ['WbsoAccountApiController: Failed to load account', $exception]],
			'account create invalid' => ['account', 'create', null, 'createAccount', $invalid, 400, ['message' => 'Unable to create account: the submitted details are invalid', 'error' => 'wbso-account-create-failed'], ['WbsoAccountApiController: account creation payload rejected', ['administrationId' => 'adm-1', 'exception' => 'bad input']]],
			'account create error' => ['account', 'create', null, 'createAccount', $error, 500, ['error' => 'Failed to create account'], ['WbsoAccountApiController: Failed to create account', $exception]],
			'account update not found' => ['account', 'update', '1000', 'updateAccount', new InvalidArgumentException('Account not found'), 404, ['error' => 'Account not found'], null],
			'account update invalid' => ['account', 'update', '1000', 'updateAccount', $invalid, 400, ['message' => 'Unable to update account: the submitted details are invalid', 'error' => 'wbso-account-update-failed'], ['WbsoAccountApiController: account update payload rejected', ['administrationId' => 'adm-1', 'accountNumber' => '1000', 'exception' => 'bad input']]],
			'account update error' => ['account', 'update', '1000', 'updateAccount', $error, 500, ['error' => 'Failed to update account'], ['WbsoAccountApiController: Failed to update account', $exception]],
			'document index invalid' => ['document', 'index', null, 'getDocumentsByAdministration', $invalid, 400, ['message' => 'Unable to load documents: the filter is invalid', 'error' => 'wbso-document-list-failed'], ['WbsoDocumentApiController: document list filter rejected', ['administrationId' => 'adm-1', 'type' => '', 'exception' => 'bad input']]],
			'document index error' => ['document', 'index', null, 'getDocumentsByAdministration', $error, 500, ['error' => 'Failed to load documents'], ['WbsoDocumentApiController: Failed to load documents', $exception]],
			'document show error' => ['document', 'show', 'doc-1', 'getDocument', $error, 500, ['error' => 'Failed to load document'], ['WbsoDocumentApiController: Failed to load document', $exception]],
			'document create invalid' => ['document', 'create', null, 'createDocument', $invalid, 400, ['message' => 'Unable to create document: the submitted details are invalid', 'error' => 'wbso-document-create-failed'], ['WbsoDocumentApiController: document creation payload rejected', ['administrationId' => 'adm-1', 'exception' => 'bad input']]],
			'document create error' => ['document', 'create', null, 'createDocument', $error, 500, ['error' => 'Failed to create document'], ['WbsoDocumentApiController: Failed to create document', $exception]],
			'document file not found' => ['document', 'file', 'doc-1', 'fileDocument', $invalid, 404, ['message' => 'Document not found', 'error' => 'wbso-document-not-found'], ['WbsoDocumentApiController: document to file not found', $docCtx]],
			'document file conflict' => ['document', 'file', 'doc-1', 'fileDocument', $conflict, 409, ['message' => 'Unable to file this document in its current state', 'error' => 'wbso-document-file-conflict'], ['WbsoDocumentApiController: document file transition rejected', $docCtx409]],
			'document file error' => ['document', 'file', 'doc-1', 'fileDocument', $error, 500, ['error' => 'Failed to file document'], ['WbsoDocumentApiController: Failed to file document', $exception]],
			'document archive not found' => ['document', 'archive', 'doc-1', 'archiveDocument', $invalid, 404, ['message' => 'Document not found', 'error' => 'wbso-document-not-found'], ['WbsoDocumentApiController: document to archive not found', $docCtx]],
			'document archive conflict' => ['document', 'archive', 'doc-1', 'archiveDocument', $conflict, 409, ['message' => 'Unable to archive this document in its current state', 'error' => 'wbso-document-archive-conflict'], ['WbsoDocumentApiController: document archive transition rejected', $docCtx409]],
			'document archive error' => ['document', 'archive', 'doc-1', 'archiveDocument', $error, 500, ['error' => 'Failed to archive document'], ['WbsoDocumentApiController: Failed to archive document', $exception]],
			'transaction index error' => ['transaction', 'index', null, 'listTransactions', $error, 500, ['error' => 'Failed to load transactions'], ['WbsoTransactionApiController: Failed to load transactions', $exception]],
			'transaction show error' => ['transaction', 'show', 'tx-1', 'getTransaction', $error, 500, ['error' => 'Failed to load transaction'], ['WbsoTransactionApiController: Failed to load transaction', $exception]],
			'transaction create invalid' => ['transaction', 'create', null, 'createTransaction', $invalid, 400, ['message' => 'Invalid transaction data', 'error' => 'wbso-transaction-invalid-input'], ['WbsoTransactionApiController.create failed', ['exception' => $invalid]]],
			'transaction create error' => ['transaction', 'create', null, 'createTransaction', $error, 500, ['error' => 'Failed to create transaction'], ['WbsoTransactionApiController: Failed to create transaction', $exception]],
			'transaction post not found' => ['transaction', 'post', 'tx-1', 'postTransaction', $invalid, 404, ['message' => 'Transaction not found', 'error' => 'wbso-transaction-not-found'], ['WbsoTransactionApiController.post failed', ['exception' => $invalid]]],
			'transaction post conflict' => ['transaction', 'post', 'tx-1', 'postTransaction', $conflict, 409, ['message' => 'Unable to post transaction', 'error' => 'wbso-transaction-conflict'], ['WbsoTransactionApiController.post failed', ['exception' => $conflict]]],
			'transaction post error' => ['transaction', 'post', 'tx-1', 'postTransaction', $error, 500, ['error' => 'Failed to post transaction'], ['WbsoTransactionApiController: Failed to post transaction', $exception]],
			'transaction reverse not found' => ['transaction', 'reverse', 'tx-1', 'reverseTransaction', new InvalidArgumentException('Transaction not found'), 404, ['message' => 'Transaction not found', 'error' => 'wbso-transaction-not-found'], null],
			'transaction reverse invalid' => ['transaction', 'reverse', 'tx-1', 'reverseTransaction', $invalid, 400, ['message' => 'Invalid reversal request', 'error' => 'wbso-transaction-invalid-input'], ['WbsoTransactionApiController.reverse failed', ['exception' => $invalid]]],
			'transaction reverse conflict' => ['transaction', 'reverse', 'tx-1', 'reverseTransaction', $conflict, 409, ['message' => 'Unable to reverse transaction', 'error' => 'wbso-transaction-conflict'], ['WbsoTransactionApiController.reverse failed', ['exception' => $conflict]]],
			'transaction reverse error' => ['transaction', 'reverse', 'tx-1', 'reverseTransaction', $error, 500, ['error' => 'Failed to reverse transaction'], ['WbsoTransactionApiController: Failed to reverse transaction', $exception]],
		];

	}//end failures()

	/**
	 * Each service failure is logged and answered as before.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param string $serviceMethod Service method.
	 * @param \Throwable $failure The failure.
	 * @param int $status Expected status.
	 * @param array<string,string> $body Expected body.
	 * @param array<int,mixed>|null $log Expected log entry; null when the
	 *                                   reverse not-found path logs first.
	 *
	 * @return void
	 *
	 * @dataProvider failures
	 */
	public function testServiceFailureMapping(string $kind, string $method, ?string $argument, string $serviceMethod, \Throwable $failure, int $status, array $body, ?array $log): void {
		$controller = $this->controller($kind);
		$this->service->method($serviceMethod)->willThrowException($failure);

		$response = self::invoke($controller, $method, $argument);

		self::assertSame([$status, $body], [$response->getStatus(), $response->getData()]);
		if ($log !== null) {
			self::assertSame([$log], $this->logged);
		}

	}//end testServiceFailureMapping()

	/**
	 * A reverse that cannot find the transaction is logged before it is answered,
	 * an account update that cannot find the account is not logged at all.
	 *
	 * @return void
	 */
	public function testNotFoundLogging(): void {
		$notFound = new InvalidArgumentException('Transaction not found');
		$controller = $this->controller('transaction');
		$this->service->method('reverseTransaction')->willThrowException($notFound);
		self::invoke($controller, 'reverse', 'tx-1');
		self::assertSame([['WbsoTransactionApiController.reverse failed', ['exception' => $notFound]]], $this->logged);

		$this->logged = [];
		$controller = $this->controller('account');
		$this->service->method('updateAccount')->willThrowException(new InvalidArgumentException('Account not found'));
		self::invoke($controller, 'update', '1000');
		self::assertSame([], $this->logged);

	}//end testNotFoundLogging()

	/**
	 * The arguments and success responses of the write actions.
	 *
	 * @return void
	 */
	public function testWriteActionsSucceed(): void {
		$this->params = [
			'administration_id' => 'adm-1',
			'documentType' => 'invoice',
			'documentNumber' => 'D1',
			'documentDate' => '2026-01-01',
			'reason' => ' typo ',
			'allowEarly' => '1',
			'transactionNumber' => 'T1',
			'amount' => '10.5',
			'name' => 'Cash',
			'status' => null,
		];

		$document = $this->controller('document');
		$this->service->expects(self::once())->method('createDocument')
			->with('adm-1', ['documentType' => 'invoice', 'documentNumber' => 'D1', 'documentDate' => '2026-01-01'])
			->willReturn(['id' => 'doc-1']);
		$response = $document->create();
		self::assertSame([201, ['id' => 'doc-1']], [$response->getStatus(), $response->getData()]);

		$document = $this->controller('document');
		$this->service->expects(self::once())->method('fileDocument')->with('adm-1', 'doc-1', 'alice')->willReturn(['filed' => true]);
		$response = $document->file('doc-1');
		self::assertSame([200, ['filed' => true]], [$response->getStatus(), $response->getData()]);

		$document = $this->controller('document');
		$this->service->expects(self::once())->method('archiveDocument')->with('adm-1', 'doc-1', ' typo ', true)->willReturn(['archived' => true]);
		$response = $document->archive('doc-1');
		self::assertSame([200, ['archived' => true]], [$response->getStatus(), $response->getData()]);

		$transaction = $this->controller('transaction');
		$this->service->expects(self::once())->method('createTransaction')
			->with('adm-1', ['transactionNumber' => 'T1', 'transactionType' => '', 'transactionDate' => '', 'amount' => '10.5', 'description' => ''])
			->willReturn(['id' => 'tx-1']);
		$response = $transaction->create();
		self::assertSame([201, ['id' => 'tx-1']], [$response->getStatus(), $response->getData()]);

		$transaction = $this->controller('transaction');
		$this->service->expects(self::once())->method('reverseTransaction')->with('adm-1', 'tx-1', 'typo')->willReturn(['id' => 'tx-2']);
		$response = $transaction->reverse('tx-1');
		self::assertSame([201, ['id' => 'tx-2']], [$response->getStatus(), $response->getData()]);

		$account = $this->controller('account');
		$this->service->expects(self::once())->method('createAccount')->with('adm-1', ['name' => 'Cash'])->willReturn(['id' => 'a']);
		$response = $account->create();
		self::assertSame([201, ['id' => 'a']], [$response->getStatus(), $response->getData()]);

	}//end testWriteActionsSucceed()

	/**
	 * The listings filter and expose whether the caller may create.
	 *
	 * @return void
	 */
	public function testListings(): void {
		$rows = [
			['id' => 1, 'status' => 'filed', 'filedAt' => '2026-03-01'],
			['id' => 2, 'status' => 'draft', 'documentDate' => '2026-04-01'],
			['id' => 3, 'status' => 'filed', 'filedAt' => '2025-12-01'],
		];
		$this->params = ['administration_id' => 'adm-1', 'type' => 'invoice', 'status' => 'filed', 'filedFrom' => '2026-01-01'];
		$this->administrator = false;
		$document = $this->controller('document');
		$this->service->expects(self::once())->method('getDocumentsByType')->with('adm-1', 'invoice')->willReturn($rows);
		$response = $document->index();
		self::assertSame([200, ['documents' => [$rows[0]], 'canCreate' => true]], [$response->getStatus(), $response->getData()]);

		$this->params = ['administration_id' => 'adm-1', 'status' => 'posted', 'type' => 'memo', 'dateFrom' => 'a', 'dateTo' => 'b'];
		$transaction = $this->controller('transaction');
		$this->service->expects(self::once())->method('listTransactions')
			->with('adm-1', ['status' => 'posted', 'type' => 'memo', 'dateFrom' => 'a', 'dateTo' => 'b'])->willReturn([['t' => 1]]);
		$response = $transaction->index();
		self::assertSame([200, ['transactions' => [['t' => 1]], 'canCreate' => true]], [$response->getStatus(), $response->getData()]);

		$this->params = ['administration_id' => 'adm-1'];
		$account = $this->controller('account');
		$this->service->method('getAccountsByAdministration')->willReturn([['n' => 1]]);
		$response = $account->index();
		self::assertSame([200, ['accounts' => [['n' => 1]], 'canCreate' => false]], [$response->getStatus(), $response->getData()]);

	}//end testListings()

	/**
	 * A missing document, transaction or account is a 404 with its message.
	 *
	 * @return void
	 */
	public function testShowMissing(): void {
		foreach (['document' => ['getDocument', 'Document not found'], 'transaction' => ['getTransaction', 'Transaction not found'], 'account' => ['getAccountByNumber', 'Account not found']] as $kind => [$serviceMethod, $message]) {
			$controller = $this->controller($kind);
			$this->service->method($serviceMethod)->willReturn(null);
			$response = $controller->show('x-1');
			self::assertSame([404, ['error' => $message]], [$response->getStatus(), $response->getData()]);
		}

	}//end testShowMissing()
}//end class
