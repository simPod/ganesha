# Use request permits for Redis recovery

## Status

Accepted for the fork. The [upstream maintenance question](https://github.com/ackintosh/ganesha/issues/140) is separate.

## Context

The service-name-only outcome API cannot distinguish a request admitted before a circuit opened from a
recovery probe. Checking a timestamp separately from updating it cannot reserve a probe for one worker.

## Decision

Offer a separate Redis rate strategy with a single-use request permit. Use Redis scripts for admission and
completion. Keep the per-service generation in a state hash, separate from expiring outcome history and the
probe key. Place all keys for one service in the same Redis Cluster slot.

An open circuit admits at most one live probe lease after its cooldown. Only the holder of that lease can
report a probe outcome. Recovery or loss of the state starts a new generation, so old requests cannot alter it.

## Consequences

The existing API, integrations, adapters, and stored data stay unchanged. The new strategy uses a separate
key namespace and keeps one small state hash per service. An expired probe may still be running when a
replacement starts, but its result is ignored. Redis errors admit untracked requests.
