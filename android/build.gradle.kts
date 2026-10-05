plugins {
    id("com.android.library") version "9.2.0"
}

android {
    namespace = "dev.pam.observability"
    compileSdk = 36

    defaultConfig {
        minSdk = 26
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        consumerProguardFiles("consumer-rules.pro")
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    lint {
        abortOnError = true
        warningsAsErrors = true
        disable += setOf("AndroidGradlePluginVersion", "GradleDependency", "NewerVersionAvailable")
    }
}

dependencies {
    api(project(":plugin-api"))
    implementation("io.sentry:sentry-android-core:8.50.1")
    implementation("io.sentry:sentry-android-ndk:8.50.1")
    androidTestImplementation("com.squareup.okhttp3:mockwebserver:5.3.0")
    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.test:runner:1.7.0")
}

// Keep framework sources read-only: plugin-api outputs live in this harness.
subprojects {
    layout.buildDirectory.set(rootProject.layout.buildDirectory.dir("subprojects/$name"))
}
