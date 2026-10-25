# omnistate/nara

Federal files that name a person in the [National Archives Catalog](https://catalog.archives.gov)
of the United States (NARA), through its Catalog API v2, for
[omniscient/omnistate](https://github.com/glitchr-studio/omnistate): pension application files,
compiled military service records, draft registration cards, indexes to naturalization papers,
Indian census cards, homestead certificates - and, for many, links to their scanned pages.

> [!IMPORTANT]
> A catalogue of federal archives, not civil registration. Births, marriages and deaths in the
> United States are kept by the states and counties; the Catalog holds none of them as such.
> Every record is of the kind `OTHER`: a file that names the person, with its series, its record
> group and the reference room that holds it.

| Operation | Request |
|---|---|
| `search($query)` | `GET https://catalog.archives.gov/api/v2/records/search?q=…&startDate=…&endDate=…&limit=…&page=…` |
| `find($naId)` | `GET https://catalog.archives.gov/api/v2/records/search?naId_is=…` |

with the key in the header `x-api-key` (never in the address) and `Accept: application/json`. The
base address is the constant `Nara::URL` (or the `url` argument); a file's page is `Nara::PAGE` +
its NAID (`https://catalog.archives.gov/id/…`).

## Installation

```sh
composer require omniscient/omnistate omnistate/nara
```

Requires PHP 8.2, `omniscient/omnistate` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application, `OmnistateBundle` registers it when it is installed (tag
`omnistate.civil_registry`); its key goes in the configuration, read from the environment - never
written in a versioned file:

```yaml
# config/packages/omnistate.yaml
omnistate:
    nara:
        api_key: '%env(NARA_API_KEY)%'
```

```sh
# .env (versioned): the variable, empty
NARA_API_KEY=
# .env.local (ignored by git) or the secrets vault: the key itself
```

## Credentials

An API key, free: asked of NARA by e-mail (Catalog_API@nara.gov); a read-only one is enough. It
allows 10,000 calls a month per key (NARA's default): past it the key is blocked until the first
of the next month. There is no sandbox and no shared key. Without a key, or with a wrong one, the
Catalog answers its web page (HTML) with a 200, read as `UnavailableException`.

## Options

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `apiKey` | `?string` | `null` | the Catalog API key (the bundle's `omnistate.nara.api_key`); without one, no `x-api-key` header is sent |
| `timeout` | float | `10` | seconds the Catalog has to answer |
| `url` | string | `https://catalog.archives.gov/api/v2` | the API's base address |

## Capabilities

| | |
|---|---|
| Contract | `CivilRegistryInterface` (`name()`: `nara`) |
| `countries()` | `['US']` |
| `kinds()` | `[CivilRecordKind::OTHER]` only |
| `period()` | `null` |
| `hasImages()` | `true`: links to the scanned pages, when the file was scanned |
| `search()`, `find()` | yes |

`supports()` is true for a query with a name, for US or no country, whose kinds include `OTHER` or
are empty: a question for births, marriages or deaths alone is not one for it.

## Behaviour

**A search.** From the `CivilQuery`:

| Query | Parameter |
|---|---|
| `givenName`, `familyName`, `place` | `q` (the words, all of them: the Catalog joins them with AND and ranks the files) |
| `dated` | `startDate`, `endDate` (`YYYY`, both in the same format): the Catalog compares them with the dates of the file's series |
| `limit`, `page` | `limit`, `page` (from 1, sent from the second page) |

`born`, `died`, `sex` and `country` are not searched on. Only hits whose `levelOfDescription` is
`fileUnit` or `item` are kept: a series or a record group that matches the words is about no one,
so a page can hold fewer records than the limit.

**A file.** `find()` takes a NAID of 1 to 15 digits (anything else is `null` without a request),
asks `naId_is`, and reads the first hit.

**What comes back.** A `CivilRecord` of kind `OTHER`:

- `identifier`: the NAID;
- `title`: the file's title, where the name is;
- `description`: its record group, its series, its scope note when it has one, joined by ` — `;
- `period`: the inclusive years of its series - the Catalog does not date the file itself, so
  `date` is empty;
- `archive`: the reference room that holds it (`National Archives at Washington, DC - Textual
  Reference`, else `National Archives and Records Administration`), code `NARA`, reference
  `RG <record group>, <microfilm publication>, NAID <naId>`, and its page;
- `url`: `https://catalog.archives.gov/id/<naId>`;
- `images`: the addresses of its digital objects whose type starts with `Image` (JPG, JP2,
  TIFF...) at catalog.archives.gov - links, nothing is copied;
- `persons` is empty: the Catalog gives the name in the title only; `raw` the hit's record.

**Limits.** 10,000 calls a month per key: past it the key is blocked until the first of the next
month, and every call answers `UnavailableException` with `retryAfter` set to the seconds left
until then (UTC). Keep answers in a cache: the facade's (a day by default) spares the month's
calls - a person looked for again is not asked again.

## Example

Without a key at hand, this script runs the register on the Catalog's answers recorded in
`Tests/Fixtures`, served by a `MockHttpClient`; with a key, replace the client by
`HttpClient::create()` and `'a-key'` by the key.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistate\Exception\UnavailableException;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\Period;
use Omnistate\Nara\Nara;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The Catalog's answers recorded in omnistate/nara's Tests/Fixtures, in place of the API
$fixtures = __DIR__.'/vendor/omnistate/nara/Tests/Fixtures/';
$asked = [];
$http = new MockHttpClient(static function (string $method, string $url) use ($fixtures, &$asked) {
    $asked[] = urldecode($url);

    return new MockResponse((string) file_get_contents($fixtures.(str_contains($url, 'naId_is') ? 'record.json' : 'search.json')));
});
$nara = new Nara($http, 'a-key');

foreach ($nara->search(new CivilQuery(familyName: 'Darwin', givenName: 'John', dated: Period::years(1775, 1900), limit: 3)) as $file) {
    printf("%s  %s\n  %s\n  period %s, %d scan(s), %s\n", $file->identifier, $file->title, $file->archive, $file->period, count($file->images), $file->url);
}
echo implode("\n", $asked), "\n";

// Without a key the Catalog answers its web page, with a 200
$keyless = new Nara(new MockHttpClient(new MockResponse('<!doctype html><html><body>National Archives Catalog</body></html>')));
try {
    $keyless->search(new CivilQuery(familyName: 'Darwin'));
} catch (UnavailableException $e) {
    echo $e->getMessage(), "\n";
}
```

```
54373887  Revolutionary War Pension and Bounty Land Warrant Application File S. 21155, John Darwin, S.C. Va.
  National Archives at Washington, DC - Textual Reference, RG 15, M804, NAID 54373887
  period 1800/1912, 33 scan(s), https://catalog.archives.gov/id/54373887
115683385  Approved Pension Application File for Margaret Gilbo Darwin, Widow of John Darwin, 12 Battery New York Light Artillery Regiment (Application No. WC43727)
  National Archives at Washington, DC - Textual Reference, RG 15, NAID 115683385
  period 1861/1934, 50 scan(s), https://catalog.archives.gov/id/115683385
27696821  Darwin, John - State: [Blank] - Year: [Blank]
  National Archives at Washington, DC - Textual Reference, RG 109, M345, NAID 27696821
  period 1861/1867, 1 scan(s), https://catalog.archives.gov/id/27696821
https://catalog.archives.gov/api/v2/records/search?q=John Darwin&startDate=1775&endDate=1900&limit=3
The NARA Catalog answered no data: an API key is needed (x-api-key), and this one is missing or wrong.
```

(run on 2026-10-25 in omnistate's Docker harness, on the recorded answers - the Catalog's answers
of 2026-10-10 - not against the Catalog)

## Errors

- No key, or a wrong one: the Catalog answers its web page (HTML) with a 200 - read as
  `UnavailableException` (`The NARA Catalog answered no data: an API key is needed...`), never as
  "nothing found".
- HTTP 429: `UnavailableException`, `retryAfter` until the first of the next month (at least 60 s).
- HTTP 401, 403: `UnavailableException` (`The NARA Catalog refused the key (403).`).
- HTTP 5xx, no answer: `UnavailableException`.
- HTTP 404 on `find()`: `null`; another 4xx: `OmnistateException` (`The NARA Catalog refused the
  question (400).`).
- An unknown NAID: no hit, `null`.

## Verified, and not

Tested offline: `Tests/NaraTest.php` (a catalogue, not civil registration; files that name
someone; the second page and nothing found; a file by its identifier; the web page without a key;
a spent quota or a service down is unavailable, not nothing) and `Tests/NaraContractTest.php`,
omnistate's conformance suite.

Against the real service on 2026-10-10, with a key: a search by name with dates (2,257 files for
John Darwin between 1775 and 1900), its second page, a file by its NAID, an unknown NAID (no
hit), a search with no result, the answer without a key and with a wrong one (the web page, 200),
one scanned page opened (200). The fixtures in `Tests/Fixtures/` are its answers, without the
response's headers and the search's aggregations. Not run live on 2026-10-25: no key at hand.

Not verified: the 429 of a spent quota and the 401/403 the documentation does not describe - the
handling follows NARA's README (the key blocked until the first of the next month);
`geographicReference` and the other filters of the API, not used.

## Responsibility

An independent, unofficial client, not affiliated with the U.S. National Archives and Records
Administration. Whoever runs it complies with the National Archives Catalog API's terms ([its
terms of use](https://www.archives.gov/research/catalog/help/api#terms-of-use)): see the README's
Responsibility section.
