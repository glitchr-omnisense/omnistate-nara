<?php

namespace Omnistate\Nara\Tests;

use Omnistate\Exception\UnavailableException;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\Period;
use Omnistate\Nara\Nara;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * On answers recorded from catalog.archives.gov (Catalog API v2) on
 * 2026-10-10, kept as they came back but for the response's own headers and
 * the search's aggregations - nothing of the request, no key.
 */
final class NaraTest extends TestCase
{
    private const KEY = 'a-key-for-the-tests';

    public function testItIsACatalogueNotCivilRegistration(): void
    {
        $nara = new Nara(new MockHttpClient());

        self::assertSame('nara', $nara->name());
        self::assertSame(['US'], $nara->countries());
        self::assertSame([CivilRecordKind::OTHER], $nara->kinds());
        self::assertNull($nara->period());
        self::assertTrue($nara->hasImages(), 'links to the scanned pages, at NARA');

        self::assertTrue($nara->supports(new CivilQuery(familyName: 'Darwin', givenName: 'John')));
        self::assertTrue($nara->supports(new CivilQuery(familyName: 'Darwin', country: 'us')));
        self::assertFalse($nara->supports(new CivilQuery(familyName: 'Darwin', kinds: [CivilRecordKind::BIRTH])), 'the states keep the births');
        self::assertFalse($nara->supports(new CivilQuery(familyName: 'Darwin', country: 'GB')));
        self::assertFalse($nara->supports(new CivilQuery(country: 'US')), 'a name is what it is asked');
    }

    public function testFilesThatNameSomeone(): void
    {
        $asked = null;
        $headers = null;
        $nara = new Nara(new MockHttpClient(function (string $method, string $url, array $options) use (&$asked, &$headers) {
            $asked = urldecode($url);
            $headers = $options['headers'];

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/search.json'), ['response_headers' => ['content-type' => 'application/json; charset=UTF-8']]);
        }), self::KEY);
        $records = $nara->search(new CivilQuery(familyName: 'Darwin', givenName: 'John', dated: Period::years(1775, 1900), limit: 3));

        self::assertSame('https://catalog.archives.gov/api/v2/records/search?q=John Darwin&startDate=1775&endDate=1900&limit=3', $asked);
        self::assertContains('x-api-key: '.self::KEY, $headers, 'the key in its header, never in the address');
        self::assertCount(3, $records);

        $pension = $records[0];
        self::assertSame('54373887', $pension->identifier);
        self::assertSame(CivilRecordKind::OTHER, $pension->kind);
        self::assertSame('nara', $pension->source);
        self::assertSame('Revolutionary War Pension and Bounty Land Warrant Application File S. 21155, John Darwin, S.C. Va.', $pension->title);
        self::assertSame('https://catalog.archives.gov/id/54373887', $pension->url);
        self::assertSame('National Archives at Washington, DC - Textual Reference', $pension->archive?->name);
        self::assertSame('RG 15, M804, NAID 54373887', $pension->archive?->reference);
        self::assertSame('NARA', $pension->archive?->code);
        self::assertNull($pension->date, 'the Catalog dates a file by its series only');
        self::assertSame('1800/1912', (string) $pension->period, 'the series\' span');
        self::assertStringContainsString('Records of the Department of Veterans Affairs — Case Files of Pension and Bounty-Land Warrant Applications', (string) $pension->description);
        self::assertCount(33, $pension->images);
        self::assertStringStartsWith('https://catalog.archives.gov/', $pension->images[0], 'a link to the page at NARA, not a copy');
        self::assertSame([], $pension->persons, 'the person is in the title, not in a field');

        $citizen = $records[2];
        self::assertSame('Darwin, John - State: [Blank] - Year: [Blank]', $citizen->title);
        self::assertSame('RG 109, M345, NAID 27696821', $citizen->archive?->reference);
        self::assertSame('1861/1867', (string) $citizen->period);
    }

    public function testTheSecondPageAndNothingFound(): void
    {
        $asked = null;
        $nara = new Nara(new MockHttpClient(function (string $method, string $url) use (&$asked) {
            $asked = urldecode($url);

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/empty.json'));
        }), self::KEY);

        self::assertSame([], $nara->search(new CivilQuery(familyName: 'Vvqxkj', givenName: 'Zzqxwv', place: 'Ohio', limit: 3, page: 2)));
        self::assertSame('https://catalog.archives.gov/api/v2/records/search?q=Zzqxwv Vvqxkj Ohio&limit=3&page=2', $asked);
    }

    public function testAFileByItsIdentifier(): void
    {
        $asked = [];
        $nara = new Nara(new MockHttpClient(function (string $method, string $url) use (&$asked) {
            $asked[] = urldecode($url);

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/'.(str_contains($url, '115683385') ? 'record' : 'empty').'.json'));
        }), self::KEY);

        $file = $nara->find('115683385');
        self::assertSame('https://catalog.archives.gov/api/v2/records/search?naId_is=115683385', $asked[0]);
        self::assertSame('Approved Pension Application File for Margaret Gilbo Darwin, Widow of John Darwin, 12 Battery New York Light Artillery Regiment (Application No. WC43727)', $file?->title);
        self::assertSame('RG 15, NAID 115683385', $file->archive?->reference);
        self::assertSame('1861/1934', (string) $file->period);
        self::assertCount(50, $file->images);

        self::assertNull($nara->find('1'), 'no such file');
        self::assertNull($nara->find('../records'), 'not an identifier: not even asked');
        self::assertCount(2, $asked);
    }

    public function testWithoutAKeyTheCatalogAnswersItsWebPage(): void
    {
        $headers = null;
        $nara = new Nara(new MockHttpClient(function (string $method, string $url, array $options) use (&$headers) {
            $headers = $options['headers'];

            return new MockResponse('<!doctype html><html lang="en"><head>', ['response_headers' => ['content-type' => 'text/html']]);
        }));

        try {
            $nara->search(new CivilQuery(familyName: 'Darwin'));
            self::fail('a web page is no answer');
        } catch (UnavailableException $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
        self::assertSame([], array_filter($headers, static fn (string $h) => str_starts_with(strtolower($h), 'x-api-key')), 'no empty key sent');
    }

    public function testAQuotaSpentOrAServiceDownIsUnavailableNotNothing(): void
    {
        foreach ([429, 403, 503] as $status) {
            $nara = new Nara(new MockHttpClient(new MockResponse('{}', ['http_code' => $status])), self::KEY);
            try {
                $nara->search(new CivilQuery(familyName: 'Darwin'));
                self::fail($status.' is not an empty answer');
            } catch (UnavailableException $e) {
                if (429 === $status) {
                    self::assertGreaterThanOrEqual(60, $e->retryAfter, 'until the first of the next month');
                }
            }
        }
    }
}
