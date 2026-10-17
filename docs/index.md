# omnistate/nara

## Installation

```sh
composer require omniscient/omnistate omnistate/nara
```

In a Symfony application, `OmnistateBundle` registers it when it is installed; its key goes in the
configuration, read from the environment - never written in a versioned file:

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

In plain PHP: `new Nara($httpClient, $apiKey)`. A key is asked of NARA by e-mail
(Catalog_API@nara.gov); a read-only one is enough.

## What is asked

`GET https://catalog.archives.gov/api/v2/records/search`, the key in the header `x-api-key`
(never in the address):

| Query | Parameter |
|---|---|
| `givenName`, `familyName`, `place` | `q` (the words, all of them: the Catalog joins them with AND and ranks the files) |
| `dated` | `startDate`, `endDate` (`YYYY`): the Catalog compares them with the dates of the file's series |
| `limit`, `page` | `limit`, `page` (from 1) |

`born`, `died`, `sex` and `country` are not searched on. `find($naId)` asks the same address with
`naId_is`. Only files and items are kept: a series or a record group that matches the words is
about no one.

## What comes back

A `CivilRecord` of kind `OTHER`:

- `identifier`: the NAID;
- `title`: the file's title, where the name is;
- `description`: its record group, its series, its scope note when it has one;
- `period`: the inclusive dates of its series - the Catalog does not date the file itself, so
  `date` is empty;
- `archive`: the reference room that holds it (`National Archives at Washington, DC - Textual
  Reference`), code `NARA`, reference `RG <record group>, <microfilm publication>, NAID <naId>`,
  and its page;
- `url`: `https://catalog.archives.gov/id/<naId>`;
- `images`: the addresses of its scanned pages at catalog.archives.gov (JPG, JP2, TIFF...), when
  it was scanned - links, nothing is copied;
- `persons` is empty: the Catalog gives the name in the title only.

## Limits

10,000 calls a month per key (NARA's default): past it the key is blocked until the first of the
next month, and every call answers `UnavailableException` with `retryAfter` set to the seconds
left until then. `omniscient/omnistate` keeps answers in its cache (a day by default): a person
looked for again is not asked again.

## Errors

- No key, or a wrong one: the Catalog answers its web page (HTML) with a 200 - read as
  `UnavailableException` ("an API key is needed"), never as "nothing found".
- HTTP 429: `UnavailableException`, `retryAfter` until the first of the next month.
- HTTP 401, 403, 5xx, no answer: `UnavailableException`.
- An unknown NAID: no hit, `null`.

## Verified, and not

Against the real service on 2026-10-10, with a key: a search by name with dates (2,257 files for
John Darwin between 1775 and 1900), its second page, a file by its NAID, an unknown NAID (no
hit), a search with no result, the answer without a key and with a wrong one (the web page, 200),
one scanned page opened (200). The fixtures in `Tests/Fixtures/` are its answers, without the
response's headers and the search's aggregations.

Not verified: the 429 of a spent quota and the 401/403 the documentation does not describe - the
handling follows NARA's README (the key blocked until the first of the next month); `geographicReference`
and the other filters of the API, not used.
