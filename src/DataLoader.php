<?php


namespace leinonen\DataLoader;


use React\EventLoop\LoopInterface;
use React\Promise\Promise;

class DataLoader
{
    /**
     * @var callable
     */
    private $batchLoadFunction;

    /**
     * @var DataLoaderOptions
     */
    private $options;

    /**
     * @var array
     */
    private $promiseQueue = [];

    /**
     * @var CacheMapInterface
     */
    private $promiseCache;

    /**
     * Initiates a new DataLoader.
     *
     * @param callable $batchLoadFunction The function which will be called for the batch loading.
     * @param LoopInterface $loop
     * @param null|DataLoaderOptions $options
     * @param null|CacheMapInterface $cacheMap
     */
    public function __construct(
        callable $batchLoadFunction,
        LoopInterface $loop,
        DataLoaderOptions $options = null,
        CacheMapInterface $cacheMap = null
    ) {
        $this->batchLoadFunction = $batchLoadFunction;
        $this->eventLoop = $loop;
        $this->options = empty($options) ? new DataLoaderOptions() : $options;
        $this->promiseCache = empty($cacheMap) ? new CacheMap() : $cacheMap;
    }

    /**
     * Returns a Promise for the value represented by the given key.
     *
     * @param int|string $key
     *
     * @return Promise
     */
    public function load($key)
    {
        $cacheKey = $key;

        if ($this->promiseCache->get($cacheKey)) {
            return $this->promiseCache->get($cacheKey);
        }

        $promise = new Promise(
            function (callable $resolve, callable $reject) use ($key) {

                $this->promiseQueue[] = [
                    'key' => $key,
                    'resolve' => $resolve,
                    'reject' => $reject,
                ];

                if (count($this->promiseQueue) === 1) {
                    $this->eventLoop->nextTick(
                        function () {
                            $this->dispatchQueue();
                        }
                    );
                }
            }
        );

        $this->promiseCache->set($cacheKey, $promise);

        return $promise;
    }

    /**
     * Loads multiple keys, promising an array of values.
     *
     * This is equivalent to the more verbose:
     *
     *  \React\Promise\all([
     *      $dataLoader->load('a');
     *      $dataLoader->load('b');
     *  });
     *
     * @param array $keys
     *
     * @return Promise
     */
    public function loadMany(array $keys)
    {
        return \React\Promise\all(
            array_map(
                function ($key) {
                    return $this->load($key);
                },
                $keys
            )
        );
    }

    /**
     * Clears the value for the given key from the cache if it exists. Returns itself for method chaining.
     *
     * @param int|string $key
     *
     * @return $this
     */
    public function clear($key)
    {
        $cacheKey = $key;

        $this->promiseCache->delete($cacheKey);

        return $this;
    }

    /**
     * Clears the entire cache. Returns itself for method chaining.
     *
     * @return $this
     */
    public function clearAll()
    {
        $this->promiseCache->clear();

        return $this;
    }

    /**
     * Adds the given key and value to the cache. If the key already exists no change is made.
     * Returns itself for method chaining.
     *
     * @param int|string $key
     * @param int|string $value
     *
     * @return $this
     */
    public function prime($key, $value)
    {
        $cacheKey = $key;

        if (! $this->promiseCache->get($cacheKey)) {
            // Cache a rejected promise if the value is an Exception, in order to match
            // the behavior of load($key).
            $promise = $value instanceof \Exception ? \React\Promise\reject($value) : \React\Promise\resolve($value);

            $this->promiseCache->set($cacheKey, $promise);
        }

        return $this;
    }

    /**
     * Resets and dispatches the DataLoaders queue.
     */
    private function dispatchQueue()
    {
        $queue = $this->promiseQueue;
        $this->promiseQueue = [];

        $maxBatchSize = $this->options->getMaxBatchSize();

        if ($maxBatchSize && $maxBatchSize > 0 && $maxBatchSize < count($queue)) {
            $this->dispatchQueueInMultipleBatches($queue, $maxBatchSize);
        } else {
            $this->dispatchQueueBatch($queue);
        }
    }

    /**
     * Dispatches a batch of a queue. The given batch can also be the whole queue.
     *
     * @param $batch
     */
    private function dispatchQueueBatch($batch)
    {
        $keys = array_column($batch, 'key');

        $batchLoadFunction = $this->batchLoadFunction;
        /** @var Promise $batchPromise */
        $batchPromise = $batchLoadFunction($keys);

        $batchPromise->then(
            function ($values) use ($batch) {
                // Handle the batch by resolving the promises and rejecting ones that return Exceptions.
                foreach ($batch as $index => $queueItem) {
                    $value = $values[$index];
                    if ($value instanceof \Exception) {
                        $queueItem['reject']($value);
                    } else {
                        $queueItem['resolve']($value);
                    }
                }
            },
            function ($error) use ($batch) {
                $this->handleFailedDispatch($batch, $error);
            }
        );

    }

    /**
     * Dispatches the given queue in multiple batches.
     *
     * @param $queue
     * @param int $maxBatchSize
     */
    private function dispatchQueueInMultipleBatches($queue, $maxBatchSize)
    {
        $numberOfBatchesToDispatch = count($queue) / $maxBatchSize;

        for ($i = 0; $i < $numberOfBatchesToDispatch; $i++) {

            $this->dispatchQueueBatch(
                array_slice($queue, $i * $maxBatchSize, $maxBatchSize)
            );

        }
    }

    /**
     * Handles the failed batch dispatch.
     *
     * @param $batch
     * @param \Exception $error
     */
    private function handleFailedDispatch($batch, \Exception $error)
    {
        foreach ($batch as $index => $queueItem) {
            $this->clear($queueItem['key']);
            $queueItem['reject']($error);
        }
    }
}
