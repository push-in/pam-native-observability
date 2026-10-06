package dev.pam.observability

import android.content.Context
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import dev.pam.nativeapp.modules.ModuleCompletion
import dev.pam.nativeapp.modules.ModuleResultStatus
import dev.pam.nativeapp.protocol.WireMap
import dev.pam.nativeapp.protocol.WireValue
import io.sentry.Sentry
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import java.util.zip.GZIPInputStream
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.json.JSONArray
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class SentryExporterInstrumentedTest {
    private val context: Context = InstrumentationRegistry.getInstrumentation().targetContext
    private val server = MockWebServer()
    private val envelopes = CopyOnWriteArrayList<String>()
    private lateinit var module: ObservabilityModule

    private data class Result(val ok: Boolean, val values: Map<String, WireValue>, val message: String)

    @Before
    fun start() {
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                val bytes = request.body.readByteArray()
                val text = if (request.getHeader("Content-Encoding") == "gzip") GZIPInputStream(bytes.inputStream()).readBytes().decodeToString() else bytes.decodeToString()
                envelopes += text
                return MockResponse().setResponseCode(200).setBody("{}")
            }
        }
        server.start()
        module = ObservabilityModule(context)
    }

    @After
    fun stop() {
        call("sentryStop")
        module.close()
        runCatching { server.shutdown() }
    }

    private fun call(method: String, vararg values: Pair<String, WireValue>): Result {
        val latch = CountDownLatch(1)
        val result = AtomicReference<Result>()
        module.invoke(method, WireMap.encode(mapOf(*values)), ModuleCompletion { status, payload ->
            result.set(if (status == ModuleResultStatus.SUCCESS) Result(true, WireMap.decode(payload), "") else Result(false, emptyMap(), String(payload)))
            latch.countDown()
        })
        assertTrue("$method timed out", latch.await(20, TimeUnit.SECONDS))
        return result.get()
    }

    private fun init() {
        val dsn = "http://public@${server.hostName}:${server.port}/42"
        val config = JSONObject()
            .put("dsn", dsn)
            .put("environment", "test")
            .put("release", "app@1.2.3")
            .put("tracesSampleRate", 0.25)
            .put("profilesSampleRate", 0.05)
            .put("tags", JSONObject().put("app", "pam-test"))
        val result = call("sentryInit", "config" to WireValue.Text(config.toString()))
        assertTrue(result.message, result.ok)
    }

    private fun status() = JSONObject((call("sentryStatus").values["status"] as WireValue.Text).value)

    private fun event(id: String): JSONObject {
        val deadline = System.currentTimeMillis() + 15_000
        while (System.currentTimeMillis() < deadline) {
            envelopes.forEach { envelope ->
                envelope.lineSequence().filter { it.startsWith("{") }.mapNotNull { runCatching { JSONObject(it) }.getOrNull() }
                    .firstOrNull { it.optString("event_id") == id && it.has("platform") }?.let { return it }
            }
            Thread.sleep(100)
        }
        throw AssertionError("Event $id not delivered; envelopes=${envelopes.size}")
    }

    @Test
    fun initializesTheSdkWithNativeCrashCaptureAndPersistsTheConfiguration() {
        init()
        val status = status()
        assertTrue(status.getBoolean("enabled"))
        assertEquals(server.hostName, status.getString("host"))
        assertEquals("test", status.getString("environment"))
        assertTrue("NDK integration available", status.getBoolean("nativeCrashes"))
        assertTrue(status.getBoolean("anr"))
        assertEquals(0.05, Sentry.getCurrentScopes().options.profilesSampleRate!!, 0.0)
        assertEquals(0.25, Sentry.getCurrentScopes().options.tracesSampleRate!!, 0.0)
        val stored = context.getSharedPreferences("dev.pam.observability.sentry", Context.MODE_PRIVATE).getString("config", null)
        assertTrue(stored!!.contains("app@1.2.3"))

        call("sentryStop")
        assertFalse(status().getBoolean("enabled"))
        assertEquals(null, context.getSharedPreferences("dev.pam.observability.sentry", Context.MODE_PRIVATE).getString("config", null))
        assertFalse(call("sentryCapture", "event" to WireValue.Text("{}")).ok)
    }

    @Test
    fun forwardsPhpThrowablesWithPhpFramesBreadcrumbsAndUser() {
        init()
        call("sentryUser", "id" to WireValue.Text("42"), "email" to WireValue.Text(""), "username" to WireValue.Text("ana"))
        call("sentryTag", "key" to WireValue.Text("screen"), "value" to WireValue.Text("chat"))
        call("sentryBreadcrumb", "breadcrumb" to WireValue.Text(JSONObject().put("message", "Opened chat 42").put("category", "navigation").put("level", 2).toString()))
        val payload = JSONObject()
            .put("level", 4)
            .put("handled", false)
            .put("tags", JSONObject().put("php.version", "8.5.0"))
            .put("exceptions", JSONArray().put(
                JSONObject().put("type", "DomainException").put("value", "Chat not found").put("mechanism", "pam.php.uncaught")
                    .put("frames", JSONArray()
                        .put(JSONObject().put("file", "src/App.php").put("line", 10).put("function", "{main}"))
                        .put(JSONObject().put("file", "src/Chat/ChatScreen.php").put("line", 88).put("function", "load").put("module", "App\\Chat\\ChatScreen"))),
            ))
        val captured = call("sentryCapture", "event" to WireValue.Text(payload.toString()))
        assertTrue(captured.message, captured.ok)
        val id = (captured.values["eventId"] as WireValue.Text).value
        call("sentryFlush", "timeoutMs" to WireValue.Integer(5_000))

        val event = event(id)
        assertEquals("php", event.getString("platform"))
        assertEquals("app@1.2.3", event.getString("release"))
        assertEquals("chat", event.getJSONObject("tags").getString("screen"))
        assertEquals("pam-test", event.getJSONObject("tags").getString("app"))
        assertEquals("ana", event.getJSONObject("user").getString("username"))
        val exception = event.getJSONObject("exception").getJSONArray("values").getJSONObject(0)
        assertEquals("DomainException", exception.getString("type"))
        assertFalse(exception.getJSONObject("mechanism").getBoolean("handled"))
        val frames = exception.getJSONObject("stacktrace").getJSONArray("frames")
        assertEquals("src/Chat/ChatScreen.php", frames.getJSONObject(1).getString("filename"))
        assertEquals(88, frames.getJSONObject(1).getInt("lineno"))
        val crumbs = event.getJSONArray("breadcrumbs").toString()
        assertTrue(crumbs.contains("Opened chat 42"))
    }

    @Test
    fun diagnosticTestEventIsDelivered() {
        init()
        val result = call("sentryTest")
        assertTrue(result.message, result.ok)
        val event = event((result.values["eventId"] as WireValue.Text).value)
        assertTrue(event.toString().contains("PAM Native Sentry diagnostic exception"))
    }
}
