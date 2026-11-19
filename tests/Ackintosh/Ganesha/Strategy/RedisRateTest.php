<?php

namespace Ackintosh\Ganesha\Strategy;

use Ackintosh\Ganesha\RequestPermit;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisRateTest extends TestCase
{
    private Client $redis;
    private string $service;

    protected function setUp(): void
    {
        $this->redis = new Client('tcp://' . (getenv('GANESHA_EXAMPLE_REDIS') ?: 'localhost'));
        $this->service = 'test-redis-rate-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $prefix = $this->keyPrefix();
        $this->redis->del([$prefix . ':state', $prefix . ':completed', $prefix . ':failures', $prefix . ':probe']);
    }

    public function testTripsOnTheSuccessThatMeetsBothThresholdsAndIgnoresOldResults(): void
    {
        $breaker = new RedisRate($this->redis, 50, 4, 60, 1, 60);
        $oldRead = $this->acquire($breaker);
        $this->acquire($breaker)->fail();
        $this->acquire($breaker)->fail();
        $this->acquire($breaker)->succeed();
        $this->acquire($breaker)->succeed();
        self::assertNull($breaker->tryAcquire($this->service));

        usleep(1_100_000);

        $this->acquire($breaker)->succeed();
        $oldRead->fail();
        $this->acquire($breaker)->fail();
        $this->acquire($breaker)->succeed();
        $this->acquire($breaker)->succeed();
        $this->acquire($breaker)->succeed();
        // The new window contains only one failure out of four completions.
        self::assertInstanceOf(RequestPermit::class, $breaker->tryAcquire($this->service));
    }

    public function testOnlyTheCurrentProbeCanRecoverTheCircuit(): void
    {
        $breaker = new RedisRate($this->redis, 100, 1, 1, 1, 1);
        $anotherWorker = new RedisRate(
            new Client('tcp://' . (getenv('GANESHA_EXAMPLE_REDIS') ?: 'localhost')),
            100,
            1,
            1,
            1,
            1
        );
        $this->acquire($breaker)->fail();
        self::assertNull($anotherWorker->tryAcquire($this->service));

        usleep(1_100_000);

        $expiredProbe = $this->acquire($breaker);
        self::assertNull($anotherWorker->tryAcquire($this->service));

        usleep(1_100_000);

        $replacement = $this->acquire($anotherWorker);
        $expiredProbe->succeed();
        self::assertNull($breaker->tryAcquire($this->service));

        $replacement->fail();
        self::assertNull($breaker->tryAcquire($this->service));

        usleep(1_100_000);

        $this->acquire($breaker)->succeed();
        $this->acquire($anotherWorker)->fail();
        // A failure here opens immediately only if the previous success was a probe.
        self::assertNull($breaker->tryAcquire($this->service));
    }

    public function testAnOldRequestCannotChangeRecreatedState(): void
    {
        $breaker = new RedisRate($this->redis, 100, 1, 60, 60, 60);
        $oldRead = $this->acquire($breaker);
        self::assertSame(1, $this->redis->del([$this->keyPrefix() . ':state']));

        $newRead = $this->acquire($breaker);
        $oldRead->fail();
        self::assertInstanceOf(RequestPermit::class, $breaker->tryAcquire($this->service));

        $newRead->fail();
        self::assertNull($breaker->tryAcquire($this->service));
    }

    private function keyPrefix(): string
    {
        return 'ganesha:redis-rate:{' . hash('sha256', $this->service) . '}';
    }

    private function acquire(RedisRate $breaker): RequestPermit
    {
        $permit = $breaker->tryAcquire($this->service);
        self::assertInstanceOf(RequestPermit::class, $permit);

        return $permit;
    }
}
