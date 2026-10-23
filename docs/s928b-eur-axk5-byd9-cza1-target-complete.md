# SM-S928B 欧版 S24 Ultra（e3q）AXK5 / BYD9 / CZA1 三代固件批量定标报告

> 定标日期：2026-10-24
> 状态：**自动定标完成，三份载荷均编译通过，待真机验证**（随本次 3.6.0 beta 携带，`tested=false`）
> 目标：`fusion-s24u` → `src/targets/{e3q-S928BXXU4AXK5, e3q-S928BXXU4BYD9, e3q-S928BXXS4CZA1}/`
> 素材：`/home/nt/下载/s24uboot.img`（RAR5，内含三代 boot）→ 重命名归档为
>   `devices_ports/eur_oneui6.1_axk5_boot.img`、`eur_oneui7.0_byd9_boot.img`、`eur_oneui8.0_cza1_boot.img`
>   （均为 Samsung 签名 `SM-S928B_EUR_XX_QKEY2`）
> 触发：社区反馈 S928B 欧版机器上用现有 S9280/港版载荷失败；S928B 与 S9280 是**不同机型**，
>   即便构建号相同也不能共用载荷。本次一次补齐欧版 S24U 的 One UI 6.1 / 7.0 / 8.0 三代。

---

## 1. 内核信息

| 项 | AXK5 | BYD9 | CZA1（欧版） |
|---|---|---|---|
| 系统 | One UI 6.1 / Android 14 | One UI 7.0 / Android 15 | One UI 8.0 / Android 16 |
| 内核版本 | `6.1.75-android14-11-29543898-abS928BXXU4AXK5` | `6.1.99-android14-11-30958380-abS928BXXU4BYD9` | `6.1.128-android14-11-31999054-abS928BXXS4CZA1` |
| 构建号 | **29543898** | **30958380** | **31999054** |
| 构建时间 | 2024-11-08 | 2025-04-14 | — |
| boot sha256 | `fa61e531…73bad8` | `67af36ff…9d41cc` | `eec1c9d2…725dd8` |
| raw ARM64 Image | 35,904,000 B | 36,952,576 B | 36,956,672 B |
| 恢复 ELF | `vmlinux_axk5.elf`（40,676,597 B，97,796 条符号） | `vmlinux_byd9.elf`（41,854,579 B，100,386 条符号） | `vmlinux_cza1_eur.elf`（41,880,408 B，100,747 条符号） |
| 模板 | CHC CZA1（6.1.128） | CHC CZA1（6.1.128） | TGY CZA1（6.1.128，同构建号） |

**关键**：欧版 CZA1 与港版 CZA1 构建号相同（31999054），但 **`SLIDE_NFULNL_LOGGER_OFF` 差 8 字节**
（欧版 `0x0160b70d` vs 港版 `0x0160b705`）——两者是不同机型（S928B vs S9280），`.rodata` 布局不同。
**同构建号 ≠ 同机型**，这是本次必须逐目标重跑的直接原因。

## 2. 定标方法（全自动化）

本次新增通用定标器 `fusion-s24u/tools/calibrate_target.py`，把 `ADAPTATION_GUIDE.md` 阶段 1/2 自动化：

1. **符号身份迁移**：模板 `target.h` 的 `*_OFF` → `KIMAGE_TEXT_BASE + 值` 命中模板 ELF 符号名
   → 该符号名在目标 ELF 中的新地址。跨内核世代不会误用偏移；符号改名有候选回退
   （AXK5/BYD9 上 `ashmem_miscs` 实为 `ashmem_misc`）。
2. **镜像实测**（6 项）：`ashmem_misc(s)+0x10`（回读 == `ashmem_fops`）、`random_table+0x108`
   （回读 == `sysctl_bootid`）、`worker_thread+0xA0`、唯一字符串 `nfnetlink_log\0`、
   tracefs 事件 id = `(__event_sched_blocked_reason - __start_ftrace_events)/8 + 20`。
3. **BTF 核对**：从内核自带 `__start_BTF` 切片，逐项实测 48 项结构体字段偏移与结构体大小，
   把「结构体常量沿用模板」从经验变成实测结论。
4. 生成 `target.h` + `p0_fingerprint.h`，输出审计 TSV + Markdown 报告；异常时非零退出。

**结果：三目标均 20 项符号迁移 / 6 项镜像派生 / 112 项沿用（其中 48 项 BTF 实测通过）/ 0 未解析。**

## 3. 关键偏移

| 宏 | AXK5 | BYD9 | CZA1（欧版） |
|---|---|---|---|
| `KMALLOC_CACHES_OFF` | 0x00160e158 | 0x0016ba938 | 0x0016c08f8 |
| `INIT_TASK_OFF` | 0x00206efc0 | 0x00215f040 | 0x00215f8c0 |
| `SYSTEM_UNBOUND_WQ_OFF` | 0x00205ae68 | 0x00214ae60 | 0x00214ae60 |
| `SLIDE_NFULNL_LOGGER_OFF` | 0x01560aa4 | 0x01605b1e | **0x0160b70d** |
| `SLIDE_TRACEFS_EVENT_ID` | 106 | 106 | 106 |
| `MM_STRUCT_SZ` | 0x3c0 | 0x3c0 | 0x3c0 |

`SLIDE_TRACEFS_EVENT_ID` 三代独立计算均为 106（索引 86 + 20），与港版一致。
`MM_STRUCT_SZ` 沿用模板 `0x3c0`：BTF 实测六个 6.1 内核（含 6.1.75/99/128/145/162）
`sizeof(mm_struct)` 全为 `0x3c0`；DZE2/EZIF 载荷写 `0x400` 属历史调优差异（两者在 CZA1 真机都成功过）。

## 4. 编译验证

```bash
cd 02_exploit工程/fusion-s24u
ANDROID_NDK_HOME=/home/nt/项目/samsung_root_research/06_工具链/android-ndk-r29 \
  make TARGET=e3q-S928BXXU4AXK5 stable all   # 其余两个同理
```

| 载荷 | 大小 | sha256 |
|---|---|---|
| `cve-2026-43499-S928BXXU4AXK5` | 104,128 B | `b346f8378b1b9fd6…` |
| `cve-2026-43499-S928BXXU4BYD9` | 104,128 B | `f03dd8f25fb75592…` |
| `cve-2026-43499-S928BXXS4CZA1` | 104,128 B | `0a81f47e138df4af…` |

- 三者均达成 104,128 B 尺寸约束；**重新编译逐字节可复现**（sha256 不变）
- 30 项偏移独立抽查（目标 ELF `llvm-nm` 地址 − `KIMAGE_TEXT_BASE` 对 `target.h`）全部一致
- root 守护沿用共享资产 `cve-2026-43499-root`（27,496 B，sha256 `d2dccd05…`，与 EZIF 逐字节相同）

## 5. App 集成

| 条目 | 载荷资产 | 系统 | 驱动 |
|---|---|---|---|
| `S928BCZA1` | `cve-2026-43499-S928BXXS4CZA1` | One UI 8.0 | `ksud-selected` |
| `S928BBYD9` | `cve-2026-43499-S928BXXU4BYD9` | One UI 7.0 | `ksud-selected` |
| `S928BAXK5` | `cve-2026-43499-S928BXXU4AXK5` | One UI 6.1 | `ksud-selected` |

- 三条目均 `tested=false`（需在设置中启用「未经测试的载荷」后可见）
- 分组沿用欧版先例「港版/台版（外版）」（EZIF、SM-F966B 欧版 Z Fold7 同此）
- **驱动未单独构建**：三者均为 e3q / 6.1 KMI，沿用通用 `ksud-selected`；
  ksud 运行时会按内核 vermagic 替换模块 vermagic（日志 `Replaced module vermagic with kernel-required value`），
  因此不强制要求逐代专属驱动。真机若驱动加载失败，再按 EZIF 路线用 DDK 构建专属驱动。
- 条目范围串里带构建码（`"CZA1（欧版）"` 等），让 `coversBuild()` 能解析出 4 位构建码做范围校验

## 6. 验证清单

- [x] 符号定标（nm 身份迁移 + 逐项复核）
- [x] p0 指纹生成（readback 256/256）
- [x] 正式 target 构建（均 104,128 B，重编译可复现）
- [x] BTF 结构体核对（48 项）
- [x] App 条目 + 载荷资产接入，`compileDebugKotlin` 通过
- [ ] **真机验证**（建议采集 `getprop ro.build.fingerprint`、
  `cat /sys/kernel/tracing/events/sched/sched_blocked_reason/id`，按 `ADAPTATION_GUIDE_FULL.md` §8 流程）
- [ ] KernelSU 专属驱动（如需，按 EZIF 的 `adapt-ksu.sh` 路线）

## 7. 备注

- `BUILD_FINGERPRINT` 仍为占位串（`samsung/e3qoxm/e3q:<n>/CP2A.XXXXXXXX.XXX/<PDA>:user/release-keys`）：
  仅元数据，不参与产物逻辑；拿到真机 `getprop ro.build.fingerprint` 后可回填
- AXK5（6.1.75）是本 App 支持的最早 6.1 代际，早于多个 GKI 补丁；
  真机失败优先怀疑上游行为变化（pipe / `call_usermodehelper`），而非偏移
- 分析细节见研究仓库 `devices_ports/s928b_{axk5,byd9,cza1_eur}_analysis.md`