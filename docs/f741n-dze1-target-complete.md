# 韩版 SM-F741N DZE1（b6q）适配定标报告

> 定标日期：2026-10-18
> 状态：**已实测成功**（beta.191 由韩版用户验证，issue #16，v3.5.1 起按已实测发布）
> 目标：`fusion-s24u` → `src/targets/b6q-F741NKSS4DZE1/`
> 素材：`devices_ports/f741n_unpacked/`（SM-F741N 韩版 Z Flip6，F741NKSS4DZE1，boot.img sha256 `0037d7ebd4f19cf858c471f22507947aa3087ac51d276f87700e87d2f86ce8c0`）
> 触发：韩版用户求助 —— F741N 此前无对应载荷与 KernelSU 驱动

---

## 1. 内核信息

| 项 | 韩版 F741N DZE1 | 美版 S926U1 DZE2（对照） |
|---|---|---|
| 内核版本 | `6.1.145-android14-11-33418572-abF741NKSS4DZE1` | `…-33419968-abS926USQS6DZE2` |
| 构建号 | **33418572** | 33419968 |
| 构建时间 | 2026-05-15 05:58 UTC | 2026-05-18 08:39 UTC |
| 编译器 | clang 17.0.2 (r487747c) | 同 |
| KIMAGE_TEXT_BASE | `0xffffffc008000000` | 同 |
| boot.img | 100,663,296 B | 100,663,296 B |
| raw kernel | 38,005,248 B | 同 |
| 恢复出的 ELF | `vmlinux_f741n_dze1.elf`（43,070,883 B，107,253 条符号记录） | — |

**与美版 DZE2 同内核分支**（同 clang、同 KIMAGE，构建号差 1396），比两台 DZE2 参考机早 3–4 天编译。

## 2. 定标方法

1. `magiskboot unpack` → raw kernel（38,005,248 B）
2. `vmlinux-to-elf` → `vmlinux_f741n_dze1.elf`（107,253 条符号记录）
3. 与美版/港版 ELF 全量符号对比：`llvm-nm -n --defined-only` 提取 `(name,type) → addr` 全表，按 `name+type` 对齐（103,520 个键）
4. 非符号常量（字符串、指针表）按原始 Image 逐字节复核
5. p0 指纹用本内核原始 Image 重新生成（脚本自带 readback 校验），并对 ELF 逐字验证

## 3. 定标结论

### 3.1 全量符号对比（vs 美版 DZE2）：103,520 个符号仅 2 项不同

| 符号 | F741N | 美版 S926U1 | 差值 |
|---|---|---|---|
| `task_index_to_char.state_char` | `0xffffffc009717c0e` | `0xffffffc009717be0` | +0x2e |
| `zero_mask` | `0xffffffc009707c98` | `0xffffffc009707c6a` | +0x2e |

两条均为 `.rodata` 打印用字符串常量；**代码段、数据段、函数与变量地址 100% 一致**。

与港版对比：6 项字符串标签差异（5 项 0x2e、1 项 0x3c，属已知「美版↔港版 `.rodata` 字符串区整体偏移」同款模式）。

### 3.2 唯一需要核对的偏移：SLIDE_NFULNL_LOGGER_OFF

| 内核 | Image 内 `"nfnetlink_log"` 偏移 |
|---|---|
| **韩版 F741N（本目标）** | **`0x016a61b8`** |
| 美版 DZE2（e2q-S926USQS6DZE2） | `0x016a61b8`（一致） |
| 港版 DZE2（e3q-S9280ZHS6DZE2） | `0x016a61e6` |

> 复核方式：原始 Image 字节 + `nfulnl_logger` 结构体首 qword 指针值双重验证。F741N 与美版同址，直接沿用美版模板无需修改；若复用港版模板则必须改为 `0x016a61b8`。

### 3.3 p0 指纹：按 F741N 内核重新生成

- 32 行 × 8 qword；与港版指纹 **256/256 相同**；与美版指纹差 **1 个 qword**：
  - 行 `slide=0xa0000` 第 2 个 qword：港版/F741N `0x9106d0219000b001` → 美版 `0x9105e0219000b001`
- 生成 readback 校验 256/256；同一管线用美版内核重新生成，与仓库既有 `e2q` 指纹逐字节一致（管线可信度验证）

### 3.4 关键锚点与 CVE 链前提（三内核逐项核对，全部一致）

- 锚点符号（相对 KIMAGE_TEXT_BASE）：`call_usermodehelper_exec_work` `0xd39cc`、`anon_pipe_buf_ops` `0x1219d90`、`ashmem_fops` `0x13d1140`、`kmalloc_caches` `0x176c6f8`、`system_unbound_wq` `0x223ae60`、`nfulnl_logger` `0x2242a20`、`init_task` `0x224f8c0`、`root_task_group` `0x244cd80`、`selinux_state` `0x2521588`、`sysctl_bootid` `0x26046e8`
- `remove_waiter`（偏移 `0x0111fcb8`，0x268 字节）反汇编逐字节相同；「清 `current->pi_blocked_on` 而非 `waiter->task->pi_blocked_on`」的竞态窗口在 F741N 上同样存在（全路径复核见研究仓库分析报告第 5 节）

## 4. 构建

```bash
ANDROID_NDK_HOME=/home/nt/项目/samsung_root_research/06_工具链/android-ndk-r29 \
  make TARGET=b6q-F741NKSS4DZE1 app stable
```

| 产物 | 大小 | sha256 |
|---|---|---|
| `cve-2026-43499-app.stable.so`（App 资产） | 104,128 B（尺寸约束） | `cf0de020ee3bb1b3b9c16ced1ac5cc7193654087b199c9535d9e7a9cdd63e3ac` |
| `cve-2026-43499-app.so` | 127,392 B | `9cd2b36f8195816a41c3c6a181750a613e46eb5176403cc5173ffb230fad9513` |
| `cve-2026-43499-root` | 27,496 B | `d2dccd05d027fde632301613e65337274ef72f395173d6ffb48ceef50babba19` |

内嵌标签 `b6q-F741NKSS4DZE1-app-physical-p0-oracle` 校验通过；与草案构建逐字节一致。

### KernelSU 3.3.0（32601）

| 产物 | 大小 | sha256 |
|---|---|---|
| `kernelsu-F741N.ko`（内核驱动） | 404,840 B | `e201114262160262e09134e6f972ee25601c5a819f428a5579eea75d18f05d3c` |
| `ksud-f741n-32601`（内嵌驱动的 ksud 资产） | 5,107,192 B | `f8648635d401a72aa8687bd5d6db0b8e84c2fe01e5c251c228b2e4e6c3852a23` |

- 模块 vermagic 与真机逐字节一致：`6.1.145-android14-11-33418572-abF741NKSS4DZE1 SMP preempt mod_unload modversions aarch64`
- 静态审计通过：未定义符号 202 / 缺失 0 / `kallsyms` 解析 70 / CRC 不一致 0；与已发货 DZF2 模块节级对比，除 `.modinfo`（vermagic）、build-id 及 `.symtab` 少量字节外逐字节一致
- 配套管理器需安装**官方 KernelSU v3.3.0 (32601)**；F741N 未构建 3.2.5 旧版驱动，异常时界面无可回退选项

## 5. App 集成

- 载荷资产：`cve-2026-43499-F741NKSS4DZE1`（条目 `F741NDZE1`，分组「韩版」）
- KSU 驱动资产：`ksud-f741n-32601`（32601 / 3.3.0；vermagic 唯一对应本内核，不可与其它机型混用）
- root 守护进程沿用共享资产 `cve-2026-43499-root`（与机型无关，UMH 协议未变）
- `tested=true`（v3.5.1 起，beta.191 真机验证通过 issue #16）；服务端 `payloads.json` 的 `F741NDZE1` 条目已同步翻转为 `tested=true`

## 6. 验证清单

- [x] 符号定标（nm 103,520 + 逐字节复核）
- [x] p0 指纹生成（readback 256/256）
- [x] 正式 target 构建（104,128 B；与草案逐字节一致）
- [x] KernelSU 驱动构建 + 静态审计
- [x] 服务端清单条目配置（`tested=false`、minVc=186；`builds` 收紧为本机型三运营商构建，避免相邻机型串入统计）
- [x] **真机验证**（beta.191，韩版用户 issue #16 反馈通过）

## 7. 未采纳项与备注

- `PIPE_DRAIN_SLABS` / `PIPE_RECLAIM_SLABS` 维持 10（所有目标的约定值）
- `BUILD_FINGERPRINT` 暂为占位串（`BP4A.XXXXXXXX.XXX`）：仅元数据、不参与产物逻辑，拿到真机 `getprop ro.build.fingerprint` 后补
- 本 target 为美版模板的直接复用（全部 `*_OFF` 无修改）；原始 Image 整体差异（F741N vs 美版 451,363 B / 15,911 段）不影响符号地址表——exploit 定标依赖的部分完全一致，剩余风险集中在真机竞态成功率
