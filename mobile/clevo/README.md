# CLEVO unified mobile application

One Flutter project for Customer, Station Owner and Driver. PHP/MySQL and the web Admin dashboard are preserved. Roles and object permissions remain enforced by the PHP API.

Requirements: Flutter 3.50.0 / Dart 3.14, Java 21, Android SDK 36, Android NDK 28.2.13676358. Android minimum 24; iOS minimum 15.0. The resolved dependency versions are committed in pubspec.lock.

From this directory:

```sh
flutter pub get
flutter analyze
flutter test test/api_test.dart test/policy_test.dart test/app_test.dart test/tracking_test.dart
```

Copy config/example.json to ignored config/local.json and set the real HTTPS URL ending /api/v1/. Do not put backend secrets in mobile configuration. The default clevo.invalid API hostname is deliberately unconfigured; the application contains no fake production data.

```sh
flutter run --dart-define-from-file=config/local.json
flutter build apk --debug --target-platform android-arm64 --dart-define-from-file=config/local.json
```

Debug local HTTP requires CLEVO_LOCAL_HTTP=true and an exact localhost, 127.0.0.1 or Android emulator 10.0.2.2 API host. Android debug network policy allows those hosts only. Release configurations always require HTTPS. iOS local development should use trusted HTTPS.

Android's test artifact is build/app/outputs/flutter-apk/app-debug.apk. It uses the SDK debug certificate and is not a store release. For release signing, copy android/key.example.properties to ignored android/key.properties and configure your own private keystore. Release tasks fail when signing configuration is absent; there is no debug-signing fallback. Never commit signing passwords or keystores.

Firebase is opt-in: add your real ignored android/app/google-services.json and ios/Runner/GoogleService-Info.plist; enable CLEVO_FIREBASE only for that registered project. Enable notification permission explicitly inside the app. Configure APNs in Firebase/Xcode before iOS device testing. iOS uses the UIScene-compatible FlutterFire notification delegate setup. Firebase service-account credentials belong only on PHP.

Maps are opt-in: enable CLEVO_MAPS and supply the restricted Android SDK key through CLEVO_ANDROID_MAPS_KEY, and the iOS SDK key in ignored ios/Flutter/Secrets.xcconfig. Restrict keys to the approved application ID, certificate/bundle and SDK. No server API keys are embedded. GPS sharing is foreground only, assignment-scoped, explicitly enabled by the driver, and stops when the screen is covered or the app backgrounds.

On a compatible macOS machine with Xcode and CocoaPods:

```sh
flutter pub get
cd ios
pod install
cd ..
flutter build ios --debug --no-codesign --dart-define-from-file=config/local.json
```

Open ios/Runner.xcworkspace for Apple team, bundle ownership, signing and Push capability. The optional Runner.entitlements template is enabled through the local Secrets.xcconfig after APNs provisioning; set CLEVO_APNS_ENVIRONMENT to development or production as appropriate. A physical iPhone is required to qualify push delivery, secure storage and location permissions. This Windows workspace cannot produce or verify an iOS binary. Replace generated launcher/store icons with approved CLEVO store artwork before distribution; the in-app logo reuses the existing project asset.

Run real PHP/MySQL integration from the repository root (PowerShell):

```powershell
$env:PUB_CACHE = "$PWD/.mobile-tools/pub-cache" # optional local tool cache
./tests/run-isolated.ps1 -Flutter 'absolute/path/to/flutter.bat'
```

That runner creates only disposable databases in .security-test on port 13379, with PHP on 18089. It prepares ephemeral role accounts and private fixture credentials, then runs the Dart live API suite. No commercial database, real customers, payment credentials or messages are used. Running the normal test directory without this runner explicitly skips the live-API suite. After the PHP suites pass, append `-MobileOnly` for a focused live-client rerun; it still creates an isolated schema/auth setup and explicitly skips the already-verified business suites.

See ../../docs/MOBILE_ARCHITECTURE.md, MOBILE_API_MATRIX.md and MOBILE_TEST_REPORT.md for implementation mapping, backend gaps and verified results. No production readiness is claimed until the listed device, provider, configuration, signing and operational gates pass.
