<?php

namespace Ackintosh\Ganesha\Storage\Adapter;

use Ackintosh\Ganesha;
use Ackintosh\Ganesha\Exception\StorageException;
use PHPUnit\Framework\TestCase;
use function extension_loaded;

/**
 * @coversDefaultClass \Ackintosh\Ganesha\Storage\Adapter\Memcached
 */
class MemcachedTest extends TestCase
{
    /**
     * @var Memcached
     */
    private $memcachedAdaper;

    /**
     * @var \Memcached
     */
    private $memcached;

    /**
     * @var string
     */
    private $service = 'testService';

    protected function setUp(): void
    {
        if (!extension_loaded('memcached')) {
            self::markTestSkipped('No ext-memcached present');
        }

        $m = new \Memcached();
        $m->addServer(
            getenv('GANESHA_EXAMPLE_MEMCACHED') ? getenv('GANESHA_EXAMPLE_MEMCACHED') : 'localhost',
            11211
        );
        $m->delete($this->service);
        $this->memcached = $m;
        $this->memcachedAdaper = new Memcached($m);
        $this->memcachedAdaper->setContext($this->createContext($this->memcachedAdaper));
    }

    private function createContext(Memcached $memcachedAdaper): Ganesha\Context
    {
        return new Ganesha\Context(
            Ganesha\Strategy\Rate::class,
            $memcachedAdaper,
            new Ganesha\Configuration([
                Ganesha\Configuration::TIME_WINDOW => 1,
            ])
        );
    }

    /**
     * @test
     * @covers ::supportCountStrategy
     */
    public function supportsCountStrategy()
    {
        $this->assertTrue($this->memcachedAdaper->supportCountStrategy());
    }

    /**
     * @test
     * @covers ::supportRateStrategy
     */
    public function supportsRateStrategy()
    {
        $this->assertTrue($this->memcachedAdaper->supportRateStrategy());
    }

    /**
     * @test
     * @covers ::load
     */
    public function saveAndLoad()
    {
        $this->memcachedAdaper->save($this->service, 1);
        $this->assertSame(1, $this->memcachedAdaper->load($this->service));
    }

    /**
     * @test
     * @covers ::load
     */
    public function loadThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getResultCode'])
            ->getMock();
        $m->expects($this->once())
            ->method('getResultCode')
            ->willReturn(\Memcached::RES_FAILURE);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->load($this->service);
    }

    /**
     * @test
     * @covers ::save
     */
    public function saveThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['set'])
            ->getMock();
        $m->expects($this->once())
            ->method('set')
            ->willReturn(false);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->save($this->service, 99);
    }

    /**
     * @test
     * @covers ::load
     * @covers ::increment
     */
    public function increment()
    {
        $this->memcachedAdaper->increment($this->service);
        $this->assertSame(1, $this->memcachedAdaper->load($this->service));
        $this->memcachedAdaper->increment($this->service);
        $this->assertSame(2, $this->memcachedAdaper->load($this->service));
    }

    /**
     * @test
     * @covers ::increment
     */
    public function incrementThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['increment'])
            ->getMock();
        $m->expects($this->once())
            ->method('increment')
            ->willReturn(false);

        $adapter = new Memcached($m);
        $adapter->setContext($this->createContext($adapter));

        $this->expectException(StorageException::class);
        $adapter->increment($this->service);
    }

    /**
     * @test
     * @covers ::increment
     * @covers ::decrement
     * @covers ::load
     */
    public function decrement()
    {
        $this->memcachedAdaper->decrement($this->service);
        $this->assertSame(0, $this->memcachedAdaper->load($this->service));

        $this->memcachedAdaper->increment($this->service);
        $this->memcachedAdaper->increment($this->service);
        $this->memcachedAdaper->increment($this->service);
        $this->memcachedAdaper->decrement($this->service);
        $this->assertSame(2, $this->memcachedAdaper->load($this->service));
    }

    /**
     * @test
     * @covers ::decrement
     */
    public function decrementThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['decrement'])
            ->getMock();
        $m->expects($this->once())
            ->method('decrement')
            ->willReturn(false);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->decrement($this->service);
    }

    /**
     * @test
     * @covers ::saveLastFailureTime
     * @covers ::loadLastFailureTime
     */
    public function saveAndLoadLastFailureTime()
    {
        $time = time();
        $this->memcachedAdaper->saveLastFailureTime($this->service, $time);
        $this->assertSame($time, $this->memcachedAdaper->loadLastFailureTime($this->service));
    }

    /**
     * @test
     * @covers ::saveLastFailureTime
     */
    public function saveLastFailureTimeThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['set'])
            ->getMock();
        $m->expects($this->once())
            ->method('set')
            ->willReturn(false);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->saveLastFailureTime($this->service, time());
    }

    /**
     * @test
     * @covers ::loadLastFailureTime
     */
    public function loadLastFailureTimeThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getResultCode'])
            ->getMock();
        $m->expects($this->once())
            ->method('getResultCode')
            ->willReturn(\Memcached::RES_FAILURE);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->loadLastFailureTime($this->service);
    }

    /**
     * @test
     * @covers ::saveStatus
     * @covers ::loadStatus
     */
    public function saveAndLoadStatus()
    {
        $status = Ganesha::STATUS_TRIPPED;
        $this->memcachedAdaper->saveStatus($this->service, $status);
        $this->assertSame($status, $this->memcachedAdaper->loadStatus($this->service));
    }

    /**
     * @test
     * @covers ::saveStatus
     */
    public function saveStatusThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['set'])
            ->getMock();
        $m->expects($this->once())
            ->method('set')
            ->willReturn(false);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->saveStatus($this->service, Ganesha::STATUS_TRIPPED);
    }

    /**
     * @test
     * @covers ::loadStatus
     */
    public function loadStatusThrowsException()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getResultCode'])
            ->getMock();
        $m->expects($this->once())
            ->method('getResultCode')
            ->willReturn(\Memcached::RES_FAILURE);

        $adapter = new Memcached($m);

        $this->expectException(StorageException::class);
        $adapter->loadStatus($this->service);
    }

    /**
     * @test
     * @requires PHP 7.0
     * @covers ::reset
     */
    public function resetWillDoNothingIfNoDataExists()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getStats', 'getAllKeys', 'getResultCode'])
            ->getMock();
        $m->expects($this->once())
            ->method('getStats')
            ->willReturn(['localhost:11211' => ['pid' => 1]]);

        $m->expects($this->once())
            ->method('getAllKeys')
            ->willReturn(false);

        $m->expects($this->once())
            ->method('getResultCode')
            ->willReturn(\Memcached::RES_SUCCESS);

        $adapter = new Memcached($m);
        $adapter->reset();
    }

    /**
     * @test
     * @requires PHP 7.0
     * @covers ::reset
     */
    public function resetThrowsExceptionWhenFailedToGetStats()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getStats'])
            ->getMock();
        $m->expects($this->once())
            ->method('getStats')
            ->willReturn(false);

        $adapter = new Memcached($m);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Couldn\'t connect to memcached.');
        $adapter->reset();
    }

    /**
     * @test
     * @requires PHP 7.0
     * @covers ::reset
     */
    public function resetThrowsExceptionWhenFailedToGetAllKeys()
    {
        $m = $this->getMockBuilder(\Memcached::class)
            ->onlyMethods(['getStats', 'getAllKeys', 'getResultCode'])
            ->getMock();
        $m->expects($this->once())
            ->method('getStats')
            ->willReturn(['localhost:11211' => ['pid' => 1]]);

        $m->expects($this->once())
            ->method('getAllKeys')
            ->willReturn(false);

        $m->expects($this->once())
            ->method('getResultCode')
            ->willReturn(\Memcached::RES_FAILURE);

        $adapter = new Memcached($m);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^failed to get memcached keys/');
        $adapter->reset();
    }

    /**
     * @test
     * @dataProvider isGaneshaDataProvider
     * @covers ::isGaneshaData
     */
    public function isGaneshaData($key, $expected)
    {
        $this->assertSame($expected, $this->memcachedAdaper->isGaneshaData($key));
    }

    public function isGaneshaDataProvider()
    {
        return [
            ['ganesha_test_success', true],
            ['ganesha_test_failure', true],
            ['ganesha_test_rejection', true],
            ['ganesha_test_last_failure_time', true],
            ['ganesha_test_status', true],
            ['ganesha_ganesha_success', true],
            ['ganesha_success_success', true],
            ['ganesha_http://example.com_success', true],
            ['ganeshaa_test_success', false],
            ['ganesha_test_successs', false],
            ['ganesha_test_failuree', false],
            ['ganesha_test_rejectionn', false],
            ['ganesha_test_last_failure_timee', false],
            ['ganesha_test_statuss', false],
        ];
    }

    /**
     * @test
     */
    public function outdatedCountsShouldBeEvictedInCaseOfRateStrategy(): void
    {
        $ganesha = Ganesha\Builder::withRateStrategy()
            ->adapter($this->memcachedAdaper)
            ->timeWindow(1)
            ->failureRateThreshold(50)
            ->minimumRequests(6) // Do not trip the circuit if the five requests land in different buckets.
            ->intervalToHalfOpen(10)
            ->build();

        $serviceName = 'outdatedCountsShouldBeEvicted-' . bin2hex(random_bytes(8));
        $firstBucket = time();
        $ganesha->success($serviceName);
        $ganesha->success($serviceName);
        $ganesha->success($serviceName);
        $ganesha->failure($serviceName);
        $ganesha->failure($serviceName);
        $lastBucket = time();

        // Requests can span several one-second buckets. Check those same keys before and after expiry.
        $storageKeys = new Ganesha\Storage\StorageKeys();
        $prefix = $storageKeys->prefix() . $serviceName;
        $successKeys = [];
        $failureKeys = [];
        for ($bucket = $firstBucket; $bucket <= $lastBucket; ++$bucket) {
            $successKeys[] = $prefix . '.' . $bucket . $storageKeys->success();
            $failureKeys[] = $prefix . '.' . $bucket . $storageKeys->failure();
        }

        $successCounts = $this->memcached->getMulti($successKeys);
        $failureCounts = $this->memcached->getMulti($failureKeys);
        self::assertIsArray($successCounts);
        self::assertIsArray($failureCounts);
        self::assertSame(3, array_sum($successCounts));
        self::assertSame(2, array_sum($failureCounts));

        sleep(15); // The adapter sets a 10-second TTL for a one-second window.

        foreach (array_merge($successKeys, $failureKeys) as $key) {
            self::assertFalse($this->memcached->get($key));
            self::assertSame(\Memcached::RES_NOTFOUND, $this->memcached->getResultCode());
        }

        // Other items that should be alive
        $statusKey = $prefix . $storageKeys->status();
        self::assertSame(Ganesha::STATUS_CALMED_DOWN, (int)$this->memcached->get($statusKey));

        $lastFailureTimeKey = $prefix . $storageKeys->lastFailureTime();
        self::assertTrue(1 < (int)$this->memcached->get($lastFailureTimeKey));
    }
}
