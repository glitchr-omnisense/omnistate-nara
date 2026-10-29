<?php

namespace Omnistate\Nara;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Http\Redaction;
use Omnistate\Model\Archive;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecord;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Registry\CivilRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The National Archives Catalog of the United States (NARA), through its
 * Catalog API v2: the federal records described file by file, many of them
 * by the name of the person they are about - pension application files,
 * compiled military service records, draft registration cards, indexes to
 * naturalization papers, Indian census cards, homestead certificates,
 * deceased personnel files... and, for a good share, their pages scanned.
 *
 * It is a catalogue of federal archives, NOT civil registration: births,
 * marriages and deaths are kept by the states and counties, and the Catalog
 * holds none of them as such. Every record is therefore of the kind OTHER: a
 * file that names the person, with its series, its record group and the
 * reference room that holds it, and the links to its scanned pages at NARA
 * (never copied). The Catalog dates a file by its series only: the span of
 * the series is the record's period, not the date of an event.
 *
 * A key is needed (header x-api-key; ask Catalog_API@nara.gov), 10,000 calls
 * a month per key: past it, the key is blocked until the first of the next
 * month. Without a valid key the API answers the Catalog's HTML page with a
 * 200, which is read as unavailable.
 */
final class Nara implements CivilRegistryInterface
{
    public const URL = 'https://catalog.archives.gov/api/v2';
    public const PAGE = 'https://catalog.archives.gov/id/';

    /** Levels that describe a file or an item: a series or a record group is about no one. */
    private const DOCUMENTS = ['fileUnit', 'item'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $apiKey = null,
        private readonly float $timeout = 10,
        private readonly string $url = self::URL,
    ) {
    }

    public function name(): string
    {
        return 'nara';
    }

    public function countries(): array
    {
        return ['US'];
    }

    public function kinds(): array
    {
        return [CivilRecordKind::OTHER];
    }

    public function period(): ?Period
    {
        return null;
    }

    public function hasImages(): bool
    {
        return true;
    }

    public function supports(CivilQuery $query): bool
    {
        return $query->hasName() && $query->inCountries('US') && $query->wants(CivilRecordKind::OTHER);
    }

    public function search(CivilQuery $query): array
    {
        $when = $query->dated;
        $from = $when?->from?->year;
        $to = $when?->to?->year;
        $answer = $this->ask(array_filter([
            'q' => trim($query->name().' '.($query->place ?? '')),
            // YYYY on both sides: the API wants the two in the same format
            'startDate' => null !== $from ? sprintf('%04d', $from) : null,
            'endDate' => null !== $to ? sprintf('%04d', $to) : null,
            'limit' => $query->limit,
            'page' => $query->page > 1 ? $query->page : null,
        ], static fn (mixed $value) => null !== $value && '' !== $value));

        $records = [];
        foreach ($answer['body']['hits']['hits'] ?? [] as $hit) {
            $record = $hit['_source']['record'] ?? null;
            if (\is_array($record) && \in_array($record['levelOfDescription'] ?? null, self::DOCUMENTS, true)) {
                $records[] = $this->record($record);
            }
        }

        return $records;
    }

    public function find(string $identifier): ?CivilRecord
    {
        if (!preg_match('/^\d{1,15}$/', $identifier)) {
            return null;
        }
        $record = $this->ask(['naId_is' => $identifier], true)['body']['hits']['hits'][0]['_source']['record'] ?? null;

        return \is_array($record) && isset($record['naId']) ? $this->record($record) : null;
    }

    /** @return array<string, mixed> */
    /** @param bool $find a lookup: a 404 is no such file (an empty answer) */
    private function ask(array $query, bool $find = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers['x-api-key'] = $this->apiKey;
        }
        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').'/records/search', ['query' => $query, 'headers' => $headers, 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                throw new UnavailableException('The NARA Catalog refused the call (429): the key\'s monthly quota is spent until the first of the next month.', self::untilNextMonth());
            }
            if (\in_array($status, [401, 403], true)) {
                throw new UnavailableException(sprintf('The NARA Catalog refused the key (%d).', $status));
            }
            if ($status >= 500) {
                throw new UnavailableException(sprintf('The NARA Catalog answered %d.', $status));
            }
            if ($find && 404 === $status) {
                return [];
            }
            if ($status >= 400) {
                throw new OmnistateException(sprintf('The NARA Catalog refused the question (%d).', $status));
            }
            $body = trim($response->getContent(false));
            // no key, or a wrong one: the Catalog's web page, with a 200
            if ('' === $body || '{' !== $body[0]) {
                throw new UnavailableException('The NARA Catalog answered no data: an API key is needed (x-api-key), and this one is missing or wrong.');
            }

            return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            // The key, in the request's headers, kept out: the previous a copy of the client's exception, no trace keeping the options.
            $secrets = array_values(array_filter([(string) $this->apiKey]));

            throw Redaction::withoutArguments(new UnavailableException('The NARA Catalog did not answer: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
    }

    /** @param array<string, mixed> $record a hit's _source.record */
    private function record(array $record): CivilRecord
    {
        $id = (string) $record['naId'];
        $series = self::ancestor($record, 'series');
        $group = self::ancestor($record, 'recordGroup');

        $scope = $record['scopeAndContentNote'] ?? null;
        $scope = \is_string($scope) ? trim(preg_replace('/\s+/', ' ', $scope)) : null;

        $holder = $record['physicalOccurrences'][0]['referenceUnits'][0]['name'] ?? 'National Archives and Records Administration';
        $microfilm = $record['microformPublications'][0]['identifier'] ?? null;
        $reference = implode(', ', array_filter([
            isset($group['recordGroupNumber']) ? 'RG '.$group['recordGroupNumber'] : null,
            \is_string($microfilm) ? $microfilm : null,
            'NAID '.$id,
        ]));

        $images = [];
        foreach ($record['digitalObjects'] ?? [] as $object) {
            if (isset($object['objectUrl']) && str_starts_with((string) ($object['objectType'] ?? ''), 'Image')) {
                $images[] = (string) $object['objectUrl'];
            }
        }

        return new CivilRecord(
            identifier: $id,
            kind: CivilRecordKind::OTHER,
            source: $this->name(),
            archive: new Archive((string) $holder, 'NARA', $reference, self::PAGE.$id),
            url: self::PAGE.$id,
            images: $images,
            title: trim((string) ($record['title'] ?? '')) ?: null,
            description: implode(' — ', array_filter([$group['title'] ?? null, $series['title'] ?? null, $scope])) ?: null,
            period: null === $series ? null : self::span($series),
            raw: $record,
        );
    }

    /** @return array<string, mixed>|null the nearest ancestor of that level */
    private static function ancestor(array $record, string $level): ?array
    {
        foreach ($record['ancestors'] ?? [] as $ancestor) {
            if (($ancestor['levelOfDescription'] ?? null) === $level) {
                return $ancestor;
            }
        }

        return null;
    }

    /** The series' inclusive dates: {year, month?, day?}, ca. or not. */
    private static function span(array $series): ?Period
    {
        $from = self::date($series['inclusiveStartDate'] ?? null);
        $to = self::date($series['inclusiveEndDate'] ?? null);
        if (null === $from && null === $to) {
            return null;
        }

        return Period::years($from?->year, $to?->year);
    }

    private static function date(mixed $date): ?PartialDate
    {
        return \is_array($date) && isset($date['year']) ? PartialDate::of((int) $date['year'], isset($date['month']) ? (int) $date['month'] : null, isset($date['day']) ? (int) $date['day'] : null) : null;
    }

    /** Seconds until the first of the next month (UTC), when a spent key is renewed. */
    private static function untilNextMonth(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(60, $now->modify('first day of next month midnight')->getTimestamp() - $now->getTimestamp());
    }
}
