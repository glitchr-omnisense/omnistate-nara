# omnistate/nara

Federal files that name a person in the [National Archives Catalog](https://catalog.archives.gov)
of the United States (NARA), through its Catalog API v2, for
[glitchr/omnistate](https://github.com/glitchr-studio/omnistate).

**A catalogue of federal archives, not civil registration.** Births, marriages and deaths in the
United States are kept by the states and counties; the Catalog holds none of them as such. What it
answers to a name is the description of federal files that mention it - pension application files,
compiled military service records, draft registration cards, indexes to naturalization papers,
Indian census cards, homestead certificates - with the series and record group they belong to, the
reference room that holds them, and, for many, links to their scanned pages at NARA. Every record is
of the kind `OTHER`.

```php
$registry = new Nara($httpClient, $apiKey);
$files = $registry->search(new CivilQuery(familyName: 'Darwin', givenName: 'John', dated: Period::years(1775, 1900)));
$files[0]->title;      // "Revolutionary War Pension and Bounty Land Warrant Application File S. 21155, John Darwin, S.C. Va."
$files[0]->archive;    // National Archives at Washington, DC - Textual Reference, RG 15, M804, NAID 54373887
$files[0]->url;        // https://catalog.archives.gov/id/54373887
$files[0]->images;     // the scanned pages, at catalog.archives.gov
```

No framework needed: the package requires `glitchr/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

| | |
|---|---|
| Countries | US |
| Kinds | OTHER only (a question for births, marriages or deaths alone is not one for it) |
| Picture of the document | links to the scanned pages at NARA, when the file was scanned (never copied) |
| Persons | not structured: the name is in the title |
| Dates | the span of the file's series; the Catalog does not date the file itself |
| Access | free, **API key** (header `x-api-key`, ask Catalog_API@nara.gov); **10,000 calls a month** per key, the key blocked until the first of the next month past it |

Keep answers in a cache: `glitchr/omnistate` does (`Omnistate`'s cache, a day by default), and that
is what keeps a search screen within the month's 10,000 calls.

Documentation: [docs/](docs/index.md). License: MIT.
