# Bootstrap theme

The reference extended theme: the site's Bootstrap look, built on top of the
`default` base theme. Copy this folder when you start a theme of your own.
See `docs/THEME_CONTRACT.md` and `docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md`.

## How it builds on `default`

- **`theme.json`** declares `"parent": "default"`. Templates resolve here
  first, then in `default`, then in module views. Parent assets load before
  this theme's own.
- **Assets.** Bootstrap's stylesheet with its RTL build (`assets.rtl`) and
  `/assets/js/ui-bootstrap.js`, which registers the Bootstrap UI adapter
  (`assets-src/site/ui-bootstrap.ts`) for modals, toasts and tooltips.
- **Templates.** Only files whose structure differs from `default` live
  here, and they extend the base instead of copying it:

  ```twig
  {# layouts/base.twig #}
  {% extends '@default/layouts/base.twig' %}

  {% block layout_header %}
      {% include 'partials/navbar.twig' %}
  {% endblock %}
  ```

  Always extend through the namespace (`@default/...`); a plain
  `'layouts/base.twig'` would resolve to this same file.
- **Settings.** `color_mode` has the same name as in `default`, so a value
  saved for `default` carries over until one is saved here.

## Status

Phase 1 of ADR-002: `default` is still the Bootstrap design and lists the same
assets, so this theme has no templates of its own yet and looks identical.
In Phase 2 `default` drops Bootstrap, and the Bootstrap-specific structure
moves here as block overrides.
