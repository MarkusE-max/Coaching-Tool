# Browser signing libraries

- qrcode.js 993e88f396640f881b69f98db7a4d17401ef83ca: bundled locally from https://github.com/davidshimjs/qrcodejs (MIT; license in LICENSE-qrcodejs.txt).
- pdf-lib 1.17.1: loaded from the pinned jsDelivr URL https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js (MIT; license in LICENSE-pdf-lib.md).

The browser downloads only the library code from jsDelivr. The document PDF and signature stay in the browser and are submitted only to this Coaching Tool server. The signing page sends no referrer to the CDN. A network connection is needed to load pdf-lib.
