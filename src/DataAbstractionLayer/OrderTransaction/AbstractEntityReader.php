<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\DataAbstractionLayer\OrderTransaction;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Base abstraction for Shopware DAL entity operations.
 *
 * Design principles:
 * - Stateless service
 * - Immutable Criteria handling
 * - Safe for Symfony shared services
 * - Production-ready for async workers and message consumers
 * - Extensible for custom entity readers
 *
 * This abstraction provides:
 * - Single entity reads
 * - Bulk entity reads
 * - Chunked processing
 *
 * IMPORTANT:
 * Criteria objects are always cloned internally to avoid accidental
 * mutation side effects across service boundaries.
 *
 * @template T of Entity
 * @template TCollection of EntityCollection
 */
abstract class AbstractEntityReader
{
    /**
     * Default chunk size used for iterator-based processing.
     */
    protected const DEFAULT_CHUNK_SIZE = 500;

    /**
     * @param EntityRepository<TCollection> $repository
     */
    public function __construct(
        protected EntityRepository $repository
    ) {
    }

    /**
     * @return EntityRepository<TCollection>
     */
    abstract protected function getRepository(): EntityRepository;

    /**
     * Reads all entities matching the provided criteria.
     *
     * @param Context $context Shopware execution context
     * @param Criteria|null $criteria Optional search criteria
     *
     * @return TCollection Matching entities
     */
    public function readAll(
        Context $context,
        ?Criteria $criteria = null
    ): EntityCollection {
        /** @var TCollection $entities */
        $entities = $this->repository
            ->search(
                $this->criteria($criteria),
                $context
            )
            ->getEntities();

        return $entities;
    }

    /**
     * Counts entities matching the provided criteria.
     *
     * @param Context $context Shopware execution context
     * @param Criteria|null $criteria Optional search criteria
     *
     * @return int Total count of matching entities
     */
    public function count(
        Context $context,
        ?Criteria $criteria = null
    ): int {
        $criteria = $this->criteria($criteria);
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->repository
            ->search($criteria, $context)
            ->getTotal();
    }

    /**
     * Reads entities in chunks using RepositoryIterator.
     *
     * This method is intended for memory-efficient processing
     * of large datasets.
     *
     * Example:
     *
     * <code>
     * $reader->readChunks(
     *     $context,
     *     function (EntityCollection $entities): void {
     *         foreach ($entities as $entity) {
     *             // process entity
     *         }
     *     }
     * );
     * </code>
     *
     * @param Context $context Shopware execution context
     * @param callable(TCollection):void $callback Chunk processor
     * @param Criteria|null $criteria Optional search criteria
     * @param int $chunkSize Iterator batch size
     *
     * @return void
     */
    public function readChunks(
        Context $context,
        callable $callback,
        ?Criteria $criteria = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): void {
        $criteria = $this->criteria($criteria);

        if ($criteria->getLimit() === null) {
            $criteria->setLimit($chunkSize);
        }

        $iterator = new RepositoryIterator(
            $this->repository,
            $context,
            $criteria
        );

        while (($result = $iterator->fetch()) !== null) {
            $callback($result->getEntities());
        }
    }

    /**
     * Reads a single entity by identifier.
     *
     * @param string $id Entity identifier
     * @param Context $context Shopware execution context
     * @param Criteria|null $criteria Optional search criteria
     *
     * @return T|null Matching entity or null
     */
    public function readOneById(
        string $id,
        Context $context,
        ?Criteria $criteria = null
    ): ?Entity {
        /** @var T|null $entity */
        $entity = $this->repository
            ->search(
                $this->withIds([$id], $criteria),
                $context
            )
            ->getEntities()
            ->first();

        return $entity;
    }

    /**
     * Reads multiple entities by identifiers.
     *
     * @param array<string> $ids Entity identifiers
     * @param Context $context Shopware execution context
     * @param Criteria|null $criteria Optional search criteria
     *
     * @return TCollection Matching entities
     */
    public function readByIds(
        array $ids,
        Context $context,
        ?Criteria $criteria = null
    ): EntityCollection {
        if ($ids === []) {
            /** @var TCollection $collection */
            $collection = new EntityCollection();

            return $collection;
        }

        /** @var TCollection $entities */
        $entities = $this->repository
            ->search(
                $this->withIds($ids, $criteria),
                $context
            )
            ->getEntities();

        return $entities;
    }

    /**
     * Creates a safe Criteria instance.
     *
     * Criteria objects are cloned internally to prevent
     * accidental external mutation side effects.
     *
     * @param Criteria|null $criteria Optional base criteria
     *
     * @return Criteria Safe Criteria instance
     */
    protected function criteria(?Criteria $criteria = null): Criteria
    {
        return $criteria !== null
            ? clone $criteria
            : new Criteria();
    }

    /**
     * Creates Criteria with entity identifiers applied.
     *
     * Existing IDs are intentionally replaced to ensure
     * predictable query behavior.
     *
     * @param array<string> $ids Entity identifiers
     * @param Criteria|null $criteria Optional base criteria
     *
     * @return Criteria Criteria with identifiers applied
     */
    protected function withIds(
        array $ids,
        ?Criteria $criteria = null
    ): Criteria {
        $criteria = $this->criteria($criteria);

        if ($ids !== []) {
            $criteria->setIds($ids);
        }

        return $criteria;
    }

    /**
     * Applies associations to Criteria safely.
     *
     * Useful for reusable entity graph loading.
     *
     * Example:
     *
     * <code>
     * $criteria = $this->withAssociations([
     *     'media',
     *     'translations',
     *     'manufacturer'
     * ]);
     * </code>
     *
     * @param array<string> $associations DAL associations
     * @param Criteria|null $criteria Optional base criteria
     *
     * @return Criteria Criteria with associations
     */
    protected function withAssociations(
        array $associations,
        ?Criteria $criteria = null
    ): Criteria {
        $criteria = $this->criteria($criteria);

        foreach ($associations as $association) {
            $criteria->addAssociation($association);
        }

        return $criteria;
    }
}
