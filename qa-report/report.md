# QA report — https://beta.domainbrothers.com

Ran 2026-11-08T05:08:42.670Z: 405 checks, 403 passed, 2 failed.

## Failures

| ID | Area | Check | Detail |
|---|---|---|---|
| UI-mobile-post-404 | Assets | /centr-tld-market-report-2024/ no same-site 4xx/5xx assets (mobile) | 508 /centr-tld-market-report-2024/ / 508 / / 508 / / 508 / |
| UI-mobile-services-load | Layout | /our-services/ loads in browser (mobile) | page.evaluate: TypeError: Cannot read properties of null (reading 'scrollHeight')
    at eval (eval at evaluate (:290:30 |

## Performance (browser)

| Viewport | Page | TTFB ms | DOMContentLoaded ms | Load ms | Requests | DOM nodes | Height px |
|---|---|---|---|---|---|---|---|
| desktop | home | 1214 | 1603 | 2953 | 82 | 1210 | 5932 |
| desktop | domain | 520 | 1132 | 1777 | 67 | 712 | 5423 |
| desktop | domain-priced | 492 | 896 | 1377 | 71 | 702 | 5368 |
| desktop | all-domains | 525 | 746 | 1342 | 58 | 884 | 6682 |
| desktop | category | 527 | 695 | 1236 | 60 | 489 | 3386 |
| desktop | news | 419 | 705 | 1227 | 63 | 452 | 6382 |
| desktop | post | 402 | 542 | 1100 | 59 | 318 | 2443 |
| desktop | services | 548 | 907 | 1432 | 60 | 389 | 4104 |
| desktop | service | 555 | 691 | 1330 | 58 | 302 | 3664 |
| desktop | about | 496 | 627 | 1159 | 61 | 333 | 2299 |
| desktop | faqs | 457 | 601 | 1127 | 58 | 433 | 4163 |
| desktop | contact | 402 | 688 | 1178 | 74 | 382 | 2320 |
| desktop | offer | 546 | 790 | 1278 | 69 | 369 | 2369 |
| desktop | privacy | 524 | 656 | 1146 | 30 | 349 | 4973 |
| desktop | sold | 608 | 753 | 1269 | 58 | 528 | 3409 |
| desktop | epp | 514 | 666 | 1225 | 61 | 427 | 5165 |
| mobile | home | 1372 | 1697 | 3218 | 76 | 1210 | 8816 |
| mobile | domain | 510 | 1095 | 1797 | 68 | 713 | 9742 |
| mobile | domain-priced | 505 | 907 | 1483 | 70 | 702 | 9620 |
| mobile | all-domains | 493 | 713 | 1250 | 58 | 884 | 12351 |
| mobile | category | 491 | 671 | 1184 | 58 | 489 | 4805 |
| mobile | news | 1344 | 1494 | 2023 | 60 | 452 | 6050 |
| mobile | post | 209 | 298 | 357 | 10 | 11 | 0 |
| mobile | service | 475 | 628 | 1293 | 58 | 301 | 4593 |
| mobile | about | 464 | 632 | 1191 | 59 | 332 | 2463 |
| mobile | faqs | 434 | 584 | 1146 | 58 | 433 | 5682 |
| mobile | contact | 380 | 651 | 1105 | 73 | 382 | 3010 |
| mobile | offer | 396 | 664 | 1117 | 69 | 369 | 2869 |
| mobile | privacy | 452 | 603 | 1117 | 30 | 349 | 7776 |
| mobile | sold | 508 | 660 | 1205 | 59 | 528 | 3239 |
| mobile | epp | 522 | 673 | 1207 | 60 | 427 | 8437 |
