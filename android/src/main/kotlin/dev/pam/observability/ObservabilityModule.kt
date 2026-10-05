package dev.pam.observability

import android.content.ContentProvider
import android.content.ContentValues
import android.content.Context
import android.database.Cursor
import android.net.Uri
import dev.pam.nativeapp.modules.ModuleCompletion
import dev.pam.nativeapp.modules.ModuleResultStatus
import dev.pam.nativeapp.modules.NativeModule
import dev.pam.nativeapp.protocol.WireMap
import dev.pam.nativeapp.protocol.WireValue
import io.sentry.Sentry
import java.util.concurrent.Executors

/** Native side of `Observability::exporter()` and the Sentry facade methods. */
class ObservabilityModule(context: Context) : NativeModule, AutoCloseable {
    private val context = context.applicationContext ?: context
    private val worker = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "pam-observability").apply { isDaemon = true }
    }

    init {
        SentryBridge.bootstrap(this.context)
    }

    override fun invoke(method: String, payload: ByteArray, completion: ModuleCompletion) {
        val values = runCatching { WireMap.decode(payload) }.getOrElse {
            completion.failure(it.message ?: "Invalid payload")
            return
        }
        worker.execute {
            runCatching {
                when (method) {
                    "sentryInit" -> {
                        SentryBridge.start(context, values.text("config"), persist = true)
                        emptyMap()
                    }
                    "sentryCapture" -> mapOf("eventId" to WireValue.Text(SentryBridge.capture(values.text("event"))))
                    "sentryBreadcrumb" -> {
                        SentryBridge.breadcrumb(values.text("breadcrumb"))
                        emptyMap()
                    }
                    "sentryUser" -> {
                        SentryBridge.user(values.text("id"), values.text("email"), values.text("username"))
                        emptyMap()
                    }
                    "sentryTag" -> {
                        Sentry.setTag(values.text("key"), values.text("value"))
                        emptyMap()
                    }
                    "sentryFlush" -> {
                        Sentry.flush(values.integer("timeoutMs"))
                        emptyMap()
                    }
                    "sentryStatus" -> mapOf("status" to WireValue.Text(SentryBridge.status().toString()))
                    "sentryTest" -> {
                        check(Sentry.isEnabled()) { SentryBridge.lastError.ifBlank { "Sentry exporter is not configured" } }
                        val id = Sentry.captureException(IllegalStateException("PAM Native Sentry diagnostic exception"))
                        Sentry.flush(5_000)
                        mapOf("eventId" to WireValue.Text(id.toString()))
                    }
                    "sentryStop" -> {
                        SentryBridge.stop(context)
                        emptyMap()
                    }
                    else -> error("Unknown observability method: $method")
                }
            }.onSuccess { completion.success(it) }
                .onFailure { completion.failure(it.message ?: it.javaClass.simpleName) }
        }
    }

    override fun close() {
        worker.shutdown()
    }

    private fun Map<String, WireValue>.text(key: String): String =
        (get(key) as? WireValue.Text)?.value ?: throw IllegalArgumentException("$key is required")

    private fun Map<String, WireValue>.integer(key: String): Long =
        (get(key) as? WireValue.Integer)?.value ?: throw IllegalArgumentException("$key is required")

    private fun ModuleCompletion.success(values: Map<String, WireValue>) =
        complete(ModuleResultStatus.SUCCESS, WireMap.encode(values))

    private fun ModuleCompletion.failure(message: String) =
        complete(ModuleResultStatus.FAILURE, message.toByteArray())
}

/**
 * Starts Sentry from the persisted exporter configuration as soon as the
 * process starts, before the PHP runtime and the module registry exist.
 */
class SentryBootstrap : ContentProvider() {
    override fun onCreate(): Boolean {
        context?.let { runCatching { SentryBridge.bootstrap(it) } }
        return true
    }

    override fun query(uri: Uri, projection: Array<out String>?, selection: String?, selectionArgs: Array<out String>?, sortOrder: String?): Cursor? = null
    override fun getType(uri: Uri): String? = null
    override fun insert(uri: Uri, values: ContentValues?): Uri? = null
    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = 0
    override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<out String>?): Int = 0
}
