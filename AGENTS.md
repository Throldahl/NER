## Web dev defaults (Derek's standards)
- Stack: HTML, CSS, JavaScript (jQuery specifically), PHP 8.3, MySQL.
- Hosting target: Hostinger Single Web Hosting plan; PHP scripts talk to MySQL.
- Other JavaScript libraries may be used if they'll provide significant value.
- File layout:
  - Keep `index.html` (or main .html), `script.js`, and `styles.css` in the project root.
  - Cache-bust any changed static assets at deploy time (e.g., update file query strings or hashed filenames) so users get new code on normal page refresh; version numbers do not need to match across files.
  - Store secrets/credentials only in `configs/config.php` (never in JS/HTML).
- JS in HTML:
  - Script tags belong in `<head>` and must use `defer` as needed.
- CSS:
  - Prevent scroll bounce/rubber-band by setting `overscroll-behavior: none` on the root scrolling context (`html, body`).
  - Never use `!important`.
- Database:
  - New tables must be prefixed with `projectname_` to avoid conflicts (e.g., `altcade_users` exists).
- Prefer minimal, high-confidence changes and keep the site modern + user-friendly.

## Git + Deployment workflow (reusable across projects)
- This project uses GitHub + Hostinger Git deployment.
- Preferred Git tool: VS Code Source Control UI for day-to-day pull/commit/push/branch actions when possible.
- Project identity rule:
  - The current folder name is the project name.
  - The project name is also the repository name and Hostinger deploy subfolder name.
- Derive values from current directory:
  - `PROJECT_NAME=$(basename "$PWD")`
  - GitHub SSH remote format: `git@github.com:<github-username>/${PROJECT_NAME}.git`
  - Hostinger deploy directory: `${PROJECT_NAME}` (subfolder under `public_html`, never root).
- Branch model:
  - `main` for ongoing development.
  - Immutable deploy branches: `deploy/YYYY-MM-DD.HHMM` for production releases.
- Deploy process:
  1. Commit/push to `main` (prefer VS Code UI).
  2. Create/push a new `deploy/YYYY-MM-DD.HHMM` branch from `main`.
  3. In Hostinger `Advanced > GIT`, set branch to that deploy branch and click Deploy.
- Terminal fallback:
  - Use terminal commands when VS Code UI is unavailable or for troubleshooting/verification.
- Rollback process:
  - In Hostinger, switch back to previous deploy branch and redeploy.
- Collision rule:
  - If a tag/branch marker for the same minute already exists, do not reuse it.
  - Wait one minute and use the next `HHMM`, or choose the next available minute value.
- Scope protection:
  - Never deploy to `public_html` root.
  - Only deploy to this project's own subfolder.
  - Never modify sibling project folders unless explicitly requested.
- Secrets:
  - Keep secrets only in `configs/config.php` (server-local, never commit).
  - Keep safe placeholders in `configs/config.example.php`.

## Test cases for this workflow
1. In any copied project folder, `PROJECT_NAME=$(basename "$PWD")` matches repo name.
2. `git remote -v` matches `git@github.com:<username>/<PROJECT_NAME>.git`.
3. Hostinger Git deploy directory equals that same project name.
4. Deploying one project does not change sibling folders.

## Assumptions
- You keep using one repo per project.
- Folder name and repo name remain identical.
- Hostinger deployment remains scoped to each project subfolder.
