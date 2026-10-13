<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\DataAbstractionLayer\OrderTransaction;

use Kommandhub\PaystackSW\DataAbstractionLayer\OrderTransaction\AbstractEntityReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

#[CoversClass(AbstractEntityReader::class)]
class AbstractEntityReaderTest extends TestCase
{
    private EntityRepository&MockObject $repository;
    private AbstractEntityReader $reader;
    private Context $context;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->context = Context::createDefaultContext();

        // Using an anonymous class to test the abstract class
        $this->reader = new class($this->repository) extends AbstractEntityReader {
            public function getRepository(): EntityRepository
            {
                return $this->repository;
            }
        };
    }

    public function testGetRepository(): void
    {
        $this->assertSame($this->repository, $this->reader->getRepository());
    }

    public function testReadAll(): void
    {
        $criteria = new Criteria();
        $collection = new EntityCollection();

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        $this->repository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($result);

        $returnedCollection = $this->reader->readAll($this->context, $criteria);
        $this->assertSame($collection, $returnedCollection);
    }

    public function testReadAllWithoutCriteria(): void
    {
        $collection = new EntityCollection();

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        $this->repository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($result);

        $returnedCollection = $this->reader->readAll($this->context);
        $this->assertSame($collection, $returnedCollection);
    }

    public function testCount(): void
    {
        $criteria = new Criteria();

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getTotal')->willReturn(42);

        $this->repository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $c) {
                return $c->getLimit() === 1 && $c->getTotalCountMode() === Criteria::TOTAL_COUNT_MODE_EXACT;
            }), $this->context)
            ->willReturn($result);

        $count = $this->reader->count($this->context, $criteria);
        $this->assertSame(42, $count);
    }

    public function testReadOneById(): void
    {
        $id = 'test-id';
        $entity = new Entity();
        $entity->setUniqueIdentifier($id);

        // EntitySearchResult::first() is deprecated (removed in Shopware 6.8);
        // the reader goes through getEntities()->first() instead.
        $entities = $this->createMock(EntityCollection::class);
        $entities->method('first')->willReturn($entity);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($entities);

        $this->repository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $c) use ($id) {
                return $c->getIds() === [$id];
            }), $this->context)
            ->willReturn($result);

        $returnedEntity = $this->reader->readOneById($id, $this->context);
        $this->assertSame($entity, $returnedEntity);
    }

    public function testCriteriaClone(): void
    {
        $reflection = new \ReflectionClass($this->reader);
        $method = $reflection->getMethod('criteria');
        $method->setAccessible(true);

        $criteria = new Criteria();
        $criteria->addAssociation('test');

        /** @var Criteria $cloned */
        $cloned = $method->invoke($this->reader, $criteria);

        $this->assertNotSame($criteria, $cloned);
        $this->assertTrue($cloned->hasAssociation('test'));
    }

    public function testWithIds(): void
    {
        $reflection = new \ReflectionClass($this->reader);
        $method = $reflection->getMethod('withIds');
        $method->setAccessible(true);

        $ids = ['id1'];
        /** @var Criteria $criteria */
        $criteria = $method->invoke($this->reader, $ids);

        $this->assertSame($ids, $criteria->getIds());
    }

    public function testWithAssociations(): void
    {
        // Accessing protected method via reflection
        $reflection = new \ReflectionClass($this->reader);
        $method = $reflection->getMethod('withAssociations');
        $method->setAccessible(true);

        $associations = ['assoc1', 'assoc2'];
        /** @var Criteria $criteria */
        $criteria = $method->invoke($this->reader, $associations);

        $this->assertArrayHasKey('assoc1', $criteria->getAssociations());
        $this->assertArrayHasKey('assoc2', $criteria->getAssociations());
    }

    public function testReadByIds(): void
    {
        $ids = ['id1', 'id2'];
        $collection = new EntityCollection();

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        $this->repository->expects($this->once())
            ->method('search')
            ->with($this->callback(function (Criteria $c) use ($ids) {
                return $c->getIds() === $ids;
            }), $this->context)
            ->willReturn($result);

        $returnedCollection = $this->reader->readByIds($ids, $this->context);
        $this->assertSame($collection, $returnedCollection);
    }

    public function testReadByIdsEmpty(): void
    {
        $this->repository->expects($this->never())->method('search');

        $returnedCollection = $this->reader->readByIds([], $this->context);
        $this->assertInstanceOf(EntityCollection::class, $returnedCollection);
        $this->assertCount(0, $returnedCollection);
    }

    public function testReadChunks(): void
    {
        $chunkSize = 1;

        $entity1 = new Entity();
        $entity1->setUniqueIdentifier('id1');

        $collection1 = new EntityCollection([$entity1]);

        $result1 = $this->createMock(EntitySearchResult::class);
        $result1->method('getEntities')->willReturn($collection1);
        $result1->method('getIds')->willReturn(['id1']);

        $resultEmpty = $this->createMock(EntitySearchResult::class);
        $resultEmpty->method('getEntities')->willReturn(new EntityCollection());
        $resultEmpty->method('getIds')->willReturn([]);

        $definition = new class() extends \Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition {
            public function getEntityName(): string
            {
                return 'test';
            }
            public function getEntityClass(): string
            {
                return Entity::class;
            }
            protected function defineFields(): \Shopware\Core\Framework\DataAbstractionLayer\FieldCollection
            {
                return new \Shopware\Core\Framework\DataAbstractionLayer\FieldCollection();
            }
        };
        $registry = $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class);
        $definition->compile($registry);

        $this->repository->method('getDefinition')->willReturn($definition);

        $this->repository->expects($this->exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls($result1, $resultEmpty);

        $processedEntities = [];
        $this->reader->readChunks(
            $this->context,
            function (EntityCollection $entities) use (&$processedEntities) {
                foreach ($entities as $entity) {
                    $processedEntities[] = $entity;
                }
            },
            null,
            $chunkSize
        );

        $this->assertCount(1, $processedEntities);
        $this->assertSame($entity1, $processedEntities[0]);
    }
}
