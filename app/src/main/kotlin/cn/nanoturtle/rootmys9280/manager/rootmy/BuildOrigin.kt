package cn.nanoturtle.rootmys9280.manager.rootmy

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import java.security.MessageDigest

/**
 * 判断当前这份 APK 是不是**官方构建**。
 *
 * ## 为什么需要它
 *
 * 这个仓库经常被 fork 出去自行编译、自行发布。那些版本如果没改上传地址，
 * 会把运行统计（成功率等）一起提交到官方服务器，与真实数据混在一起 ——
 * 服务器此前无法分辨，因为上传字段里只有机型/系统/版本号，没有身份信息。
 *
 * ## 为什么用签名而不是别的
 *
 * 判据必须在**客户端无法伪造成官方**的前提下可用：
 *
 * | 判据 | fork 能否伪造 |
 * |---|---|
 * | 包名 | 能（改 applicationId 即可） |
 * | 版本号 / 构建 hash | 能（随便写） |
 * | 编译期注入的常量 | 能（改源代码即可） |
 * | **APK 签名证书** | **不能**（没有官方 keystore） |
 *
 * 所以这里读的是**本 APK 的签名证书 SHA-256**，与编译期写死的官方指纹比对。
 * 官方 keystore 不在仓库里，fork 者用自己的签名必然对不上。
 *
 * 注意：这不是安全边界（root 工具本身就在提权），只是为了**让统计数据干净**、
 * 并避免服务端下发的载荷清单与 fork 自带的自制载荷打架。伪造它没有意义。
 */
object BuildOrigin {

    private const val TAG = "BuildOrigin"

    /**
     * 官方签名证书的 SHA-256 指纹（小写十六进制）。
     *
     * 取自官方 release keystore 签出的 APK：
     *   CN=S9280Root, O=RootMyS9280, C=CN
     * 换签名密钥时必须同步更新这个值，否则官方包会被自己判成第三方。
     */
    private const val OFFICIAL_CERT_SHA256 =
        "12daafaaa2beea11c6ec6c6353d4899331b0fbe15e17c20c887e0a4510ade9af"

    /** 当前 APK 的签名证书 SHA-256；取不到返回 null。 */
    fun signatureSha256(context: Context): String? = runCatching {
        val pm = context.packageManager
        val info =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                pm.getPackageInfo(context.packageName, PackageManager.GET_SIGNING_CERTIFICATES)
            } else {
                @Suppress("DEPRECATION")
                pm.getPackageInfo(context.packageName, PackageManager.GET_SIGNATURES)
            }

        val certs =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                val si = info.signingInfo ?: return null
                if (si.hasMultipleSigners()) si.apkContentsSigners else si.signingCertificateHistory
            } else {
                @Suppress("DEPRECATION") info.signatures
            }

        val first = certs?.firstOrNull() ?: return null
        MessageDigest.getInstance("SHA-256")
            .digest(first.toByteArray())
            .joinToString("") { "%02x".format(it) }
    }.getOrElse {
        Log.w(TAG, "读取签名失败: ${it.message}")
        null
    }

    /**
     * 是否官方构建。
     *
     * 读不到签名时（极少数 ROM/加固环境）**判为第三方**：宁可少统计一份官方数据，
     * 也不要让不明来源的数据混进去。
     */
    fun isOfficial(context: Context): Boolean {
        val sig = signatureSha256(context) ?: return false
        val ok = sig.equals(OFFICIAL_CERT_SHA256, ignoreCase = true)
        if (!ok) {
            Log.i(TAG, "非官方签名（${sig.take(16)}…）：将不上报统计、不使用在线载荷清单")
        }
        return ok
    }
}
