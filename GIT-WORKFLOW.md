# Private Fork Git Workflow

How this private fork stays in sync with the public IMathAS project while keeping
private-only features out of upstream contributions.

## Remotes

| Remote | URL | Role |
|---|---|---|
| `origin` | `https://github.com/ahtavangar/IMathAS.git` | This private fork — deploy + private features |
| `upstream` | `https://github.com/drlippman/IMathAS.git` | Public project — target for contributions |

## Branches

| Branch | Role |
|---|---|
| `master` | Clean mirror of `upstream/master`. **PR launchpad only — never commit private work here.** |
| `production` | `master` + all private-only features. **This is what the live instance deploys.** |
| `feature/*` | Private features in progress; merged into `production` when ready. |

The `imathas-docker` mount points at `production` (not `master`). Docker doesn't care
which branch is checked out, so switching deploy branches needs no rebuild.
`config.php` and `loginpage.php` stay gitignored per instance.

## The one rule

**Changes flow `master` → `production` only, never the reverse.**

PR branches are cut from `master`, so private code physically cannot reach an upstream
PR. Never merge `production` (or a private feature branch) back into `master`.

```
upstream/master ──► master (clean mirror) ──► PR branches ──► upstream (drlippman)
                       │
                       └──► production (master + private features) ──► live instance
```

## Routine commands

**Pull upstream fixes into the private instance:**
```bash
git checkout master
git merge --ff-only upstream/master
git push origin master
git checkout production
git merge master
git push origin production
```

**Ship a private-only feature:**
```bash
git checkout production
git checkout -b feature/my-thing
# ...build...
git checkout production
git merge feature/my-thing
git push origin production
```

**Contribute something to upstream (must be clean of private code):**
```bash
git checkout master            # NOT production
git checkout -b fix/whatever
# ...build...
git push origin fix/whatever
# open PR from origin/fix/whatever -> drlippman/IMathAS
```
