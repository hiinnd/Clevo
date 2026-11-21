import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val releaseProperties = Properties()
val releasePropertiesFile = rootProject.file("key.properties")
if (releasePropertiesFile.exists()) {
    releasePropertiesFile.inputStream().use { releaseProperties.load(it) }
}
gradle.taskGraph.whenReady {
    if (allTasks.any { it.project.path == project.path && it.name.contains("release", ignoreCase = true) }) {
        require(releasePropertiesFile.exists()) { "Release signing requires private android/key.properties. Debug signing is never used for release." }
        for (key in listOf("storeFile", "storePassword", "keyAlias", "keyPassword")) {
            require(!releaseProperties.getProperty(key).isNullOrBlank()) { "Missing release signing configuration: $key" }
        }
    }
}

// Native Firebase configuration is opt-in; no placeholder project is bundled.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

android {
    namespace = "com.clevo.clevo"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.clevo.clevo"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = 24
        // Keep dependency JNI libraries consistent with the selected Flutter engine.
        // Without this, an ARM64-only test build can advertise unsupported ABIs.
        val requestedPlatforms = (project.findProperty("target-platform") as String?)?.split(",") ?: emptyList()
        val flutterAbis = mapOf("android-arm" to "armeabi-v7a", "android-arm64" to "arm64-v8a", "android-x64" to "x86_64")
        val splitPerAbi = project.findProperty("split-per-abi")?.toString()?.toBoolean() ?: false
        if (requestedPlatforms.isNotEmpty() && !splitPerAbi) {
            require(requestedPlatforms.all { flutterAbis.containsKey(it) }) { "Unsupported Android target platform" }
            // Flutter 3.50 otherwise resets these filters to all supported ABIs.
            project.extensions.extraProperties.set("disable-abi-filtering", true)
            ndk.abiFilters.clear()
            ndk.abiFilters.addAll(requestedPlatforms.map { flutterAbis.getValue(it) })
        }
        manifestPlaceholders["googleMapsApiKey"] = System.getenv("CLEVO_ANDROID_MAPS_KEY") ?: ""
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (releasePropertiesFile.exists()) {
            create("production") {
                storeFile = file(releaseProperties.getProperty("storeFile"))
                storePassword = releaseProperties.getProperty("storePassword")
                keyAlias = releaseProperties.getProperty("keyAlias")
                keyPassword = releaseProperties.getProperty("keyPassword")
            }
        }
    }
    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("production")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    constraints {
        // Flutter's integration_test Android plugin brings older runtime libraries.
        // Keep its tooling without retaining known vulnerable transitive versions.
        implementation("junit:junit:4.13.2") {
            because("Fix CVE-2020-15250 in the transitive integration-test runtime")
        }
        implementation("com.google.guava:guava:33.7.2-android") {
            because("Fix Guava temporary-file advisories and GHSA-xxph-c9ww-hj94")
        }
    }
}
