<?php
/**
 * This file is part of the prooph/redis-event-store.
 * (c) 2017 prooph software GmbH <contact@prooph.de>
 * (c) 2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\Redis;

use Iterator;
use Prooph\Common\Messaging\MessageFactory;
use Prooph\EventStore\Exception\StreamExistsAlready;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\Exception\TransactionAlreadyStarted;
use Prooph\EventStore\Exception\TransactionNotStarted;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Projection\Projection;
use Prooph\EventStore\Projection\ProjectionFactory;
use Prooph\EventStore\Projection\ProjectionOptions;
use Prooph\EventStore\Projection\Query;
use Prooph\EventStore\Projection\QueryFactory;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\Projection\ReadModelProjection;
use Prooph\EventStore\Projection\ReadModelProjectionFactory;
use Prooph\EventStore\Redis\Exception\RuntimeException;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Prooph\EventStore\TransactionalEventStore;
use Redis;

final class RedisEventStore implements TransactionalEventStore
{
    private const HASH_FIELD_REAL_STREAM_NAME = 'realStreamName';
    private const HASH_FIELD_METADATA = 'metadata';

    private $redisClient;
    private $persistenceStrategy;
    private $messageFactory;
    private $inTransaction;

    public function __construct(
        Redis $redisClient,
        PersistenceStrategy $persistenceStrategy,
        MessageFactory $messageFactory
    ) {
        $this->redisClient = $redisClient;
        $this->persistenceStrategy = $persistenceStrategy;
        $this->messageFactory = $messageFactory;

        $this->inTransaction = false;
    }

    public function fetchStreamMetadata(StreamName $streamName): array
    {
        if (! $this->hasStream($streamName)) {
            throw StreamNotFound::with($streamName);
        }

        $hashKey = $this->persistenceStrategy->getEventStreamHashKey($streamName);
        $metadata = $this->redisClient->hGet($hashKey, self::HASH_FIELD_METADATA);

        return json_decode($metadata, true);
    }

    public function updateStreamMetadata(StreamName $streamName, array $newMetadata): void
    {
        if (! $this->hasStream($streamName)) {
            throw StreamNotFound::with($streamName);
        }

        $this->persistEventStreamMetadata($streamName, $newMetadata);
    }

    public function hasStream(StreamName $streamName): bool
    {
        $hashKey = $this->persistenceStrategy->getEventStreamHashKey($streamName);
        $this->watchKey($hashKey);

        return $this->redisClient->hExists($hashKey, self::HASH_FIELD_REAL_STREAM_NAME);
    }

    public function create(Stream $stream): void
    {
        if ($this->hasStream($stream->streamName())) {
            throw StreamExistsAlready::with($stream->streamName());
        }

        $this->persistEventStreamMetadata($stream->streamName(), $stream->metadata());
        $this->appendTo($stream->streamName(), $stream->streamEvents());
    }

    public function appendTo(StreamName $streamName, Iterator $streamEvents): void
    {
        if (! $this->hasStream($streamName)) {
            throw StreamNotFound::with($streamName);
        }

        $streamNameKey = $this->persistenceStrategy->getEventStreamHashKey($streamName); // fixme

        // todo: use persistence strategy
        foreach ($streamEvents as $event) {
            $eventId = $event->uuid()->toString();
            $aggregateVersion = $event->metadata()['_aggregate_version'];

            $storageKey = 'event_data:' . $streamNameKey . ':' . $eventId;

            // todo: throw exception if version for aggregate is already set (persistence strategy)
            $this->redisClient->zAdd('event_version:' . $streamNameKey, $aggregateVersion, $storageKey);

            // todo: maybe we using a hash here?
            $this->redisClient->set($storageKey, json_encode([
                'event_id' => $event->uuid()->toString(),
                'event_name' => $event->messageName(),
                'payload' => json_encode($event->payload()),
                'metadata' => json_encode($event->metadata()),
                'created_at' => $event->createdAt()->format('Y-m-d\TH:i:s.u'),
            ]));

            // todo: maybe for later usage with metadata matcher
            //$this->redisClient->hMset('event_metadata:'.$streamNameKey.':'.$eventId, $event->metadata());
        }
    }

    public function load(
        StreamName $streamName,
        int $fromNumber = 1,
        int $count = null,
        MetadataMatcher $metadataMatcher = null
    ): Iterator {
        $streamNameKey = $this->persistenceStrategy->getEventStreamHashKey($streamName); // fixme
        $result = new \ArrayIterator();

        $fromNumber--;
        $toNumber = $count ? $fromNumber + $count : -1;

        // todo: is $fromNumber = 1 the aggregate version = 1 or the first event?
        $eventDataKeys = $this->redisClient->zRange('event_version:' . $streamNameKey, $fromNumber, $toNumber);

        if (! $eventDataKeys) {
            return $result;
        }

        foreach ($this->redisClient->mget($eventDataKeys) as $eventKey => $eventData) {
            if (false === $eventData) {
                // todo: data for key was not found. Throw an exception?
                throw new RuntimeException();
                continue;
            }

            $eventData = json_decode($eventData, true);

            // todo: maybe yielding values is better here
            $result->append($this->messageFactory->createMessageFromArray($eventData['event_name'], [
                'uuid' => $eventData['event_id'],
                'created_at' => \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $eventData['created_at']),
                'payload' => json_decode($eventData['payload'], true),
                'metadata' => json_decode($eventData['metadata'], true),
            ]));
        }

        // todo: implement metadata matcher

        return $result;
    }

    public function loadReverse(
        StreamName $streamName,
        int $fromNumber = PHP_INT_MAX,
        int $count = null,
        MetadataMatcher $metadataMatcher = null
    ): Iterator {
        $streamNameKey = $this->persistenceStrategy->getEventStreamHashKey($streamName); // fixme
        $result = new \ArrayIterator();

        $fromNumber = -1 * $fromNumber;
        $toNumber = $count ? $fromNumber - $count : 0;

        // todo: is $fromNumber = 1 the aggregate version = 1 or the first event?
        $eventDataKeys = $this->redisClient->zRevRange('event_version:' . $streamNameKey, $fromNumber, (int) $toNumber);

        if (! $eventDataKeys) {
            return $result;
        }

        foreach ($this->redisClient->mget($eventDataKeys) as $eventKey => $eventData) {
            if (false === $eventData) {
                // todo: data for key was not found. Throw an exception?
                throw new RuntimeException();
                continue;
            }

            $eventData = json_decode($eventData, true);

            // todo: maybe yielding values is better here
            $result->append($this->messageFactory->createMessageFromArray($eventData['event_name'], [
                'uuid' => $eventData['event_id'],
                'created_at' => \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $eventData['created_at']),
                'payload' => json_decode($eventData['payload'], true),
                'metadata' => json_decode($eventData['metadata'], true),
            ]));
        }

        // todo: implement metadata matcher

        return $result;
    }

    public function delete(StreamName $streamName): void
    {
        throw new RuntimeException('not implemented yet');
    }

    public function createQuery(QueryFactory $factory = null): Query
    {
        throw new RuntimeException('not implemented yet');
    }

    public function createProjection(
        string $name,
        ProjectionOptions $options = null,
        ProjectionFactory $factory = null
    ): Projection {
        throw new RuntimeException('not implemented yet');
    }

    public function createReadModelProjection(
        string $name,
        ReadModel $readModel,
        ProjectionOptions $options = null,
        ReadModelProjectionFactory $factory = null
    ): ReadModelProjection {
        throw new RuntimeException('not implemented yet');
    }

    public function getDefaultQueryFactory(): QueryFactory
    {
        throw new RuntimeException('not implemented yet');
    }

    public function getDefaultProjectionFactory(): ProjectionFactory
    {
        throw new RuntimeException('not implemented yet');
    }

    public function getDefaultReadModelProjectionFactory(): ReadModelProjectionFactory
    {
        throw new RuntimeException('not implemented yet');
    }

    public function deleteProjection(string $name, bool $deleteEmittedEvents): void
    {
        throw new RuntimeException('not implemented yet');
    }

    public function resetProjection(string $name): void
    {
        throw new RuntimeException('not implemented yet');
    }

    public function stopProjection(string $name): void
    {
        throw new RuntimeException('not implemented yet');
    }

    public function beginTransaction(): void
    {
        if (true === $this->inTransaction) {
            throw new TransactionAlreadyStarted();
        }

        $this->inTransaction = true;
        $this->redisClient = $this->redisClient->multi(Redis::MULTI);
    }

    public function commit(): void
    {
        if (false === $this->inTransaction) {
            throw new TransactionNotStarted();
        }

        $this->redisClient->exec();
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        if (false === $this->inTransaction) {
            throw new TransactionNotStarted();
        }

        $this->redisClient->discard();
        $this->inTransaction = false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function transactional(callable $callable)
    {
        $this->beginTransaction();

        try {
            $result = $callable($this);
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }

        return $result ?: true;
    }

    private function watchKey(string $key)
    {
        if ($this->inTransaction()) {
            $this->redisClient->watch($key);
        }
    }

    private function persistEventStreamMetadata(StreamName $streamName, array $metadata): void
    {
        $hashKey = $this->persistenceStrategy->getEventStreamHashKey($streamName);

        $result = $this->redisClient->hMset($hashKey, [
            self::HASH_FIELD_REAL_STREAM_NAME => $streamName->toString(),
            self::HASH_FIELD_METADATA => json_encode($metadata), // todo: is it already encoded?
        ]);

        if (! $result) {
            throw new RuntimeException(); // todo: provide exception message
        }
    }
}
