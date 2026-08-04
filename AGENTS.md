# AGENTS.md — fast-kalk

Status: active L2 adapter (Typ A). WordPress lead widget.

## Safety capsule

- Code and local proof in this repo beat historical docs.
- Default target is **local**, not VPS/production.
- No production mutation without explicit operator order and dedicated proof.
- Do not write other repos without explicit `ai_os_task` scope expansion.
- OfferDTO ownership is `kalk-top`; document rendering is `top-instal-generator`.
- Do not declare `done` without the Gate A appropriate to the changed PHP/JS surface.
- When opened inside `top-code workspace`, root `../AGENTS.md` also applies.
- Missing root does not waive these local rules.

## Role

Leadgen WordPress UI. Delegates calculation to `kalk-top`, PDF to `top-instal-generator`, and may call Node B lead/registry hooks when configured.

## Owns / Must not

| Owns | Must not |
|------|----------|
| Lead widget flow and its WP surface | BufferEngine / OZC / OfferDTO ownership |
| Mapping client inputs into service contracts | PDF mapping inside the generator |
| | Full Cieplo pipeline |

## Read first

1. This file
2. Root `../AGENTS.md` when available
3. `README.md`
4. OfferDTO / calculate contracts in `kalk-top`; generate API in `top-instal-generator`

## Write and task scope

- Pass a full OfferDTO to the generator per the canonical contract — do not substitute a bare `result_summary` when the contract requires OfferDTO.
- Do not confuse Daszek runtime with calculator runtime; read current ports from local config/scripts.
- Scope via `fast-kalk:<path>`.

## Gate A

**Minimum on PHP edits:**

```powershell
php -l path\to\changed-file.php
```

Run `php -l` on each PHP file you changed.

**Closeout / integration when lead→kalk→document path is in scope:**

```powershell
php scripts/e2e-scenarios-smoke.php
```

(Requires the local calculator/runtime path this smoke expects — see script and README; do not treat lint alone as integration proof.)

## Cross-repo contract changes

1. Owners: `kalk-top` (OfferDTO), `top-instal-generator` (documents), `gmail-agent` (registry hooks).
2. Change owner + tests first.
3. Then this consumer.
4. Run Gate A for each touched repo.
5. No client-only guessing.
6. Knowledge docs alone do not change runtime.

## Anti-goals

- Re-implementing HVAC engines here
- Prod deploy as default (company suspended / local-only default)
- Claiming E2E proof from `php -l` alone

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **fast-kalk** (469 symbols, 1175 relationships, 40 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/fast-kalk/context` | Codebase overview, check index freshness |
| `gitnexus://repo/fast-kalk/clusters` | All functional areas |
| `gitnexus://repo/fast-kalk/processes` | All execution flows |
| `gitnexus://repo/fast-kalk/process/{name}` | Step-by-step execution trace |

## Cross-Repo Groups

This repository is listed under GitNexus **group(s): topinstal-workspace** (see `~/.gitnexus/groups/`). For cross-repo analysis, use MCP tools `impact`, `query`, and `context` with `repo` set to `@<groupName>` or `@<groupName>/<memberPath>` (paths match keys in that group’s `group.yaml`). Use `group_list` / `group_sync` for membership and sync. From the terminal: `npx gitnexus group list`, `npx gitnexus group sync <name>`, `npx gitnexus group impact <name> --target <symbol> --repo <group-path>`.

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
