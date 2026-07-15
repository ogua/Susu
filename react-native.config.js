module.exports = {
  dependencies: {
    // @nozbe/simdjson ships its own podspec, so RN autolinking picks it up
    // automatically on iOS. @morrowdigital/watermelondb-expo-plugin also adds
    // it manually to the Podfile (with modular_headers: true, which simdjson
    // needs). Having both causes a CocoaPods "multiple dependencies with
    // different sources for `simdjson`" error, so disable autolinking here
    // and let the config plugin own it.
    '@nozbe/simdjson': {
      platforms: {
        ios: null,
      },
    },
  },
};
