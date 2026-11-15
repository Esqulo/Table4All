# Table4All brand assets

Source files of the Table4All identity. These are the originals; the app
serves optimized copies from `public/images/brand/` and the favicons in
`public/`. Brand green: `#22B573` (same as the app's `--primary`).

| Folder | Contents |
|---|---|
| `symbol/` | The isometric chair symbol, green and white. The SVGs are traced from the PNGs (the 3D gradient is approximated by flat color bands). |
| `wordmark/` | The "Table 4 all" lettering, green and white (PNG only, no vector yet). |
| `lockup/` | Symbol stacked over the wordmark, transparent PNGs plus JPGs on a solid background. |
| `avatar/` | White symbol on a green square, for profile pictures and the iOS home-screen icon. |

## Usage rule

Symbol and wordmark always share one color: **green** on any light or dark
background, **white** only on brand-green surfaces. Never pair a green symbol
with a white wordmark.

## Derived files in the app

| App file | Made from |
|---|---|
| `public/images/brand/mark-green.svg`, `mark-white.svg` | `symbol/*.svg`, with rounded coordinates and no fixed size |
| `public/images/brand/wordmark-green.png`, `wordmark-white.png` | `wordmark/*.png`, cropped and resized to 96px tall |
| `public/favicon.svg` | `symbol/table4all-symbol-green.svg` centered on a transparent square |
| `public/favicon.ico` | `symbol/table4all-symbol-green.png` on a transparent square (16/32/48px) |
| `public/apple-touch-icon.png` | `avatar/table4all-avatar-512.png` at 180px (opaque: iOS fills transparency with black) |

Components: `AppLogoIcon` (symbol) and `AppLogoWordmark` in `resources/js/components/`.
When an icon changes, bump the `?v=` query on the favicon links in `resources/views/app.blade.php`.
