<?php

namespace Omnistate\Nara\Tests;

use Omnistate\Model\CivilQuery;
use Omnistate\Nara\Nara;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on the register's recorded answers (Tests/Fixtures). */
final class NaraContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new Nara($http, 'test-key');
    }

    protected function recordedAnswers(): array
    {
        return ['*' => static fn (string $method, string $url) => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match (true) {
            str_contains($url, '115683385') => 'record.json',
            default => 'search.json',
        }), ['response_headers' => ['content-type' => 'application/json']])];
    }

    protected function civilQuery(): ?CivilQuery
    {
        return new CivilQuery(familyName: 'Darwin', givenName: 'John');
    }

    protected function civilIdentifier(): ?string
    {
        return '115683385';
    }
}
