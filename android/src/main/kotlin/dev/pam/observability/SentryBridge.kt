package dev.pam.observability

import android.content.Context
import io.sentry.Breadcrumb
import io.sentry.Sentry
import io.sentry.SentryEvent
import io.sentry.SentryLevel
import io.sentry.android.core.SentryAndroid
import io.sentry.protocol.Mechanism
import io.sentry.protocol.Message
import io.sentry.protocol.SentryException
import io.sentry.protocol.SentryStackFrame
import io.sentry.protocol.SentryStackTrace
import io.sentry.protocol.User
import org.json.JSONArray
import org.json.JSONObject

/**
 * Owns the process-wide Sentry SDK for PAM Native.
 *
 * The configuration sent by PHP (`Observability::exporter()`) is persisted so
 * [SentryBootstrap] can start the SDK on the next launch before the PHP
 * runtime boots: JVM crashes, ANRs and native (NDK) crashes of the embedded
 * runtime are then captured even when they happen during startup.
 */
internal object SentryBridge {
    private const val PREFERENCES = "dev.pam.observability.sentry"
    private const val KEY_CONFIG = "config"

    @Volatile var activeConfig: String? = null
        private set
    @Volatile var lastError: String = ""
        private set

    /**
     * Starts Sentry from the persisted configuration, if any. Safe to call
     * repeatedly and from any thread; a concurrent start() wins the lock.
     */
    fun bootstrap(context: Context) {
        if (activeConfig != null) return
        val stored = runCatching { preferences(context).getString(KEY_CONFIG, null) }.getOrNull() ?: return
        runCatching { start(context, stored, persist = false) }
    }

    @Synchronized
    fun start(context: Context, json: String, persist: Boolean) {
        val config = JSONObject(json)
        val dsn = config.getString("dsn")
        require(dsn.startsWith("https://") || dsn.startsWith("http://")) { "Invalid Sentry DSN" }
        if (persist && config.optBoolean("persist", true)) {
            preferences(context).edit().putString(KEY_CONFIG, json).apply()
        } else if (persist) {
            preferences(context).edit().remove(KEY_CONFIG).apply()
        }
        if (json == activeConfig && Sentry.isEnabled()) return
        try {
            SentryAndroid.init(context.applicationContext ?: context) { options ->
                options.dsn = dsn
                config.optString("environment").takeIf { it.isNotEmpty() }?.let { options.environment = it }
                config.optString("release").takeIf { it.isNotEmpty() }?.let { options.release = it }
                config.optString("dist").takeIf { it.isNotEmpty() }?.let { options.dist = it }
                options.sampleRate = config.optDouble("sampleRate", 1.0)
                if (config.has("tracesSampleRate")) options.tracesSampleRate = config.getDouble("tracesSampleRate")
                if (config.has("profilesSampleRate")) options.profilesSampleRate = config.getDouble("profilesSampleRate")
                options.isSendDefaultPii = config.optBoolean("sendDefaultPii", false)
                options.isDebug = config.optBoolean("debug", false)
                options.maxBreadcrumbs = config.optInt("maxBreadcrumbs", 100)
                options.isAnrEnabled = config.optBoolean("anr", true)
                options.anrTimeoutIntervalMillis = config.optLong("anrTimeoutMs", 5_000)
                options.isEnableNdk = config.optBoolean("nativeCrashes", true)
                options.maxCacheItems = 60
                options.setTag("pam.runtime", "pam-native")
                config.optJSONObject("tags")?.let { tags -> tags.keys().forEach { options.setTag(it, tags.getString(it)) } }
            }
            activeConfig = json
            lastError = if (Sentry.isEnabled()) "" else "Sentry did not start"
        } catch (error: Throwable) {
            activeConfig = null
            lastError = error.message ?: error.javaClass.simpleName
            throw error
        }
    }

    @Synchronized
    fun stop(context: Context) {
        preferences(context).edit().remove(KEY_CONFIG).apply()
        activeConfig = null
        Sentry.close()
    }

    /** Builds a Sentry event from a PHP throwable / message payload. */
    fun capture(json: String): String {
        check(Sentry.isEnabled()) { lastError.ifBlank { "Sentry exporter is not configured" } }
        val payload = JSONObject(json)
        val event = SentryEvent()
        event.platform = "php"
        event.logger = payload.optString("logger", "pam.php")
        event.level = level(payload.optInt("level", 4))
        payload.optString("message").takeIf { it.isNotEmpty() }?.let { text ->
            event.message = Message().apply { formatted = text }
        }
        payload.optJSONArray("exceptions")?.let { list ->
            event.exceptions = (0 until list.length()).map { exception(list.getJSONObject(it), payload.optBoolean("handled", true)) }
        }
        payload.optJSONObject("tags")?.let { tags -> tags.keys().forEach { event.setTag(it, tags.getString(it)) } }
        payload.optJSONObject("extra")?.let { extra -> extra.keys().forEach { event.setExtra(it, extra.get(it).toString()) } }
        payload.optString("fingerprint").takeIf { it.isNotEmpty() }?.let { event.fingerprints = listOf(it) }
        return Sentry.captureEvent(event).toString()
    }

    fun breadcrumb(json: String) {
        val payload = JSONObject(json)
        val crumb = Breadcrumb(payload.optString("message"))
        crumb.category = payload.optString("category", "app")
        crumb.type = payload.optString("type", "default")
        crumb.level = level(payload.optInt("level", 2))
        payload.optJSONObject("data")?.let { data -> data.keys().forEach { crumb.setData(it, data.get(it).toString()) } }
        Sentry.addBreadcrumb(crumb)
    }

    fun user(id: String, email: String, username: String) {
        if (id.isEmpty() && email.isEmpty() && username.isEmpty()) {
            Sentry.setUser(null)
            return
        }
        Sentry.setUser(User().apply {
            if (id.isNotEmpty()) this.id = id
            if (email.isNotEmpty()) this.email = email
            if (username.isNotEmpty()) this.username = username
        })
    }

    fun status(): JSONObject {
        val config = activeConfig?.let { runCatching { JSONObject(it) }.getOrNull() }
        val dsn = config?.optString("dsn").orEmpty()
        return JSONObject()
            .put("enabled", Sentry.isEnabled())
            .put("host", runCatching { java.net.URI(dsn).host }.getOrNull().orEmpty())
            .put("environment", config?.optString("environment").orEmpty())
            .put("release", config?.optString("release").orEmpty())
            .put("nativeCrashes", config?.optBoolean("nativeCrashes", true) == true && ndkAvailable())
            .put("anr", config?.optBoolean("anr", true) == true)
            .put("lastEventId", Sentry.getLastEventId().toString().takeIf { it != "00000000000000000000000000000000" }.orEmpty())
            .put("platform", "Android ${android.os.Build.VERSION.SDK_INT}")
            .put("error", lastError)
    }

    private fun exception(json: JSONObject, handled: Boolean): SentryException {
        val exception = SentryException()
        exception.type = json.optString("type", "Error")
        exception.value = json.optString("value")
        val frames = json.optJSONArray("frames") ?: JSONArray()
        exception.stacktrace = SentryStackTrace((0 until frames.length()).map { index ->
            val frame = frames.getJSONObject(index)
            SentryStackFrame().apply {
                filename = frame.optString("file").takeIf { it.isNotEmpty() }
                function = frame.optString("function").takeIf { it.isNotEmpty() }
                module = frame.optString("module").takeIf { it.isNotEmpty() }
                lineno = frame.optInt("line", 0).takeIf { it > 0 }
                platform = "php"
                setInApp(frame.optBoolean("inApp", true))
            }
        })
        exception.mechanism = Mechanism().apply {
            type = json.optString("mechanism", "pam.php")
            setHandled(handled)
        }
        return exception
    }

    private fun level(value: Int) = when (value) {
        1 -> SentryLevel.DEBUG
        2 -> SentryLevel.INFO
        3 -> SentryLevel.WARNING
        5 -> SentryLevel.FATAL
        else -> SentryLevel.ERROR
    }

    private fun ndkAvailable() = runCatching { Class.forName("io.sentry.android.ndk.SentryNdk") }.isSuccess

    private fun preferences(context: Context) =
        (context.applicationContext ?: context).getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
}
