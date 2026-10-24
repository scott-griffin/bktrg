# CLAUDE.md — Train Rat (bktrg.com)

Train Rat is a 3D browser endless-runner that Scott builds with his son Thomas (a kid — features often arrive as his ideas, write replies and in-game text for him). Several people work on it from **different Claude sessions on different machines at the same time**. This file is the shared contract; follow it exactly.

## What this repo is

- **This repo IS the live site.** The grifflow server serves `main` at https://bktrg.com (HTTPS by Caddy/Let's Encrypt): a systemd timer pulls the server's checkout (`/var/www/trainrat/repo`) every minute, so a push to `main` is live in ~1 minute. There is no staging: merging = shipping. (Hosting moved off GitHub Pages 2026-10-24; the Pages config lingers only as a temporary fallback.)
- `index.html` — the entire game in one file (Three.js scene, Tone.js soundtracks, gameplay, UI, leaderboard client). ~120KB. **This is the merge hot-spot** — see the workflow rules.
- `leaderboard.html` — the full top-100 board page.
- `api/leaderboard.php` — the scoreboard API, served at https://api.bktrg.com straight from the grifflow server's auto-pulled checkout (no separate deploy step). PHP runs as an isolated `trainrat` user that can only touch this repo's checkout and the data dir — keep it that way. Data lives on that server outside the web root (`/var/www/trainrat-data` or `$TRAINRAT_DATA`): `bktrg_scores.json`, the append-only audit log `bktrg_scores_log.jsonl` (NEVER delete or rewrite it — it is the dispute record), `bktrg_ghosts/` (replays, self-garbage-collected), `bktrg_history/` (daily board snapshots, kept a year).
- `rat-styles.html`, `squirrel-styles.html`, `echidna-styles.html`, `rain-drive.html` — design-studio mockup pages. The pattern for proposing art/music/characters: build 3–4 live variants on a page like these, Thomas picks a letter, port the winner into the game.
- `classic.html`, `3d.html`, `train-rat.html`, `subway-rats.html`, `music-styles.html` — **redirects. Never delete or repoint them**; shared links live forever.
- `og.png`, `apple-touch-icon.png` — share card and home-screen icon.

## Working rules for concurrent sessions (the important part)

1. **Never work directly on `main`'s tip blind.** Start every task: `git fetch origin && git merge --ff-only origin/main` (or fresh clone), branch if your tooling wants one.
2. **Push with `git push origin <yourbranch>:main`** (or from main after merging). If the push is rejected, someone else shipped while you worked: `git fetch origin && git merge origin/main`, resolve, re-check, push again. Conflicts in `index.html` are normal; resolve by keeping BOTH features (that is almost always the right answer — two people add different things to the same region).
3. **Syntax-check before every push.** The page dies silently on a script error:
   `sed -n '/<script type="module">/,/<\/script>/p' index.html | sed '1d;$d' > /tmp/check.mjs && node --check /tmp/check.mjs`
   For `api/leaderboard.php`: `php -l`.
4. **Humans are the test rig.** There is no automated browser test; Scott/Rebecca/Thomas play on iPhones and send screenshots. After shipping anything visual, say what to look for. Runtime errors show as a red toast at the bottom of the game; ⚙️ settings has a 🔊 TEST SOUND diagnostic that prints audio-engine state.
5. **Don't ship two big overlapping features at once.** If your task rewrites a subsystem (music engine, biome engine, collision), say so in your thread and keep the change window short. Small additive features merge fine in parallel.
6. **Verify your deploy**: `curl -s https://bktrg.com/ | grep <something-only-your-change-contains>` in a loop; the server pulls on the minute, so allow up to ~1 minute.

## The game's architecture (one file, clear sections)

`index.html` script order: error toast + iOS unmute → Three.js scene (lights, textures, scenery per biome) → character builders (`buildRat`, `buildSquirrel`, `buildEchidna` — `chars`/`hero`/`setChar`) → obstacle/treat builders per biome → particles & power-ups → game state + `reset()` → `spawnWave()` → `update(dt)` (the loop: movement, biomes, gait, collisions, pickups, camera) → ghost replays (record/watch) → `frame()`/`start()`/`gameOver()` → Tone.js soundtracks (5 styles + picker) → leaderboard client (name/rat-code identity, claim flow, auto-save) → settings/help/character UI → input (keys + swipe).

Key mechanics: 3 lanes; jump/duck; duck-squeeze under trains (hold until passed); Night Glider glides (tap mid-air, sink slowly); Rainbow Spike digs (down = underground, max two trains per dig); power-ups 🧲⛑️🚀⭐; treats 🧀25 / 🍕100 / 🍌60 / 🥧70; **levels**: every 20s the biome advances (country → Amazon → Outback → repeat), speed and music tempo reset per level and each level starts a notch faster; `?level=N` URL starts at that level (the family test door).

## Identity & leaderboard model

- A player = name + **rat code** (8-char token in localStorage, shown in ⚙️). First device to score under a name owns it; writes need the token. Same name+code on another device = same player.
- The server has: `claim` (lost-code recovery by connection history), `rename` (row follows, taken names 409), `flag` (country), **adoptions map** (admin list folding duplicate names into the real one — the device self-heals via the `become` response field), ghosts (best-run replay per board player, GC'd when they leave the board).
- **Never expose tokens via GET.** `publicRows()` is the only shape the board returns.
- Admin fixes (merging duplicate players, setting flags) are done by editing the data files on the API server directly — ALWAYS append an `admin-*` line to the audit log saying what and why.

## Hard-won gotchas (each cost real debugging — do not relearn)

- **Tone.js `Sequence` subdivides nested arrays**: an event like `['A1','8n']` becomes two sub-events of garbage. Note tuples must be OBJECTS (`{n,dur,v}`); chord arrays must be wrapped (`{c:[...]}`). This once made entire tracks silent/mush.
- **iPhone silent switch mutes WebAudio.** The game plays a looping silent `<audio>` ("unmuteLoop") on the Run tap to force media-playback mode. Don't remove it.
- **Three.js `setPixelRatio` without `setSize` balloons canvases on retina** (every iPhone/iPad). Fixed-size canvases need both calls.
- **Phone-width layout**: the HUD is nowrap with a <600px media query; test every overlay at iPhone width — Scott's screenshots have caught two layout collisions.
- Pages/iMessage cache: shared-link previews come from `og.png` + OG tags; iMessage caches per conversation (resend in a new thread to refresh).
- Audio/music/samples come from CDNs (jsDelivr Three/Tone, tonejs.github.io piano). The game must keep degrading gracefully (silent but playable) when they're unreachable — guard with `typeof Tone === 'undefined'`, `loadedOrTimeout()`.
- Keyboard handler must ignore `e.target.tagName === 'INPUT'`, and the window touch handler must ignore taps inside panels (see the `closest(...)` guard) — new overlays must be added to that guard.

## Who works where

- **Grifflow server session**: primary development + hosts the API and its data.
- **Rebecca's and friends' Claude apps**: game features via this repo (GitHub collaborators).
- **The BrassTix VM session**: emergency access only (it built v1; its API copy at dev.brasstix.com/bktrg/ becomes a forwarder during the API migration, then retires).
- Open with the owner before touching: the API data files (grifflow session owns), this file's rules (Scott owns).

## Backlog (agreed, unbuilt)

1. **Split `index.html` into ES modules** (state/world/characters/audio/net/ui) — the single file is the merge bottleneck for concurrent work. Do it as one dedicated change window with Scott play-testing immediately after.
2. Ghost **racing** (translucent ghost runs alongside your live run; watch mode exists).
3. More biomes (one palette entry + props each), more characters (mockup-page pattern).
4. API migration completion: forwarder on the old BrassTix endpoint, then retire it and move the data history archive.
5. **Retire GitHub Pages** (added 2026-10-24): hosting moved to the grifflow server; Pages still has the bktrg.com custom domain configured as a fallback. Once the cutover has soaked a few days, remove the custom domain / disable Pages in the repo settings so GitHub stops trying to serve the site, and delete the `CNAME` file.
