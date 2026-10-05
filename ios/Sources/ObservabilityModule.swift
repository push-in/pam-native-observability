import Foundation
import PamNative

/// The native Sentry exporter ships for Android first. On iOS every exporter
/// call reports a module failure, so PHP code stays portable and the PHP
/// forwarding becomes a no-op.
public final class ObservabilityModule: NativeModule, @unchecked Sendable {
    public init() {}

    public func invoke(method: String, payload: Data, completion: @escaping ModuleCompletion) {
        completion(.failure, Data("The Sentry exporter is not available on iOS yet (\(method)).".utf8))
    }
}
