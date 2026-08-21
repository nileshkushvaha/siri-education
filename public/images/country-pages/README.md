# Country landing page hero images

`CountryLandingPageSeeder` expects one image per country here, named
`{country-name-slug}-student.webp`:

| Country              | Expected file                          |
|----------------------|----------------------------------------|
| India                | `india-student.webp`                   |
| United States        | `united-states-student.webp`           |
| United Kingdom       | `united-kingdom-student.webp`          |
| Australia            | `australia-student.webp`               |
| Canada               | `canada-student.webp`                  |
| United Arab Emirates | `united-arab-emirates-student.webp`    |
| Singapore            | `singapore-student.webp`               |
| New Zealand          | `new-zealand-student.webp`             |
| Saudi Arabia         | `saudi-arabia-student.webp`            |

The filename is derived from the country's `name` column via `Str::slug()`,
so renaming a country in the admin changes the expected filename.

Specification: 4:3 landscape, 1200x900, WebP, ideally under 150 KB. The hero
component declares `width="1200" height="900"` to reserve the box before the
image loads, so a different aspect ratio will letterbox inside the crop.

Each page references its image directly at `/images/country-pages/...` for the
hero. Re-running the seeder additionally attaches the file to the page's
`featured-image` media collection so `SeoManager` can use it for `og:image` —
that step is skipped (with a warning) while the file is missing, and never
replaces an image an admin has already set.
