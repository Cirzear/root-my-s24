# BakaSU 内核模式（实验）

RootMyS24 设置卡里新增 **「BakaSU 内核（实验）」** 开关。打开后 root 流程会用
BakaSU（三星 KDP/RKP/DEFEX 适配版）的内核模块替换 KernelSU：

| 项目 | KernelSU 模式 | BakaSU 模式 |
|---|---|---|
| root 守护进程 | `cve-2026-43499-root` | `cve-2026-43499-root-bakasu` |
| late-load 驱动 | `ksud-selected` 等 | `ksud-bakasu-dzh3`（内嵌 DZH3 模块） |
| 管理器 | `me.weishu.kernelsu` | `com.resukisu.resukisu`（自动安装） |
| 内核 | KernelSU LKM | BakaSU LKM（uapi4/35171，三星适配） |

## 已验证环境

- SM-S9280 / `S9280ZCS6DZH3` / 内核 `6.1.145-android14-11-3254743-abS9280ZCS6DZH3`
- 管理器 v4.2.0-rc3 (35171)，内核驱动 35171/5，显示「工作中 / LKM / 越狱模式」
- 超级用户与模块安装可用

## 注意

- 每次开机都要重新跑一次 root 流程（late-load 不持久，与 KernelSU 模式一致）；
- **不要点管理器里的「安装/刷入」页面**（会写 boot，有变砖风险）；装模块走「模块」页；
- `selinux_hide` feature 不可用（RKP 禁止 live text patch），日志有一条
  `Failed to set feature selinux_hide`，不影响使用；
- 构建/源码/脚本见独立仓库：<https://github.com/NanoTurtle1145/BakaSU-S9280>。

## 资源改动

- `assets/ksud-bakasu-dzh3`：内嵌三星适配模块的 ksud（4.37 MB）
- `assets/cve-2026-43499-root-bakasu`：BakaSU 包名/环境适配的 root 守护进程
- `assets/bakasu-manager.apk`：BakaSU 管理器 v4.2.0-rc3（9.3 MB，root 流程中自动安装）
