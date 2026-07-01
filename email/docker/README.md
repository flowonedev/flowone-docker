# FlowOne Docker workflow

Three loops move code from a laptop to a live server. Nobody ever runs `docker`
by hand on a production box -- Fleet Manager is the only thing that touches a
server.

```
LOCAL (test)  ->  GIT + CI (publish)  ->  FLEET (deploy)
```

## 1. Local loop -- test your change

Everything runs from `email/docker/`. `docker-compose.override.yml` forces the
app images to be built from your working copy, so you always test the code
you're editing (it never pulls GHCR).

```bash
cd email/docker
./dev-up.sh              # build app tier + start bridge services (mail skipped)
./dev-up.sh --logs       # follow web logs
./dev-up.sh --down       # tear it down
```

- The host-networked `mail` pod is skipped on Windows/macOS. On a Linux box you
  can include it with `./dev-up.sh --with-mail`.
- `.env` is created from `.env.example` on first run -- fill in the `change-me`
  values before the stack will work.

## 2. Publish loop -- CI builds + pushes images (hands-free)

You do **not** build release images by hand. On every push to `main` (and on
`v*` tags), GitHub Actions (`.github/workflows/build-images.yml`) builds all four
images and pushes them to GHCR:

| Image | From |
| --- | --- |
| `ghcr.io/flowonedev/flowone-web` | `email/docker/web/Dockerfile` |
| `ghcr.io/flowonedev/flowone-collab` | `email/docker/collab/Dockerfile` |
| `ghcr.io/flowonedev/flowone-mailsync` | `email/docker/mailsync/Dockerfile` |
| `ghcr.io/flowonedev/flowone-mail` | `email/docker/mail/Dockerfile` |

Each image is tagged twice:

- `:latest` (moving; only on the default branch)
- `:<short-git-sha>` (immutable; one per commit -- this is what Fleet pins)

CI authenticates with the built-in `GITHUB_TOKEN`, so there are no secrets to
manage for publishing.

> Manual fallback: `./build-and-push.sh --push` still works from a machine that
> has run `docker login ghcr.io`. Prefer letting CI do it.

## 3. Deploy loop -- Fleet rolls a chosen server (one click)

From the Fleet dashboard, on a server's detail page, use the **Deploy** menu:

- **Docker Provision** -- first-time full stack: renders the per-host `.env`,
  ships `docker-compose.yml`, pulls the images, brings the stack up, obtains SSL
  and seeds a default mailbox.
- **Docker Update** -- roll the app tier (web / collab / mailsync) to a chosen
  image version. Pick the services + a tag (`latest`, a git sha, or `vX.Y.Z`);
  Fleet re-renders `.env` with that tag, logs the box in to GHCR, then
  `docker compose pull` + `up -d --no-deps` only the picked services.

The version currently live on a server is shown next to its IP and stored in
`servers.deployed_image_tag`.

### Private images

The GHCR packages are private, so Fleet logs each target in before pulling. Set
these on the master in `fleet/api/config.local.php`:

```php
'docker' => [
    'registry_user'  => 'a-github-username-or-bot',
    'registry_token' => 'a-PAT-with-read:packages',
],
```

Leave them empty to treat the images as public (Fleet then skips `docker login`).
