# Git + Hostinger Workflow Instructions

Use this as your repeatable checklist for any project.

## Core Rules
- One project folder = one GitHub repo = one Hostinger Git repository entry.
- Hostinger branch can stay on `main` for a simple solo workflow.
- Folder name should match repo name.
- Keep secrets only in `configs/config.php` (never commit this file).
- Hostinger deploy directory should be the project subfolder in `public_html`, never root.
- Release marker format uses local 24-hour time: `YYYY-MM-DD.HHMM` (no colon).
- Preferred Git workflow: use VS Code Source Control UI when possible; use Terminal as fallback.

## 1) Brand New Project (One-Time Setup)
1. Create/open your project folder locally.
2. Initialize git in that folder:
```bash
cd "/Users/Derek/Library/CloudStorage/Dropbox/DereksProjects/<projectname>"
git init
git branch -M main
```
3. Add a `.gitignore`:
```bash
  printf ".DS_Store\nconfigs/config.php\n.env\n*.log\n" >> .gitignore
```
4. Create `configs/config.example.php` with placeholders (no real credentials).
5. Create a private GitHub repo at https://github.com/new
(empty repo, no README/license/gitignore).
6. Connect local repo to GitHub (replace placeholders):
```bash
git remote add origin git@github.com:Throldahl/<projectname>.git
```
7. First commit and push:
```bash
git add .
git commit -m "chore: initial project setup"
git push -u origin main
```
8. In Hostinger `Advanced > GIT`:
- Add Hostinger SSH key to GitHub access.
- Create one repository entry for this project:
- Repository: `git@github.com:Throldahl/<project-folder-name>.git`
- Branch: `main`
- Directory: `<project-folder-name>` (subfolder only, not root)
- Deploy.
9. In Hostinger, choose to set Auto Deployment

## 2) Start a Work Session (Existing Project)
Preferred in VS Code Source Control:
- Confirm branch is `main` (status bar branch indicator).
- Use Pull/Sync to get latest changes from remote.
- Confirm working tree is clean before starting edits.

Terminal fallback:
```bash
git checkout main
git pull origin main
git status -sb
```

## 3) While You Work
- Edit in VS Code as normal.
- Commit in small logical chunks using VS Code Source Control:
  - Stage files (`+`)
  - Enter commit message
  - Click **Commit**
- Push using VS Code **Sync Changes** / **Push** after commit.

Terminal fallback:
```bash
git add .
git commit -m "feat(scope): short description"
git push origin main
```

## 4) Finish a Work Session
Preferred in VS Code Source Control:
- Ensure current branch is `main`.
- Push latest commits (Sync Changes / Push).
- Confirm no pending unstaged changes unless intentionally left.

Terminal fallback:
```bash
git checkout main
git push origin main
git status -sb
```

Optional but recommended before live deploy: create a release checkpoint tag:
```bash
git tag deploy-YYYY-MM-DD.HHMM
git push origin --tags
```

If you want changes live, deploy in Hostinger:
- Open this project's existing Git entry.
- Confirm branch is `main`.
- Click `Deploy`.
- Smoke test the live URL.

## 5) Rollback if a Deployment Fails
Use git revert on `main`, then redeploy:
```bash
git checkout main
git pull origin main
git log --oneline
git revert <bad_commit_sha>
git push origin main
```

Then click `Deploy` in Hostinger again.

## 6) Return Later for Enhancements
Use the same flow every time:
- Start session: Section 2
- Work + commit: Section 3
- Finish session and optionally deploy: Section 4

## Optional Advanced Workflow (Only if You Want It Later)
You can deploy from immutable `deploy/YYYY-MM-DD.HHMM` branches for stricter release control, but this is optional for solo projects.

If you use advanced branches:
```bash
git checkout main
git pull origin main
git checkout -b deploy/YYYY-MM-DD.HHMM
git push -u origin deploy/YYYY-MM-DD.HHMM
git checkout main
```

Collision rule for tags/branches:
- If a marker for the same minute already exists, do not reuse it.
- Wait one minute and use the next `HHMM`, or choose the next available minute value.

## Safety Checks (Quick)
- VS Code UI checks:
  - Branch shown as `main` in status bar.
  - Source Control view has no pending changes after commit/push (unless intentionally pending).
- Confirm correct remote:
```bash
git remote -v
```
- Confirm `configs/config.php` is not tracked:
```bash
git ls-files configs/config.php
```
Expected: no output.

- Verify release tags:
```bash
git tag --list "deploy-*"
```

- Verify advanced release branches (if used):
```bash
git branch --list "deploy/*"
```
