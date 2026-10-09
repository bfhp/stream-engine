# GitHub Wiki publication

The Markdown files in `docs/wiki/` are the canonical documentation source.
The `Publish Wiki` GitHub Actions workflow validates them and synchronizes the
complete directory to the repository's GitHub Wiki after a change reaches
`main`.

Do not edit published Wiki pages directly. A later workflow run will replace
direct edits with the reviewed repository source.

## One-time Wiki initialization

GitHub does not create `bfhp/stream-engine.wiki.git` until the first Wiki page
has been created.

1. Open `https://github.com/bfhp/stream-engine/settings`.
2. Under **Features**, enable **Wikis** if it is disabled.
3. Open `https://github.com/bfhp/stream-engine/wiki`.
4. Choose **Create the first page**.
5. Name the page `Home`, enter a temporary sentence, and save it.
6. Confirm that the Wiki Git repository now exists:

   ```bash
   git ls-remote https://github.com/bfhp/stream-engine.wiki.git
   ```

The first successful publication replaces that temporary page with
`docs/wiki/Home.md` and publishes the remaining managed pages.

## First publication

Merge the documentation and workflow into `main`. A change under `docs/wiki/`
starts publication automatically. To start or repeat it manually:

1. Open the repository's **Actions** tab.
2. Select **Publish Wiki**.
3. Choose **Run workflow** on `main`.
4. Open the completed run and confirm that **Validate and publish** succeeded.
5. Open the Wiki and verify `Home`, the sidebar, and all linked pages.

The workflow uses a concurrency group, so two merges cannot publish over one
another. It removes published pages that are no longer present in `docs/wiki/`
and records the source commit SHA in every Wiki publication commit.

## Authentication

The workflow first tries the built-in `GITHUB_TOKEN` with `contents: write`.
For a personal public repository this may be sufficient, depending on the
repository's Actions policy.

Check **Settings → Actions → General → Workflow permissions** if the push is
denied. Permit read and write access for workflows when the repository policy
allows it, then run **Publish Wiki** again.

### `WIKI_TOKEN` fallback

If organization or repository policy prevents the built-in token from pushing
to the Wiki, add a dedicated secret:

1. Create a GitHub credential owned by the maintainer or a project automation
   identity. For a public repository, a classic personal access token with the
   `public_repo` scope is the broadly compatible fallback. Use `repo` only if
   the repository is private. Set the shortest practical expiration.
2. Open **Settings → Secrets and variables → Actions** in
   `bfhp/stream-engine`.
3. Create a repository secret named `WIKI_TOKEN` containing the token.
4. Run **Publish Wiki** again.
5. Record the token owner and expiry in the project's private maintenance
   records and rotate it before expiry.

When `WIKI_TOKEN` exists, the workflow uses it instead of `GITHUB_TOKEN`.
Secrets are never used by a pull-request workflow, and the publication job does
not print the selected token.

For a long-lived organization project, a repository-scoped GitHub App is
preferable to a maintainer's personal token. The workflow interface can remain
the same if the App's short-lived installation token is supplied to the
publication step.

## Manual emergency publication

Use this only when GitHub Actions is unavailable. It requires an authenticated
Git setup with permission to update the Wiki:

```bash
wiki_directory="$(mktemp -d)"
git clone git@github.com:bfhp/stream-engine.wiki.git "$wiki_directory"
find "$wiki_directory" -mindepth 1 -maxdepth 1 \
  ! -name .git -exec rm -rf -- {} +
cp -a docs/wiki/. "$wiki_directory/"
git -C "$wiki_directory" add --all
git -C "$wiki_directory" commit -m "Publish documentation manually"
git -C "$wiki_directory" push
```

The `find` command intentionally deletes every published file except the Wiki
Git metadata because `docs/wiki/` is the complete source of truth. Inspect the
temporary checkout before committing when recovering from an unusual failure.
Remove the temporary directory after publication.

## Troubleshooting

| Failure | Resolution |
| --- | --- |
| `Repository not found` while cloning | Initialize the first Wiki page and confirm that Wikis are enabled. The same message can also mean the selected token has no access. |
| HTTP 403 while pushing | Grant workflow write permission or configure `WIKI_TOKEN`. |
| Validation reports a missing page | Add the target `.md` file or correct the relative link. Internal Wiki links must use flat `.md` filenames. |
| Validation reports an unreachable page | Link the page from `Home.md`, `_Sidebar.md`, or another reachable page. |
| Wiki shows an unexpected direct edit | Change the canonical file under `docs/wiki/` and republish; do not preserve unreviewed Wiki-only content. |
| Publication ran for an older commit | Wait for the serialized workflow runs to finish. The latest `main` run publishes last. |

Run the same source validation locally with:

```bash
php bin/check-wiki-docs.php
```
