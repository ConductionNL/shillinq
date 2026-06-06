<?php

/**
 * Unit tests for VATReturnController.
 *
 * Exercises the HTTP-shape and validation paths for the
 * bookkeeping-vat-btw-filing change (issue #127). Service interactions
 * are mocked; OR ObjectService interactions are mocked through the
 * container so the real OR-API call shape stays honest.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VATReturnController;
use OCA\Shillinq\Service\VATReturnService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the VAT-return API controller.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VATReturnControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock service.
     *
     * @var VATReturnService&MockObject
     */
    private VATReturnService&MockObject $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $session;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Controller under test.
     *
     * @var VATReturnController
     */
    private VATReturnController $controller;

    /**
     * Currently-authenticated user (mutable across a single test).
     *
     * @var IUser|null
     */
    private ?IUser $currentUser = null;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request    = $this->createMock(IRequest::class);
        $this->service    = $this->createMock(VATReturnService::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->session    = $this->createMock(IUserSession::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->controller = new VATReturnController(
            request: $this->request,
            service: $this->service,
            container: $this->container,
            session: $this->session,
            logger: $this->logger,
        );

        // Bind the session once to a mutable reference; tests can override the
        // current user mid-test via withUser() without re-stubbing the mock.
        $this->session->method('getUser')->willReturnCallback(
            fn (): ?IUser => $this->currentUser
        );
        $this->withUser(uid: 'admin');

    }//end setUp()

    /**
     * Configure request params via callback.
     *
     * @param array<string,mixed> $params Param key/value map.
     *
     * @return void
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($params): mixed {
                return $params[$key] ?? $default;
            }
        );

    }//end withParams()

    /**
     * Bind the container to return an inline ObjectService fake.
     *
     * @param object $stub Inline stub.
     *
     * @return void
     */
    private function withObjectService(object $stub): void
    {
        $this->container->method('get')->willReturn($stub);

    }//end withObjectService()

    /**
     * Bind the session to return a user with the given uid.
     *
     * @param string $uid User uid.
     *
     * @return void
     */
    private function withUser(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->currentUser = $user;

    }//end withUser()

    /**
     * Build an inline ObjectService fake.
     *
     * @param array<string,array<int,array<string,mixed>>> $data Schema => records.
     *
     * @return object
     */
    private function fakeObjectService(array $data): object
    {
        return new class($data)
        {

            /**
             * Records keyed by schema slug.
             *
             * @var array<string,array<int,array<string,mixed>>>
             */
            private array $data;

            /**
             * Active schema.
             *
             * @var string
             */
            private string $schema = '';

            /**
             * Constructor.
             *
             * @param array<string,array<int,array<string,mixed>>> $data Schema => records.
             */
            public function __construct(array $data)
            {
                $this->data = $data;
            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Fluent schema setter.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->schema = $schema;
                return $this;
            }//end setSchema()

            /**
             * Equality-filter findAll.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params=[]): array
            {
                $rows    = ($this->data[$this->schema] ?? []);
                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($row[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }//end findAll()

            /**
             * Find by id.
             *
             * @param string $id Record id.
             *
             * @return array<string,mixed>|null
             */
            public function find(string $id): ?array
            {
                foreach (($this->data[$this->schema] ?? []) as $row) {
                    if (((string) ($row['id'] ?? '')) === $id) {
                        return $row;
                    }
                }

                return null;
            }//end find()

            /**
             * Save (insert).
             *
             * @param array<string,mixed> $data Record body.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $data): array
            {
                $data['id']                  = ($data['id'] ?? ('id-'.count($this->data[$this->schema] ?? [])));
                $this->data[$this->schema][] = $data;
                return $data;
            }//end saveObject()

            /**
             * Delete by id.
             *
             * @param string $id Record id.
             *
             * @return void
             */
            public function deleteObject(string $id): void
            {
                foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
                    if (((string) ($row['id'] ?? '')) === $id) {
                        unset($this->data[$this->schema][$idx]);
                    }
                }

                $this->data[$this->schema] = array_values($this->data[$this->schema] ?? []);
            }//end deleteObject()
        };

    }//end fakeObjectService()

    /**
     * index() returns paginated list + total.
     *
     * @return void
     */
    public function testIndexReturnsPaginatedList(): void
    {
        $this->withParams(['_page' => 1, '_limit' => 10]);
        $this->withObjectService(
            $this->fakeObjectService(
                [
                    'VATReturn' => [
                        ['id' => 'r-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
                        ['id' => 'r-2', 'period' => 'quarter', 'regime' => 'kor', 'statusCode' => 'submitted'],
                    ],
                ]
            )
        );

        $response = $this->controller->index();
        $payload  = $response->getData();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(2, $payload['total']);
        self::assertCount(2, $payload['data']);

    }//end testIndexReturnsPaginatedList()

    /**
     * index() applies whitelisted filters (regime).
     *
     * @return void
     */
    public function testIndexFiltersByRegime(): void
    {
        $this->withParams(['regime' => 'kor', '_page' => 1, '_limit' => 10]);
        $this->withObjectService(
            $this->fakeObjectService(
                [
                    'VATReturn' => [
                        ['id' => 'r-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
                        ['id' => 'r-2', 'period' => 'quarter', 'regime' => 'kor', 'statusCode' => 'submitted'],
                    ],
                ]
            )
        );

        $response = $this->controller->index();
        $payload  = $response->getData();

        self::assertSame(1, $payload['total']);
        self::assertSame('kor', $payload['data'][0]['regime']);

    }//end testIndexFiltersByRegime()

    /**
     * show() returns the return + declarations + lines.
     *
     * @return void
     */
    public function testShowReturnsReturnWithChildren(): void
    {
        $this->withObjectService(
            $this->fakeObjectService(
                [
                    'VATReturn'      => [['id' => 'ret-1', 'statusCode' => 'draft']],
                    'VATDeclaration' => [['id' => 'd-1', 'returnId' => 'ret-1', 'type' => 'collected', 'taxRate' => 21.0]],
                    'VATLine'        => [['id' => 'l-1', 'returnId' => 'ret-1', 'type' => 'collected', 'taxRate' => 21.0]],
                ]
            )
        );

        $response = $this->controller->show(returnId: 'ret-1');
        $payload  = $response->getData();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('ret-1', $payload['data']['id']);
        self::assertCount(1, $payload['declarations']);
        self::assertCount(1, $payload['lines']);

    }//end testShowReturnsReturnWithChildren()

    /**
     * show() returns 404 when missing.
     *
     * @return void
     */
    public function testShowReturns404WhenMissing(): void
    {
        $this->withObjectService($this->fakeObjectService(['VATReturn' => []]));
        $response = $this->controller->show(returnId: 'ret-missing');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowReturns404WhenMissing()

    /**
     * create() with a future period yields 400 (REQ-VAT-001 validation).
     *
     * @return void
     */
    public function testCreateRejectsFuturePeriod(): void
    {
        $futureYear = ((int) gmdate(format: 'Y') + 1);
        $this->withParams(
            [
                'administrationId' => 'adm-1',
                'period'           => 'quarter',
                'periodYear'       => $futureYear,
                'periodNumber'     => 1,
                'regime'           => 'standard',
            ]
        );

        $response = $this->controller->create();
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateRejectsFuturePeriod()

    /**
     * create() with an invalid regime yields 400.
     *
     * @return void
     */
    public function testCreateRejectsInvalidRegime(): void
    {
        $this->withParams(
            [
                'administrationId' => 'adm-1',
                'period'           => 'quarter',
                'periodYear'       => 2026,
                'periodNumber'     => 1,
                'regime'           => 'imaginary',
            ]
        );

        $response = $this->controller->create();
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateRejectsInvalidRegime()

    /**
     * create() delegates to the service and returns 201 with the persisted record.
     *
     * @return void
     */
    public function testCreateDelegatesToService(): void
    {
        $this->withParams(
            [
                'administrationId' => 'adm-1',
                'period'           => 'quarter',
                'periodYear'       => 2024,
                'periodNumber'     => 1,
                'regime'           => 'standard',
            ]
        );
        $this->service->expects($this->once())
            ->method('createReturn')
            ->with('adm-1', 'quarter', 2024, 1, 'standard')
            ->willReturn(['id' => 'ret-new', 'statusCode' => 'draft']);

        $response = $this->controller->create();
        $payload  = $response->getData();

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertSame('ret-new', $payload['data']['id']);

    }//end testCreateDelegatesToService()

    /**
     * submit() returns 200 + the submitted return on success.
     *
     * @return void
     */
    public function testSubmitReturns200(): void
    {
        $this->withUser(uid: 'alice');
        $this->service->expects($this->once())
            ->method('submitReturn')
            ->with('ret-1', 'alice')
            ->willReturn(['id' => 'ret-1', 'statusCode' => 'submitted']);

        $response = $this->controller->submit(returnId: 'ret-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('submitted', $response->getData()['data']['statusCode']);

    }//end testSubmitReturns200()

    /**
     * submit() returns 409 when the service rejects (e.g. already submitted).
     *
     * @return void
     */
    public function testSubmitReturns409OnConflict(): void
    {
        $this->withUser(uid: 'alice');
        $this->service->method('submitReturn')->willThrowException(new \RuntimeException('not draft'));

        $response = $this->controller->submit(returnId: 'ret-1');

        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testSubmitReturns409OnConflict()

    /**
     * rebase() returns 200 + the rebased return.
     *
     * @return void
     */
    public function testRebaseReturns200(): void
    {
        $this->withUser(uid: 'bob');
        $this->service->expects($this->once())
            ->method('rebaseReturn')
            ->with('ret-1', 'bob')
            ->willReturn(['id' => 'ret-1', 'statusCode' => 'draft']);

        $response = $this->controller->rebase(returnId: 'ret-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testRebaseReturns200()

    /**
     * destroy() deletes a draft return and returns 200.
     *
     * @return void
     */
    public function testDestroyDeletesDraft(): void
    {
        $this->withObjectService(
            $this->fakeObjectService(
                [
                    'VATReturn' => [['id' => 'ret-del', 'statusCode' => 'draft']],
                ]
            )
        );

        $response = $this->controller->destroy(returnId: 'ret-del');
        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testDestroyDeletesDraft()

    /**
     * destroy() rejects non-draft returns with 409.
     *
     * @return void
     */
    public function testDestroyRejectsNonDraft(): void
    {
        $this->withObjectService(
            $this->fakeObjectService(
                [
                    'VATReturn' => [['id' => 'ret-submitted', 'statusCode' => 'submitted']],
                ]
            )
        );

        $response = $this->controller->destroy(returnId: 'ret-submitted');
        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testDestroyRejectsNonDraft()
}//end class
