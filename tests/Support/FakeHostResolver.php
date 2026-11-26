<?php

namespace Tests\Support;

use App\Support\Feeds\HostResolver;

/** DNS for tests: say what each name resolves to; anything else doesn't resolve. */
final class FakeHostResolver implements HostResolver
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}
