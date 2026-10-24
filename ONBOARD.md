# ONBOARD.md — for a new collaborator's Claude

A human just joined the Train Rat team and pointed you here. Do their setup for them, verify everything works, then hand control back to them. Keep it warm and simple — they may be new to all of this.

## Who you're working with

Train Rat (https://bktrg.com) is a 3D browser game built by Scott and his young son Thomas, played daily by family on three continents. The person you're setting up is family or a friend — a creative collaborator, not necessarily a programmer. Plain words, no jargon, nothing to install.

## Setup steps (do these now, in order)

1. **Read `CLAUDE.md` in this repo, fully.** It is the team contract: how pushes deploy, how to avoid colliding with the other Claude sessions, and the list of hard-won gotchas. Everything there binds you.
2. **Verify write access with the welcome ritual**: add one line to `CONTRIBUTORS.md` (create it if missing) — their name or nickname and today's date — commit with a friendly message, and push to `main` following CLAUDE.md's merge rules. If the push succeeds, their setup is complete; tell them so, with the line you added visible at https://github.com/scott-griffin/bktrg/blob/main/CONTRIBUTORS.md
   - If the push is **rejected for permissions**: their GitHub collaborator invite isn't accepted yet — tell them to find the email from GitHub ("invited you to scott-griffin/bktrg") and click Accept, then try again.
3. **Confirm the game loads for them**: have them open https://bktrg.com on their phone, pick a character and play one run. Their best score auto-saves under their name with a secret "rat code" (in ⚙️ settings) — suggest they screenshot that code once, it's their identity.

## Then: their first feature

Ask what they'd like to change or add, and offer these gentle starters (all are the established pattern — small, self-contained, kid-delighting):

- **A new soundtrack** — compose a new Tone.js style and add it to the in-game picker (see `MUSIC_STYLES` in index.html; mind the CLAUDE.md gotcha about Sequence arrays).
- **A new character** — build a mockup page with 3–4 design variants (copy `squirrel-styles.html` as the template), let the kids pick a letter, then port the winner into `chars`.
- **A new land** — the biome engine is a palette table (`PAL`) plus props; a snow mountain or night city is one entry, two obstacle skins, and a treat.
- **A new treat or power-up** — smallest possible first ship.

Whatever they pick: build it, push it, tell them "refresh bktrg.com in one minute," and let them see their own work live on the family game. That moment is the whole point of making this easy.

## House rules worth repeating out loud to them

- Everything pushed to `main` is instantly live for the whole family — including Thomas. Ship kindly.
- They can't really break anything permanently: git history keeps every version, and the Claude sessions know how to roll back.
- The leaderboard data and its audit history live on the API server, not in this repo — game code here, scores there.
