import Foundation
import PamNative
import Sentry
import XCTest
// Generated plugin target: PamPlugin<index>PushinbrPamNativeObservability (index = plugin order).
@testable import PamPlugin0PushinbrPamNativeObservability

/// XCTest mirror of SentryExporterInstrumentedTest (loopback envelope server
/// omitted: asserts SDK state and event ids). Uncompiled — needs Mac validation.
final class SentryBridgeTests: XCTestCase {
    private let module = ObservabilityModule()

    override func tearDown() {
        SentryBridge.stop()
        super.tearDown()
    }

    private func call(_ method: String, _ values: [String: WireValue]) -> (ok: Bool, values: [String: WireValue], message: String) {
        let done = expectation(description: method)
        var result: (Bool, [String: WireValue], String) = (false, [:], "")
        module.invoke(method: method, payload: (try? WireMap.encode(values)) ?? Data()) { status, payload in
            result = (status == .success, (try? WireMap.decode(payload)) ?? [:], String(decoding: payload, as: UTF8.self))
            done.fulfill()
        }
        wait(for: [done], timeout: 10)
        return result
    }

    func testInitCaptureBreadcrumbUserStatusAndStop() throws {
        let config = #"{"dsn":"https://key@127.0.0.1:9/1","environment":"test","release":"app@1","tags":{"team":"chat"},"persist":true}"#
        XCTAssertTrue(call("sentryInit", ["config": .text(config)]).ok)
        XCTAssertTrue(SentrySDK.isEnabled)
        XCTAssertNotNil(UserDefaults.standard.string(forKey: "dev.pam.observability.sentry.config"))
        XCTAssertTrue(call("sentryBreadcrumb", ["breadcrumb": .text(#"{"message":"opened chat","category":"nav","level":2,"data":{"id":7}}"#)]).ok)
        XCTAssertTrue(call("sentryUser", ["id": .text("u1"), "email": .text(""), "username": .text("ana")]).ok)
        XCTAssertTrue(call("sentryTag", ["key": .text("screen"), "value": .text("chat")]).ok)
        let capture = call("sentryCapture", ["event": .text(#"{"level":4,"message":"boom","handled":false,"exceptions":[{"type":"RuntimeException","value":"boom","frames":[{"file":"app/Chat.php","function":"send","line":42}]}],"fingerprint":"chat-send"}"#)])
        XCTAssertTrue(capture.ok, capture.message)
        guard case let .text(id)? = capture.values["eventId"] else { return XCTFail("eventId") }
        XCTAssertEqual(id.count, 32)
        guard case let .text(status)? = call("sentryStatus", [:]).values["status"],
              let json = try JSONSerialization.jsonObject(with: Data(status.utf8)) as? [String: Any] else { return XCTFail("status") }
        XCTAssertEqual(json["enabled"] as? Bool, true)
        XCTAssertEqual(json["host"] as? String, "127.0.0.1")
        XCTAssertEqual(json["lastEventId"] as? String, id)
        XCTAssertTrue(call("sentryFlush", ["timeoutMs": .integer(500)]).ok)
        XCTAssertTrue(call("sentryStop", [:]).ok)
        XCTAssertFalse(SentrySDK.isEnabled)
        XCTAssertNil(UserDefaults.standard.string(forKey: "dev.pam.observability.sentry.config"))
    }

    func testCaptureWithoutExporterFailsAndInvalidDsnIsRejected() {
        XCTAssertFalse(call("sentryCapture", ["event": .text("{}")]).ok)
        XCTAssertFalse(call("sentryInit", ["config": .text(#"{"dsn":"ftp://x"}"#)]).ok)
        XCTAssertFalse(call("sentryTest", [:]).ok)
        XCTAssertFalse(call("unknown", [:]).ok)
    }
}
