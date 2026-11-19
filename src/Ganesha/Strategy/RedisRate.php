<?php

namespace Ackintosh\Ganesha\Strategy;

use Ackintosh\Ganesha\RequestPermit;
use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Opt-in Redis rate breaker. Each outcome belongs to the request that was admitted.
 */
final class RedisRate
{
    private const ACQUIRE = <<<'LUA'
        local generation = redis.call('HGET', KEYS[1], 'generation')
        if not generation then
            redis.call('DEL', KEYS[2], KEYS[3], KEYS[4])
            redis.call('HSET', KEYS[1], 'generation', ARGV[1])
            return {ARGV[1], 0}
        end

        local openedAt = redis.call('HGET', KEYS[1], 'opened_at')
        if not openedAt then
            return {generation, 0}
        end

        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
        if tonumber(openedAt) + tonumber(ARGV[2]) > now then
            return {}
        end

        if not redis.call('SET', KEYS[4], ARGV[1], 'NX', 'PX', ARGV[3]) then
            return {}
        end

        return {generation, 1}
        LUA;

    private const COMPLETE = <<<'LUA'
        if redis.call('HGET', KEYS[1], 'generation') ~= ARGV[2] then
            return 0
        end

        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
        local openedAt = redis.call('HGET', KEYS[1], 'opened_at')
        local failed = ARGV[4] == '1'

        if ARGV[3] == '1' then
            if not openedAt or redis.call('GET', KEYS[4]) ~= ARGV[1] then
                return 0
            end

            if failed then
                redis.call('HSET', KEYS[1], 'opened_at', now)
            else
                redis.call('HSET', KEYS[1], 'generation', ARGV[1])
                redis.call('HDEL', KEYS[1], 'opened_at')
            end

            redis.call('DEL', KEYS[2], KEYS[3], KEYS[4])
            return 1
        end

        if openedAt then
            return 0
        end

        local window = tonumber(ARGV[5])
        redis.call('ZADD', KEYS[2], now, ARGV[1])
        if failed then
            redis.call('ZADD', KEYS[3], now, ARGV[1])
        end

        redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', now - window)
        redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', now - window)
        local completed = redis.call('ZCARD', KEYS[2])
        local failures = redis.call('ZCARD', KEYS[3])
        if completed >= tonumber(ARGV[6]) and failures * 100 >= tonumber(ARGV[7]) * completed then
            redis.call('HSET', KEYS[1], 'opened_at', now)
        end

        redis.call('PEXPIRE', KEYS[2], window)
        redis.call('PEXPIRE', KEYS[3], window)
        return 1
        LUA;

    public function __construct(
        private ClientInterface $redis,
        private int $failureRateThresholdPercent,
        private int $minimumCompletedRequests,
        private int $failureWindowSeconds,
        private int $recoveryCooldownSeconds,
        private int $recoveryProbeLeaseSeconds
    ) {
        if ($failureRateThresholdPercent < 1 || $failureRateThresholdPercent > 100) {
            throw new InvalidArgumentException('The failure rate threshold must be between 1 and 100 percent.');
        }

        if (min(
            $minimumCompletedRequests,
            $failureWindowSeconds,
            $recoveryCooldownSeconds,
            $recoveryProbeLeaseSeconds
        ) < 1) {
            throw new InvalidArgumentException('The minimum request count and durations must be positive.');
        }
    }

    /**
     * Returns null when the circuit rejects this request. A Redis error allows an untracked request.
     */
    public function tryAcquire(string $service): ?RequestPermit
    {
        $token = bin2hex(random_bytes(16));
        $prefix = 'ganesha:redis-rate:{' . hash('sha256', $service) . '}';
        $keys = [$prefix . ':state', $prefix . ':completed', $prefix . ':failures', $prefix . ':probe'];

        try {
            $result = $this->redis->executeCommand($this->redis->createCommand('EVAL', [
                self::ACQUIRE,
                count($keys),
                ...$keys,
                $token,
                $this->recoveryCooldownSeconds * 1000,
                $this->recoveryProbeLeaseSeconds * 1000,
            ]));
        } catch (PredisException) {
            return $this->untrackedPermit();
        }

        if ($result === []) {
            return null;
        }

        if (!is_array($result) || !is_string($result[0] ?? null) || !in_array($result[1] ?? null, [0, 1], true)) {
            return $this->untrackedPermit();
        }

        return new RequestPermit(function (bool $failed) use ($keys, $token, $result): void {
            try {
                $this->redis->executeCommand($this->redis->createCommand('EVAL', [
                    self::COMPLETE,
                    count($keys),
                    ...$keys,
                    $token,
                    $result[0],
                    $result[1],
                    $failed ? 1 : 0,
                    $this->failureWindowSeconds * 1000,
                    $this->minimumCompletedRequests,
                    $this->failureRateThresholdPercent,
                ]));
            } catch (PredisException) {
                // A storage failure must not replace the request's outcome.
            }
        });
    }

    private function untrackedPermit(): RequestPermit
    {
        return new RequestPermit(static function (bool $failed): void {
        });
    }
}
