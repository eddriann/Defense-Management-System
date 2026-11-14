# Defense Management System

A PHP and MySQL application for managing student projects, manuscripts, and
defense schedules.

## Upload this project to GitHub

The `.gitignore` file excludes local environment files, Docker setup files, and
uploaded manuscripts in `storage/manuscripts/`. Review what Git will include
before committing.

In PowerShell, from the project directory:

```powershell
git init
git add .
git status
git commit -m "Initial project upload"
git branch -M main
git remote add origin https://github.com/YOUR-USERNAME/YOUR-REPOSITORY.git
git push -u origin main
```

Create an empty repository on GitHub first, then replace the URL above with its
HTTPS URL. If `origin` is already configured, skip `git remote add origin`.

Before `git commit`, check `git status` and confirm `.env` files, Docker setup
files, and manuscript PDFs are not staged. If any of those files were committed
previously, `.gitignore` alone will not remove them from Git history; untrack
them with `git rm --cached <file>` and rotate any credentials that were exposed.

> **Before public deployment:** review the default accounts and sample data in
> `database/schema.sql`. Do not use shared or default credentials for a live
> system.
