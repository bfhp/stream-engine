// CSS-only entry: emits css/bootstrap-skin.css, the `bootstrap` theme's colors and
// navbar/footer tweaks. Its own entry (listed in the theme manifest after
// bootstrap.css) rather than an import shared by the LTR and RTL entries:
// a shared import is split into a chunk the manifest would never load.
import "./bootstrap-skin.css";
