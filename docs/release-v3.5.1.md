## 本版主题：韩版 Z Flip6（SM-F741N）支持 + 多机型/实验能力正式化

v3.5.0 之后的累积更新以 **v3.5.1 稳定版**发布：新增韩版 Galaxy Z Flip 6（SM-F741N）
正式支持（经过 beta.191 真机验证），并把 W23、DZI1、BakaSU 实验内核模式、DirtyFrag 引擎
等此前 beta/alpha 线内容纳入正式包。

> **未包含**：美版 S24+（SM-S926U1 / XAA）DZE2 独立载荷已完成定标（issue #10），
> 但尚未有真机实测反馈，**不在 v3.5.1 中发布**（等实测后再放）。

---

## 一、新增机型 / 固件

- **SM-F741N 韩版 Galaxy Z Flip 6（b6q / F741NKSS4DZE1，内核 6.1.145-android14-11-33418572）**
  - 独立定标载荷 `cve-2026-43499-F741NKSS4DZE1`；
  - 专属 KernelSU 3.3.0（32601）驱动 `ksud-f741n-32601`（vermagic 逐字节匹配，本机型无 3.2.5 回退）；
  - beta.191 由韩版用户真机验证通过（issue #16），本版起按「已实测」发布。
- **SM-S9210 国行 DZI1（One UI 8.5）**：新增独立载荷与条目。
- **SM-W9023N（心系天下 W23，Fold4 / cape / 5.10.236）**：完成定标与载荷接入（实验性）。
- **DirtyFrag 引擎（alpha，默认关闭）**：KMI 识别与引擎分流框架、native 引擎实现、
  ESP 回环自检；设置内提供实验入口。

## 二、BakaSU 实验内核模式（新）

- 设置卡新增「**BakaSU 内核（实验）**」开关（默认关闭）：root 流程改用三星适配的
  BakaSU LKM（KDP / RKP / DEFEX 适配），并自动安装 BakaSU 管理器。
- 仅 **SM-S9280 / DZH3** 已真机验证（管理器显示「工作中 / LKM / 越狱模式」）；
  属于 per-boot 临时 root，不刷 boot、不影响 Knox。
- 说明见 `docs/bakasu-kernel-mode.md`；构建/脚本仓库：
  <https://github.com/NanoTurtle1145/BakaSU-S9280>。

## 三、功能与修复

- **无线调试**：修复「误报已 root」与「载荷推不上去」；修复配对闪退与静默卡死（三个独立缺陷）。
- **载荷状态**：未知载荷显示「未知」徽章（三态判定）；区分官方构建与 fork 构建，
  避免统计混入与载荷冲突。
- **KernelSU 资产下发**：App 上报 versionCode，服务端按版本下发对应 KSU 驱动资产。
- **UI**：设置页分组重组、关于页折叠合并；beta 版本命名规则统一为
  `<基版本>-<渠道>.<versionCode>`。
- **反馈闭环**：反馈凭据 + 开发者回复 + 载荷在线下载能力。
- **多语言**：新增香港繁体（zh-HK）支持；四语 README 与文档中心同步。

## 四、未包含（重要）

- **美版 S24+（SM-S926U1 / XAA）DZE2 载荷**：`e2q-S926USQS6DZE2` 已完成定标
  （美/港 `.rodata` 字符串区差 0x2e，`nfnetlink_log` 0x016a61b8，p0 指纹重生成；
  见 `docs/dze2-xaa-target-complete.md`），但尚无 S926U1 真机实测，**v3.5.1 不包含**
  该固件条目，且载荷资产**不打包进 APK**（暂存于 `app/unreleased-payloads/`，内含恢复步骤；验证通过后再发布）。港版/台版 DZE2 条目不受影响。

## 系统要求

- 机型：见根 README「支持设备」（S24 系列、S23/S25 部分机型、Z Flip/Fold 系列与 W23）
- 系统：Android 12 及以上（Samsung One UI，具体固件见支持列表）
- 授权：Shizuku 或无线调试直连
- 管理器：按机型选择 KernelSU 3.3.0（32601，推荐）或 3.2.5（32525，回退）

## 已知问题

- BakaSU 模式为实验性：每次开机需重跑流程，`selinux_hide` 不可用，且**不要**点管理器内
  「安装/刷入」页面（会写 boot，有变砖风险）。
- 部分第三方移植载荷（S23/S25/S26/折叠屏系列等）仍标记为「未经测试」，
  需在设置中打开「未经测试的载荷」才显示。

## 产物校验

- APK：`RootMyS24-v3.5.1-release.apk`
- SHA-256：`54292ce5b5dfada2e86fc77aeff6565872054219f1ab03a727b856e6f124d32d`
