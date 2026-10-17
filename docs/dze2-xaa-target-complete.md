# 美版 S926U1 DZE2（XAA）适配定标报告

> 定标日期：2026-10-17
> 目标：`fusion-s24u` → `src/targets/e2q-S926USQS6DZE2/`
> 素材：`devices_ports/xaa_oneui8.5_dze2_boot.img`
> （SM-S926U1, CSC XAA, S926U1UES6DZE2, boot sha256 `1d52c16449fcaeb9b00933b2a8ee1dc172d084a23212092d646ab96423c6c397`）
> 触发：issue #10 —— SM-S926U1 在 DZE2 上用港版载荷 0 成功（9 次 cache gate 失败 + 2 次触发失败）

---

## 1. 内核信息

| 项 | 美版 S926U1 DZE2 | 港版 S9280 DZE2（对照） |
|---|---|---|
| 构建号 | **33419968** | 33419968 |
| 机型标识 | abS926USQS6DZE2 | abS9280ZHS6DZE2 |
| 内核版本 | 6.1.145-android14-11 | 同 |
| 编译时间 | Mon May 18 08:39:17 UTC 2026 | — |
| KIMAGE_TEXT_BASE | 0xffffffc008000000 | 同 |
| boot.img | 100,663,296 B | — |
| raw kernel | 38,005,248 B | 同 |

**与港版 DZE2 为同一构建号**（33419968）；与国行 DZF2（3254743）不同批。

## 2. 定标方法

1. `magiskboot unpack` → raw kernel（38,005,248 B）
2. `vmlinux-to-elf` → `vmlinux_dze2_xaa.elf`（43,070,883 B，kallsyms 107,254 条）
3. 与港版 ELF 全量符号对比：nm 各 107,253 行，公共符号 **103,479** 个
4. 非符号常量（字符串、指针表）按原始 Image 逐字节复核
5. p0 指纹用本内核原始 Image 重新生成（脚本自带 readback 校验），并对 ELF 逐字验证

## 3. 定标结论

### 3.1 全量符号对比：103,479 个公共符号仅 7 项不同

差异全部在 `.rodata` 字符串常量区（整体位移，与机型标识串长度相关）：

| 符号 | 港版 | 美版 | 差值 |
|---|---|---|---|
| trunc_msg | 0x01678c08 | 0x01678bda | -0x2e |
| f_midi_shortname | 0x0167e6f7 | 0x0167e6c9 | -0x2e |
| max_tt_usecs | 0x016b6204 | 0x016b61d6 | -0x2e |
| f_midi_longname | 0x016c93dd | 0x016c93af | -0x2e |
| pty_line_name.ptychar | 0x016e4441 | 0x016e4413 | -0x2e |
| zero_mask | 0x01707c98 | 0x01707c6a | -0x2e |
| task_index_to_char.state_char | 0x01717c4a | 0x01717be0 | -0x6a |

代码段/数据段符号偏移、struct 布局全部一致（103,472/103,479 完全同地址）。

### 3.2 唯一需要重标的偏移：SLIDE_NFULNL_LOGGER_OFF

`"nfnetlink_log"` 字符串是 KASLR 定标（boot_id 路径）与日志使用的标记；属字符串常量，nm 无法覆盖，按原始 Image 字节实测：

| | 偏移 |
|---|---|
| 港版 DZE2（tgy） | `0x016a61e6` |
| **美版 DZE2（本目标）** | **`0x016a61b8`** |
| 美版 DZF2（e3q-S928USQS6DZF2） | `0x016a61b8`（一致） |

> 复核方式：美版 ELF `0x16a61b8` 处为 `nfnetlink_log\0`（港版同一地址是无关字符串，`0x16a61e6` 处才是），两个内核逐字节互证。

### 3.3 p0 指纹：按美版内核重新生成

- 32 行 × 8 qword；与港版仅 **1 个 qword** 不同：
  - 行 `slide=0xa0000`、页内偏移 `0x200`：港版 `0x9106d0219000b001` → 美版 `0x9105e0219000b001`（对应 image `0x152200`，单条指令立即数差异）
- 生成 readback 校验 256/256；并对 ELF 逐字节复核 256/256
- 二进制内嵌验证：新载荷仅含美版指纹字、不再含港版字（出货港版载荷则相反）

### 3.4 其余关键值复核（全部一致）

kmalloc_caches `0x176c6f8`、anon_pipe_buf_ops `0x1219d90`、ashmem 全组（ioctl/mmap/open/release/show_fdinfo/fops/misc_fops）、configfs read_iter/bin_write_iter、copy_splice_read `0x3ef340`、noop_llseek、call_usermodehelper_exec_work、system_unbound_wq、init_task、root_task_group、selinux_state、sysctl_bootid、nfulnl_logger(loggers) `0x2242a20`、boot_id 指针表 `0x23762f0`（内容=&sysctl_bootid ✓）、ashmem misc 表项 `0x23bb5b0`（内容=&ashmem_fops ✓）、tracefs caller `0xdb1a0`（逐字节一致）。

## 4. 构建

```bash
ANDROID_NDK_HOME=/home/nt/项目/samsung_root_research/06_工具链/android-ndk-r29 \
  TARGET=e2q-S926USQS6DZE2 make stable
# → build/e2q-S926USQS6DZE2/cve-2026-43499-app.stable.so
#   104,128 B（尺寸约束）
#   sha256 549bd287a93cc4322e35678d7907a39cad82f6251ad8015ff6047bd7b139a2ba
```

App 集成：资产 `cve-2026-43499-dze2-xaa`，条目 `S926U1DZE2`（外版分组，默认 `tested=false`）。

## 5. 与 issue #10 现象的关系

出货港版 DZE2 载荷入库于 2026-08-22 11:20（App commit cbc2a34），**早于**当天 20:09 的 pipe gate v2 等修复：

| 修复 | 提交 | 对 S926U1 的意义 |
|---|---|---|
| cache gate 8 页→64 页 + 逐槽位读取 + gate 内 find_pipe_buffer | 3c3d09b (8/22 20:09) | 日志 9 次 gate 失败均只扫 8 页、全页 slab_cache=0 |
| 放宽 cache_match（任意非零 kmem_cache + 严格验证） | 292cf7b (8/22 23:42) | 成功样例中 pipe_buffer 常落在 normal2k 而非 cgroup2k |
| KernelSnitch futex_hashsize roundup 对齐 | a7038eb (8/23) | 在线核数不同时的泄漏正确性 |
| CFI 触发超时/重试、tracefs slide=0 防御等 | 7c8d3ea / ea0552c (8/23) | 日志 2 次 triggered=0 |
| root umh 子进程退出时序（黑屏重启修复） | b02e77e (8/24) | 载荷侧，root 阶段稳定性 |

S926U1 日志（465 行；11 次完整尝试 + 2 次中断；0 成功）关键结论：

- KASLR 解析**正确**：tracefs 源，slide=0xe0000；CFI 回读 misc_fops = `0xffffffc0094b1140`，与 `KIMAGE+slide+ASHMEM_FOPS_OFF` 逐位吻合
- 写入原语正常（cfi write/read 均 ret=35）
- 失败点：8 页窗口内无任何 kmalloc-2k slab 页（slab_cache 全 0）；同签名在港版设备上也有约 1/3 概率出现——64 页扫描 + 放宽匹配正是针对它

## 6. 验证清单

- [x] 符号/偏移定标（nm 103,479 + 逐字节复核）
- [x] p0 指纹生成（readback 256/256）
- [x] 构建（104,128 B / sha256 已记录）
- [x] 指纹内嵌二进制验证
- [ ] **真机验证**（issue #10 / SM-S926U1，待测）

## 7. 未采纳项与备注

- `PIPE_DRAIN_SLABS` / `PIPE_RECLAIM_SLABS` 维持 10（所有目标的约定值；16 会触发 pipe-user-pages-soft 软限制）
- root helper（`cve-2026-43499-root`）沿用 App 现行版（md5 `1a79f8b4…`，b399aa5 有意还原的版本）；本载荷 UMH 协议未变，无需更换
- 美版 S928U1（e3q）若升级到 DZE2 批次，字符串区布局与本内核一致，理论上同样适用本载荷——但未取到该 boot 镜像，不写入覆盖范围；出现实测案例再放宽条目描述
