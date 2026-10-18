# unreleased-payloads

已定标、但**尚未通过真机验证**的载荷暂存于此，不打进 Release APK 的 `assets/`。

## 当前内容

| 文件 | 说明 | 状态 |
|---|---|---|
| `cve-2026-43499-dze2-xaa` | 美版 S926U1（e2q / XAA）DZE2 独立载荷，104,128 B；定标见 `docs/dze2-xaa-target-complete.md`（issue #10） | 待 S926U1 真机实测 |

## 恢复步骤（真机验证通过后）

1. `git mv app/unreleased-payloads/<文件> app/src/main/assets/cve-2026-43499-dze2-xaa`
2. 在 `RootViewModel.kt` 中取消 `S926U1DZE2(...)` 条目的注释（见该条目上方说明），
   并按需把 `tested` 置为 `true`；
3. （可选）同步 `server/payloads.json` 条目；
4. 构建发布版本并验证 APK 内 `assets/cve-2026-43499-dze2-xaa` 存在。
