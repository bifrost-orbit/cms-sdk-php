# Changelog

## 0.2.0 (8 November 2026)

- `CacheTags::safe($tag)` and `CacheTags::safeAll($tags)` encode tags and keys for caches that refuse `{}()/\@:`, such as Symfony Cache: `cms:page:germany:/pricing` becomes `cms%3Apage%3Agermany%3A%2Fpricing`. Use them both when storing answers and with `Webhook::tagsForEvent`. The tags themselves are unchanged, so they still match the JavaScript SDK and caches such as Laravel's Redis store keep working as before. The encoding is `rawurlencode`, so sites that already encoded tags that way can switch without clearing their cache.

- When the organisation's CMS Orbit trial or plan has ended, the delivery API and GraphQL answer 402. Every call then throws `SubscriptionInactiveException` (status `402`, `errorCode` `subscription_inactive`) straight away, without retrying, whatever code the API sends (older CMS Orbit deployments sent `plan_ended`). `Client::VERSION` and the User-Agent are now `0.2.0`.
- `Images::srcSet` offers 320, 480, 640, 768, 960, 1280, 1600 and 1920 pixels wide instead of 640, 960, 1280 and 1920, the widths CMS Orbit makes, so phones and small cards load a photo close to the size they show it at.
- Pages carry `id`, the entry's id, and `insights`, `['site' => '…']` when the space is connected to Insights Orbit or `null`. Pages from an older CMS Orbit have neither key.
- `Insights::tags($page)` writes the Insights Orbit script with the site key and `<meta name="insights-content">` with the entry's id, for server-rendered pages. With `waitForConsent`, the script waits for `insights.consent()` before counting. Without a site key it writes nothing.
