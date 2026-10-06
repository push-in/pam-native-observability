import Foundation
import PamNative
import Sentry
import UIKit

/// Native side of `Observability::exporter()` and the Sentry facade methods,
/// backed by the Sentry Cocoa SDK (crashes, app hangs, PHP events).
public final class ObservabilityModule: NativeModule, ClosableNativeModule, @unchecked Sendable {
    private let worker = DispatchQueue(label: "pam-observability", qos: .utility)

    public init() {
        // Modules are created while the app launches. Restore the persisted
        // exporter from the worker (ahead of every queued PHP call): the SDK
        // start itself hops to the main thread once the launch returned, so
        // it overlaps the PHP boot instead of delaying the first frame
        // (Android parity: SentryBootstrap starts it off the UI thread).
        worker.async { SentryBridge.bootstrap() }
    }

    public func invoke(method: String, payload: Data, completion: @escaping ModuleCompletion) {
        let values: [String: WireValue]
        do {
            values = try WireMap.decode(payload)
        } catch {
            completion(.failure, Data("Invalid payload".utf8))
            return
        }
        worker.async {
            do {
                func text(_ key: String) throws -> String {
                    guard case let .text(value)? = values[key] else { throw ObservabilityError("\(key) is required") }
                    return value
                }
                let result: [String: WireValue]
                switch method {
                case "sentryInit":
                    try SentryBridge.start(try text("config"), persist: true)
                    result = [:]
                case "sentryCapture":
                    result = ["eventId": .text(try SentryBridge.capture(try text("event")))]
                case "sentryBreadcrumb":
                    try SentryBridge.breadcrumb(try text("breadcrumb"))
                    result = [:]
                case "sentryUser":
                    SentryBridge.user(id: try text("id"), email: try text("email"), username: try text("username"))
                    result = [:]
                case "sentryTag":
                    let key = try text("key")
                    let value = try text("value")
                    SentrySDK.configureScope { $0.setTag(value: value, key: key) }
                    result = [:]
                case "sentryFlush":
                    guard case let .integer(timeout)? = values["timeoutMs"] else { throw ObservabilityError("timeoutMs is required") }
                    SentrySDK.flush(timeout: Double(timeout) / 1_000)
                    result = [:]
                case "sentryStatus":
                    result = ["status": .text(SentryBridge.statusJson())]
                case "sentryTest":
                    guard SentrySDK.isEnabled else {
                        throw ObservabilityError(SentryBridge.lastError.isEmpty ? "Sentry exporter is not configured" : SentryBridge.lastError)
                    }
                    let error = NSError(domain: "PamNative", code: 1, userInfo: [
                        NSLocalizedDescriptionKey: "PAM Native Sentry diagnostic exception",
                    ])
                    let id = SentrySDK.capture(error: error).sentryIdString
                    SentryBridge.remember(id)
                    SentrySDK.flush(timeout: 5)
                    result = ["eventId": .text(id)]
                case "sentryStop":
                    SentryBridge.stop()
                    result = [:]
                default:
                    throw ObservabilityError("Unknown observability method: \(method)")
                }
                completion(.success, try WireMap.encode(result))
            } catch {
                completion(.failure, Data(((error as? LocalizedError)?.errorDescription ?? String(describing: error)).utf8))
            }
        }
    }

    public func close() {}
}

struct ObservabilityError: LocalizedError {
    let message: String
    init(_ message: String) { self.message = message }
    var errorDescription: String? { message }
}

/// Owns the process-wide Sentry SDK; the PHP configuration is persisted so the
/// next launch starts Sentry before PHP boots.
enum SentryBridge {
    private static let configKey = "dev.pam.observability.sentry.config"
    private static let lock = NSLock()
    private static var activeConfig: String?
    private(set) static var lastError = ""
    private static var lastEventId = ""

    static func bootstrap() {
        lock.lock()
        let running = activeConfig != nil
        lock.unlock()
        guard !running, let stored = UserDefaults.standard.string(forKey: configKey) else { return }
        try? start(stored, persist: false)
    }

    static func start(_ json: String, persist: Bool) throws {
        guard let config = try JSONSerialization.jsonObject(with: Data(json.utf8)) as? [String: Any],
              let dsn = config["dsn"] as? String, dsn.hasPrefix("https://") || dsn.hasPrefix("http://") else {
            throw ObservabilityError("Invalid Sentry DSN")
        }
        if persist {
            if (config["persist"] as? Bool) ?? true {
                UserDefaults.standard.set(json, forKey: configKey)
            } else {
                UserDefaults.standard.removeObject(forKey: configKey)
            }
        }
        lock.lock()
        let unchanged = json == activeConfig && SentrySDK.isEnabled
        lock.unlock()
        if unchanged { return }
        func text(_ key: String) -> String? { (config[key] as? String).flatMap { $0.isEmpty ? nil : $0 } }
        let start = {
            SentrySDK.start { options in
                options.dsn = dsn
                if let environment = text("environment") { options.environment = environment }
                if let release = text("release") { options.releaseName = release }
                if let dist = text("dist") { options.dist = dist }
                options.sampleRate = NSNumber(value: (config["sampleRate"] as? NSNumber)?.doubleValue ?? 1)
                if let traces = config["tracesSampleRate"] as? NSNumber { options.tracesSampleRate = traces }
                if let profiles = config["profilesSampleRate"] as? NSNumber { options.profilesSampleRate = profiles }
                options.sendDefaultPii = (config["sendDefaultPii"] as? Bool) ?? false
                options.debug = (config["debug"] as? Bool) ?? false
                options.maxBreadcrumbs = UInt(max((config["maxBreadcrumbs"] as? NSNumber)?.intValue ?? 100, 0))
                options.enableAppHangTracking = (config["anr"] as? Bool) ?? true
                options.appHangTimeoutInterval = Double((config["anrTimeoutMs"] as? NSNumber)?.int64Value ?? 5_000) / 1_000
                options.enableCrashHandler = (config["nativeCrashes"] as? Bool) ?? true
                options.maxCacheItems = 60
                let tags = (config["tags"] as? [String: Any] ?? [:]).compactMapValues { $0 as? String }
                options.initialScope = { scope in
                    scope.setTag(value: "pam-native", key: "pam.runtime")
                    tags.forEach { scope.setTag(value: $1, key: $0) }
                    return scope
                }
            }
        }
        // The SDK installs UIKit integrations; start it on the main thread.
        if Thread.isMainThread { start() } else { DispatchQueue.main.sync(execute: start) }
        lock.lock()
        activeConfig = SentrySDK.isEnabled ? json : nil
        lastError = SentrySDK.isEnabled ? "" : "Sentry did not start"
        lock.unlock()
    }

    static func stop() {
        UserDefaults.standard.removeObject(forKey: configKey)
        lock.lock()
        activeConfig = nil
        lock.unlock()
        SentrySDK.close()
    }

    static func remember(_ id: String) {
        lock.lock()
        lastEventId = id
        lock.unlock()
    }

    /// Builds a Sentry event from a PHP throwable / message payload.
    static func capture(_ json: String) throws -> String {
        guard SentrySDK.isEnabled else {
            throw ObservabilityError(lastError.isEmpty ? "Sentry exporter is not configured" : lastError)
        }
        guard let payload = try JSONSerialization.jsonObject(with: Data(json.utf8)) as? [String: Any] else {
            throw ObservabilityError("Invalid event")
        }
        let event = Event(level: level((payload["level"] as? NSNumber)?.intValue ?? 4))
        event.platform = "php"
        event.logger = payload["logger"] as? String ?? "pam.php"
        if let message = payload["message"] as? String, !message.isEmpty {
            event.message = SentryMessage(formatted: message)
        }
        let handled = (payload["handled"] as? Bool) ?? true
        if let exceptions = payload["exceptions"] as? [[String: Any]] {
            event.exceptions = exceptions.map { exception($0, handled: handled) }
        }
        if let tags = payload["tags"] as? [String: Any] {
            event.tags = tags.compactMapValues { $0 as? String }
        }
        if let extra = payload["extra"] as? [String: Any] {
            event.extra = extra.mapValues { "\($0)" }
        }
        if let fingerprint = payload["fingerprint"] as? String, !fingerprint.isEmpty {
            event.fingerprint = [fingerprint]
        }
        let id = SentrySDK.capture(event: event).sentryIdString
        remember(id)
        return id
    }

    static func breadcrumb(_ json: String) throws {
        guard let payload = try JSONSerialization.jsonObject(with: Data(json.utf8)) as? [String: Any] else {
            throw ObservabilityError("Invalid breadcrumb")
        }
        let crumb = Breadcrumb(level: level((payload["level"] as? NSNumber)?.intValue ?? 2), category: payload["category"] as? String ?? "app")
        crumb.message = payload["message"] as? String
        crumb.type = payload["type"] as? String ?? "default"
        if let data = payload["data"] as? [String: Any] {
            crumb.data = data.mapValues { "\($0)" }
        }
        SentrySDK.addBreadcrumb(crumb)
    }

    static func user(id: String, email: String, username: String) {
        guard !id.isEmpty || !email.isEmpty || !username.isEmpty else {
            SentrySDK.setUser(nil)
            return
        }
        let user = User()
        if !id.isEmpty { user.userId = id }
        if !email.isEmpty { user.email = email }
        if !username.isEmpty { user.username = username }
        SentrySDK.setUser(user)
    }

    static func statusJson() -> String {
        lock.lock()
        let config = activeConfig.flatMap { try? JSONSerialization.jsonObject(with: Data($0.utf8)) as? [String: Any] }
        let error = lastError
        let eventId = lastEventId
        lock.unlock()
        let dsn = config?["dsn"] as? String ?? ""
        let status: [String: Any] = [
            "enabled": SentrySDK.isEnabled,
            "host": URL(string: dsn)?.host ?? "",
            "environment": config?["environment"] as? String ?? "",
            "release": config?["release"] as? String ?? "",
            "nativeCrashes": (config?["nativeCrashes"] as? Bool) ?? (config != nil),
            "anr": (config?["anr"] as? Bool) ?? (config != nil),
            "lastEventId": eventId,
            "platform": "iOS \(UIDevice.current.systemVersion)",
            "error": error,
        ]
        let data = (try? JSONSerialization.data(withJSONObject: status)) ?? Data("{}".utf8)
        return String(decoding: data, as: UTF8.self)
    }

    private static func exception(_ json: [String: Any], handled: Bool) -> Sentry.Exception {
        let exception = Sentry.Exception(value: json["value"] as? String ?? "", type: json["type"] as? String ?? "Error")
        let frames = (json["frames"] as? [[String: Any]] ?? []).map { item -> Frame in
            let frame = Frame()
            frame.fileName = (item["file"] as? String).flatMap { $0.isEmpty ? nil : $0 }
            frame.function = (item["function"] as? String).flatMap { $0.isEmpty ? nil : $0 }
            frame.module = (item["module"] as? String).flatMap { $0.isEmpty ? nil : $0 }
            if let line = (item["line"] as? NSNumber)?.intValue, line > 0 { frame.lineNumber = NSNumber(value: line) }
            frame.platform = "php"
            frame.inApp = NSNumber(value: (item["inApp"] as? Bool) ?? true)
            return frame
        }
        exception.stacktrace = SentryStacktrace(frames: frames, registers: [:])
        let mechanism = Mechanism(type: json["mechanism"] as? String ?? "pam.php")
        mechanism.handled = NSNumber(value: handled)
        exception.mechanism = mechanism
        return exception
    }

    private static func level(_ value: Int) -> SentryLevel {
        switch value {
        case 1: return .debug
        case 2: return .info
        case 3: return .warning
        case 5: return .fatal
        default: return .error
        }
    }
}
