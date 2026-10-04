# Bootstrap theme

The reference extended theme: the site's Bootstrap look, built on top of the
`default` base theme. Copy this folder when you start a theme of your own.
See `docs/THEME_CONTRACT.md` and `docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md`.

## How it builds on `default`

- **`theme.json`** declares `"parent": "default"`: templates resolve here
  first, then in `default`, then in module views. It sets
  `"inheritAssets": false` and lists its assets itself, because Bootstrap
  (`bootstrap.css`, with the theme skin and an RTL build in `assets.rtl`)
  must load before `site.css`, and `default`'s `base.css` is not needed.
  `/assets/js/ui-bootstrap.js` registers the Bootstrap UI adapter.
- **Class blocks** where only classes differ: `layouts/*.twig`, `404.twig`.
- **Block overrides that reuse the rest** with `block()`:
  `partials/header.twig`, `partials/footer.twig`, `partials/comments.twig`,
  `error.twig`.
- **Own components** where the markup is Bootstrap-specific:
  `components/nav/*`, `components/ui/*`, `components/auth/form.twig`,
  `components/comments/*`, `components/common/*`,
  `components/users/profile-sidebar.twig`. They keep the data-* hooks the
  scripts use (`data-slot*`, `data-comment-form`, `data-reply-toggle`, ids
  such as `authFormModal`).

Always extend through the namespace (`@default/...`); a plain
`'layouts/base.twig'` resolves to this same file.

`color_mode` has the same name as in `default`, so a value saved for
`default` carries over until one is saved here.

## Status

ADR-002 Phase 2: the platform layer is done. Module templates still use
Bootstrap classes directly; when they move to the UI vocabulary, this theme
maps `ui-*` onto Bootstrap with Sass instead of copying module templates.
