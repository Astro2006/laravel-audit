# Task: Rendering, redirects and uploads

## Mission

Find injection vectors in rendering, redirects and file uploads.

## Inputs

- The Code candidates snapshot above — unescaped output (`{!!`, `v-html`, `x-html`, `innerHTML`, `dangerouslySetInnerHTML`), redirects with request data and stored uploads, with file and line. Open those lines instead of searching.
- Upload and download controllers, `TrustProxies` / `TrustHosts`

## Checklist

- **XSS:** `{!! !!}`, `v-html`, `dangerouslySetInnerHTML`, Alpine `x-html`, `innerHTML` in JS, markdown rendered without sanitizing, user data in `<script>` without `@js` / `Js::from()`, user-controlled `href`/`src` (`javascript:` URLs), SVG or HTML uploads served inline. (`xss`)
- **Open redirects:** `redirect()->to()` / `away()` with request input; header injection; `TrustProxies` / `TrustHosts` configuration. (`open-redirect`)
- **File uploads:** validation (`mimes` vs extension, `max`), storage on the public disk, trusting client filenames, path traversal in download routes. (`file-upload`)
