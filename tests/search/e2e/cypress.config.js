const {
  defineConfig
} = require('cypress')

const {
  readConfig
} = require('@wordpress/env/lib/config');

module.exports = defineConfig({
  e2e: {
    async setupNodeEvents(on, config) {
      require('@cypress/grep/src/plugin')(config);

      const wpEnvConfig = await readConfig('wp-env');

      if (wpEnvConfig) {
        const port = wpEnvConfig.env.tests.port || null;

        if (port) {
          config.baseUrl = wpEnvConfig.env.tests.config.WP_SITEURL;
        }
      }

      // Account for ElasticPress and elasticpress usages.
      config.pluginName = process.cwd().split('/').pop();

      return config;
    },
    experimentalSessionAndOrigin: true,
    supportFile: "tests/search/e2e/support/index.js",
    specPattern: "tests/search/e2e/**/*.spec.{js,jsx,ts,tsx}",
    fixturesFolder: "tests/search/e2e/fixtures",
    screenshotsFolder: "tests/search/e2e/screenshots",
    videosFolder: "tests/search/e2e/videos",
    downloadsFolder: "tests/search/e2e/downloads",
    video: false,
    env: {
      "grepFilterSpecs": true,
      "grepOmitFiltered": true,
    },
    retries: {
      "runMode": 1
    },
    elasticPressIndexTimeout: 100000,
    numTestsKeptInMemory: 0,
    reporter: 'cypress-multi-reporters',
    reporterOptions: {
      configFile: 'tests/search/e2e/cypress-reporter-config.json'
    },
  },
})