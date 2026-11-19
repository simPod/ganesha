<?php

namespace Ackintosh\Ganesha;

use Closure;

/**
 * Records at most one outcome for an admitted request.
 */
final class RequestPermit
{
    /** @var Closure(bool): void */
    private $complete;

    /** @param Closure(bool): void $recordOutcome */
    public function __construct(Closure $recordOutcome)
    {
        $this->complete = static function (bool $failed) use ($recordOutcome): void {
            static $completed = false;

            if ($completed) {
                return;
            }

            $completed = true;
            $recordOutcome($failed);
        };
    }

    public function succeed(): void
    {
        ($this->complete)(false);
    }

    public function fail(): void
    {
        ($this->complete)(true);
    }
}
