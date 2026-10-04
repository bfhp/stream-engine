# Bootstrap theme

The reference extended theme: the site's Bootstrap look, built on top of the
`default` base theme. Copy this folder when you start a theme of your own.
See `docs/THEME_CONTRACT.md` and `docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md`.

## The rule

`default` knows nothing about Bootstrap and works without it. This theme
overrides only the pieces of markup where it wants Bootstrap's styles;
everything else renders `default`'s markup.

## How it builds on `default`

- **`theme.json`** declares `"parent": "default"`: templates resolve here
  first, then in `default`, then in module views. It sets
  `"inheritAssets": false` to fix the stylesheet order:
  1. `base.css` - `default`'s styles, for the markup this theme does not
     override;
  2. `bootstrap.css` - Bootstrap's prebuilt CSS (`bootstrap-rtl.css` for
     right-to-left locales, via `assets.rtl`);
  3. `bootstrap-skin.css` - the site's colors and navbar/footer tweaks;
  4. `site.css`, `custom-content.css`.

  Scripts: `site.js` and `ui-bootstrap.js`, which registers the Bootstrap UI
  adapter (modals, toasts, tooltips) and Bootstrap's data API.
- **Class blocks** where only classes differ: `layouts/*.twig`, `404.twig`.
- **Block overrides that reuse the rest** with `block()`:
  `partials/header.twig`, `partials/footer.twig`, `partials/comments.twig`,
  `error.twig`.
- **Own components** where the structure is Bootstrap-specific:
  `components/nav/*`, `components/ui/*`, `components/auth/form.twig`,
  `components/comments/*`, `components/common/*`,
  `components/users/profile-sidebar.twig`.
- **Module templates**, where a module should look like Bootstrap: same path
  as the module's file, extending the original through the module's
  namespace and overriding its blocks:

  ```twig
  {# modules/users/register1.twig #}
  {% extends '@Users/modules/users/register1.twig' %}

  {% block register_header %}
      <h1>{{ trans('user.registration') }} <small class="text-muted">{{ trans('view.users.register1.01') }}</small></h1>
      <hr>
  {% endblock %}
  ```

Overrides keep the hooks scripts rely on: `data-*` attributes, ids such as
`authFormModal`, and the `data-slot*` fill points of components rendered as
`<template>` (comments today).

Always extend through a namespace (`@default/...`, `@Users/...`); a plain
`'layouts/base.twig'` resolves to this same file.

`color_mode` has the same name as in `default`, so a value saved for
`default` carries over until one is saved here.

## Status

The platform layer is done. Modules rewritten for `default` so far, with
their Bootstrap overrides here: Feedback (`modules/feedback/page.twig`,
`components/feedback/form.twig`), Search (`components/search/results.twig`,
`components/search/result.twig`), Article (`components/article/*.twig`,
`modules/article/article.show.twig`), Profile (`components/profile/**`,
`modules/profile/*.twig`). The other modules' templates still use
Bootstrap classes directly.
