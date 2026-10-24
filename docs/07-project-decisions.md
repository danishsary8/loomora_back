# Loomora: Project Setup Decisions (v0.1)

| # | Decision |
|---|---|
| S1 | Two repos: backend github.com/danishsary8/loomora_back, frontend github.com/danishsary8/Loomora_front. Both stay public, so a secret scan is task 1. Old code moves to legacy/ on branch v2-rebuild |
| S2 | docs/ lives in both repos. The back repo is the source of truth; docs/api.md is the contract between them |
| S3 | Stack: React + Vite + Tailwind + TypeScript (front, Vercel); Laravel + PostgreSQL (back, Render service "loomora-api"); Neon DB in a US region, wiped fresh |
| S4 | Free tiers only. Free subdomains; domain bought later |
| S5 | One branch per big feature; PRs auto-merged when tests pass |
| S6 | No deadline. Pause at Claude Code usage limit and resume after reset |
| S7 | Auth: email + Google (same Google client ID, NEW secret, new redirect URIs). Facebook dropped. Email verification + password reset via Brevo |
| S8 | Sign-up survey (skippable): display name, biggest goal (be consistent / manage time / finish tasks / other), how did you hear about Loomora |
| S9 | Time zone: per user |
| S10 | No human testers: automated Playwright end-to-end tests |
| S11 | Legal pages included. English now, Khmer in P12. README with screenshots, API doc, live link |
| S12 | Drop page-view analytics endpoint and the Faith category |
| S13 | Feedback: stored in DB + emailed to Danish |
| S14 | Delete account: confirm, then delete everything immediately |
| S15 | Branding chosen by planner with ui-ux-pro-max (neo-brutalism, cream #F5EDE6, script headings, 6 category colors) |
| S16 | First task: security cleanup (secret scan, remove mail-test endpoints, rotate Google secret) |
| S17 | Danish already has Vercel, Neon and Render. Brevo account still needed |
| S18 | NOTE (style, to resolve in B1): Danish added 'Neo-skeuomorphism / retro-digital hybrid' as a style idea. Current decision remains neo-brutalism until B1 compares them |
