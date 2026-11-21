# PRIME Task Hub — Installed Snapshot Manifest

**Snapshot:** 2026-11-21 12:43 +07 — installed cutover; final independent QA underway. Manager’s QA-PASS correction was installed after hash verification. This timestamp is a point-in-time record, not a claim about future QA status.  
**Installed root:** `C:\laragon\www\prime-google\Docs\prime-task-hub`

## Contents

- 3 hub root pages: `PRIME_TASK_INDEX.md`, `PRIME_ACTIVE_WORK.md`, `PROJECT_STATE.md`.
- 1 archive README: `archive/README.md`.
- 3 byte-identical historical originals: `archive/PRIME_MASTER_BACKLOG.md`, `archive/PRIME_PARALLEL_EXECUTION_PLAN.md`, `archive/PROJECT_STATE.md`.
- 28 task files: 22 parents and 6 separate-acceptance children (`TASK-07.01/.02/.03`, `TASK-19.01/.02/.03`).
- 1 manifest: this file; its own SHA256 is reported externally, never self-embedded.

## Workflow and validation history

- The staging candidate at `C:\Users\USER\AppData\Local\Temp\prime-task-hub-staging-2026-11-20-2031` received QA status READY.
- On 2026-11-21 the correction candidate at `C:\Users\USER\AppData\Local\Temp\prime-task-hub-staging-2026-11-21-qa-correction-DuYJjK` passed QA; Manager installed its approved two-file correction after hash verification.
- Per the PANE QA report at snapshot time, independent fidelity comparison of all 22 parent TASKs and their children is complete; this hub has not independently verified QA's comparison artifacts. The separate policy digest remains PARTIAL; the archive preserves detailed source evidence. Do not conflate these results.
- As of the snapshot timestamp above, PANE FULLSTACK-B has completed routing edits and handed off; PANE QA is conducting final review. No final QA PASS is claimed.
- The hub contains exactly 22 parent tasks, 6 children, and 22 index parent rows.
- Relative links/anchors use GitHub slug behavior. Archive originals remain byte-identical.
- No source application, database, runtime, or Git history was changed; this cutover updates documentation routing/state only.
- Temporal Resume remains absent from `main`; it exists only in open draft PR #7. PR #8 remains draft.
- D1–D5 are recorded in open draft PR #7, not merged/main. Real InnoDB serialization is **OPEN / NOT VERIFIED**.

## Current handoff limits

- Snapshot records QA underway; it does not forecast the final verdict.
- Policy digest remains PARTIAL; detailed evidence is preserved in the archive.
- Current ownership and point-in-time handoff state are in [Active Work](PRIME_ACTIVE_WORK.md).

## SHA256 per-file manifest (all files except this manifest; sorted by relative path)

| Relative path | Bytes | SHA256 |
|---|---:|---|
| `PRIME_ACTIVE_WORK.md` | 2558 | `241159916a6c9ca4ba2b8efe5783080a827ee29bc24a08e3401a10e8ad25e6c1` |
| `PRIME_TASK_INDEX.md` | 5929 | `b82f9f27a9304688cd79de0f91e0f92fad1e88cdf2c09259fa799d0778a319ec` |
| `PROJECT_STATE.md` | 8251 | `380a762c2f594b2d6b271aa91888fad463ab575b7ae57e3bf59bcf6c33221e53` |
| `archive/PRIME_MASTER_BACKLOG.md` | 94416 | `0a5630bf15180e03b666764e24fb59ae18b3c88a5edad2b809394da7c806d78b` |
| `archive/PRIME_PARALLEL_EXECUTION_PLAN.md` | 86878 | `d43417fc7f053e153b94201174e94162cc10acc07271dcdd78fe847ba2638224` |
| `archive/PROJECT_STATE.md` | 62705 | `b201147b6641e182af095edf7d461f5fadd689c0a6c1afcf4886dec0e9d39eb3` |
| `archive/README.md` | 1056 | `587c1531b7a2e824d97a819518265a59cef164e8e3157a0ce7bd3d5f5acc3617` |
| `tasks/TASK-01.md` | 2409 | `3c44b3207eeac667a8d1b6aec969511ed42b46adef98c873e85cdbb535b8f63b` |
| `tasks/TASK-02.md` | 1495 | `a83bd6fa40bfea426646a000e0bf3be0dda57c2413b89a8d695af69dcc18685e` |
| `tasks/TASK-03.md` | 1738 | `66fdb9bffb17bdc06d8de14539755472c209baf02183fcabdc43ea74cc16f8b8` |
| `tasks/TASK-04.md` | 1204 | `aa05725506840456014deae5c121658379c4762a2a48a514d5cab05edfd67cf2` |
| `tasks/TASK-05.md` | 1524 | `a12d915a14db114be7445ad65818384b0f3acc96721e2071cb6e93e67ff4f43a` |
| `tasks/TASK-06.md` | 1680 | `84b0ec6cfca2b567e2a0b2daa65149faa5f9d99d95c1f10deda6cdfceeda9330` |
| `tasks/TASK-07.01.md` | 1638 | `72271ee2d21436d69d403b24b21d57eba5b344c1fc4b4f91deea0fd1e889fc10` |
| `tasks/TASK-07.02.md` | 1250 | `ce7486fe34707df3187f45ea7806a7fc09d62ee39ed1a4f99451c97fc04803f4` |
| `tasks/TASK-07.03.md` | 1530 | `e4b5c77931be556c32a499fcb20799fd4c26edd22540d3f805192be12c8a60c3` |
| `tasks/TASK-07.md` | 2512 | `607bb97ee433c69533f8831da281460ea3fd0cb5acf42f5deb9fd2a5002aa457` |
| `tasks/TASK-08.md` | 1218 | `6d3c2218e1afc3b6d23d59a248781ed9421b12f5173d5782103302bc1c1e2ac4` |
| `tasks/TASK-09.md` | 988 | `0e86ad305b4220fd65d48d1644e28009a2b80947b5d6e6776231c90ccc12abdb` |
| `tasks/TASK-10.md` | 1189 | `17d81766cc72a684ff43ac7d42f69a8560041f33a1680c7fc366d11b6c2cd90c` |
| `tasks/TASK-11.md` | 966 | `31041696ddcc353eada821bb8c63e0a7366f499dbc9f3fd41ff18b22c7c49e0c` |
| `tasks/TASK-12.md` | 925 | `6ba7e7b9caee6c94d7f320d2bed2816d3b2ef8c7290316ac5a1052fba47882e1` |
| `tasks/TASK-13.md` | 1519 | `a8365c96f61f8507325088a91cbbc8c35cb1ed52a0cb165a3e4fd1d6c86d0b6a` |
| `tasks/TASK-14.md` | 1326 | `f822cd254d93524197e247d700dc30fdfdb5c737c8ce3aa2f295fd4afbf57816` |
| `tasks/TASK-15.md` | 1123 | `f64c69730baaefa04deb0b67c2ecc3ba3c9444c56f151c2c92699cef34571527` |
| `tasks/TASK-16.md` | 1206 | `41e14ddf8e09af0529246f3e0f5d8cf64721d7da9848e038f832e3d5b9e188da` |
| `tasks/TASK-17.md` | 1769 | `50de46c89ee8e721e6b7c68d99c2a8dfa33352b33a779d8199475aafff22d356` |
| `tasks/TASK-18.md` | 1110 | `b2bb2e4fea9002f2cd09c3fdacb893fc1cf1a983882045a286d7dcfd2beb6468` |
| `tasks/TASK-19.01.md` | 814 | `d26d0e02a59d49aba3ec651ff10e253edc8ad9b97476e418548cd23d7481543a` |
| `tasks/TASK-19.02.md` | 884 | `84f8d3b10c3084b40b395e818d5352ea47e057df4c38211538259b5d00213f81` |
| `tasks/TASK-19.03.md` | 2430 | `8e246748d7c7fe660b7526e42598aac9fcbf02d6cb0951b4821bcf1530021b4f` |
| `tasks/TASK-19.md` | 2199 | `4712d79d464ca15bb7846c350440ed9b8d437f1bde71185e5c4fcf8fe969ef6c` |
| `tasks/TASK-20.md` | 1248 | `a328b5780756c5bed482f873de712d6049597ca1b07a13cffd4efe8a68a0f9ac` |
| `tasks/TASK-21.md` | 1645 | `4b7071f7caf093f7a9fe87806abe9c78b8d5ddb5089c3aec5f260c6cf9281914` |
| `tasks/TASK-22.md` | 1228 | `2728ca3127bac289ef3af286c79d0fc3f0a72e766a1d02ffa65bb36511ed530b` |

**Aggregate (excluding this manifest):** `925ce35460c721f6e89eeb6287a527ebf50cffb3a7a6dd17316335f5f7ddd129` — SHA256 of the sorted `sha256  bytes  relative-path` rows for the 35 `.md` files listed in this table. The manifest file itself is deliberately excluded (a file cannot hash its own final bytes); its SHA256 is reported separately in the freeze report.
