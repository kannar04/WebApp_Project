# Defect register

Date: 2026-10-09. CLOSED means reproduced defect repaired and exercised by the specified local regression, not production certification. Requirements not implemented are listed separately in the 94-group matrix.

| ID | Severity | Features | Reproduction / root cause | Expected / repair | Regression evidence | Status |
|---|---|---|---|---|---|---|
| QA-001 | P1 | H05 | Host form only uploads, lacks existing photo management | Own reorder/reference removal; complete snapshot, duplicate/foreign/stale guard, preserve physical files, re-moderate | E6 HTTP+SQL snapshot/rollback; E7 form | CLOSED |
| QA-002 | P1 | H06 | Host has no delete action | Soft delete only after no pending/confirmed booking; preserve history | E6 pending rejection/HTTP own delete and unchanged booking history | CLOSED |
| QA-003 | P1 | H07,G05,A16 | Only explicitly configured dates shown | Full month default-open + blocked/pending/confirmed/past; private notes hidden | E3/E6 state changes and E7 actual next-month clicks | CLOSED |
| QA-004 | P1 | H09,G09,A07 | No individual booking detail/lookup | Participant/Admin guard, immutable nights/policy/refund/events and search | E6 own details/404/foreign rejection/search; E7 detail 375/1440 | CLOSED |
| QA-005 | P1 | A04 | Pending public projection hides Admin photo/amenity detail | Separate SQL-guarded private preview; keep Guest public denial | E6 pending photos+amenity count/Admin 200/Guest 403; E7 | CLOSED |
| QA-006 | P2 | H20,G23 | Host list discloses Guest phone before confirmation | SQL NULL until confirmed/completed; authorized participant contact | E6 pending NULL/confirmed contact; E7 tel detail | CLOSED |
| QA-007 | P1 | A07 | New booking search returns SQL collation error 1267 | Explicit utf8mb4_unicode_ci on ID cast, consistent with text predicates | E6 actual Admin HTTP search and persistence | CLOSED |
| QA-008 | P1 | test safety | Old smoke script ignores BaseUrl, hits default shared server instead of owned server | Explicit param/environment fallback; runner passes exact base URL | Full isolated regression E5 | CLOSED |
| QA-009 | P2 | SEARCH | Advanced controls overflow at 375px | Min-width 0/100%-width controls and wrapping grid | E7 375/768/1024/1440; actual screenshot | CLOSED |
| QA-010 | P2 | Admin UI | Additional long-name test users make mobile table expand document | Constrain surface/table container; horizontal table scroll and wrap action links | E7 document overflow=false and positive table scrollLeft | CLOSED |
| QA-011 | P1 | H05 | Reordered pending photos inaccessible through approved-only public SP | Separate owner/Admin photos routine, preserve public guard | E6 reorder/private snapshot/rollback and public denial | CLOSED |
| QA-012 | P2 | A04 | Preview next-month link reloads current month | Parse/validate month via same Calendar helper | E7 next-month click checks date prefix | CLOSED |
| QA-013 | P1 | H13,G11 | Password mutations leave existing login sessions valid unless version checked | Session fingerprint revalidated; change/reset revokes unused tokens | E6 wrong/old/new password and independent stale session tests | CLOSED |
| QA-014 | dependency | H13,G11 | No configured mail transport; user confirmed missing service | Disabled/request explanation “đang chờ cấu hình”; configure/test genuine delivery privately | Token/change flows PASS; mail deliberately not simulated | BLOCKED |
| QA-015 | verification | G15,G19 | Source/URL exists but external map/native share not end-to-end exercised | Keep NOT_VERIFIED, manual external verification required | No unexecuted PASS claim | NOT_VERIFIED |

No baseline PHP syntax error was reproduced; all 86 PHP files lint clean. No leftover reproduced failure in the final executed suite. Full-production, external-service, load and all-accessibility acceptance is still open.
