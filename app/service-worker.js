const CACHE_NAME = 'tradity-v0.7.0';
const ASSETS_TO_CACHE = [
    '/app/static/css/distv0.7.0/my-styles.css',
    '/app/static/css/distv0.7.0/admin-more-styles.css',
    '/app/static/css/distv0.7.0/admin-style.css',
    '/app/static/css/distv0.7.0/loader.css',
    '/app/static/css/core.8101.7635118be0daf6f0feff.main.css',
    '/app/static/css/core.chunk.6408.ea813ca5196078e4b5dc.css',
    '/app/static/css/core.chunk.8283.e093a20f556e53514056.css',
    '/app/static/css/core.chunk.account-wizard-modal.c7d53b4a914f817f24f5.css',
    '/app/static/css/core.chunk.core_real_account_signup.8dc03a0a0cdf350d891c.css',
    '/app/static/css/core.chunk.traders-hub-header.dae916ddf09ce659571c.css',
    '/app/static/css/core.main.c93ad11dbdb15865237d.main.css',
    '/app/static/css/login-styles.css',
    '/app/static/images/404.png',
    '/app/static/images/dashboard.eyeslash.svg',
    '/app/static/images/dashboard.eyeunslash.svg',
    '/app/static/images/logo.png',
    '/app/static/images/logo-full.png',
    '/app/static/images/notif-alert-icon.png',
    '/app/static/images/notif-bg.png',
    '/app/static/images/register_success.gif',
    '/app/static/images/cashier-demo-dark.605d2c476f169a7b73a8c7ea5312606b.svg',
    '/app/static/images/cashier-demo-light.e69b0cb941dd65480866365959a517ae.svg',
    '/app/static/images/dashboard.boldGlobe__XyKom2DoZ56AuEEzi8grA.svg',
    '/app/static/images/dashboard.close_dark__MIRDmQwHcPxlCA0zmb00g.svg',
    '/app/static/images/dashboard.iconstatus_1___Aw7E24a8coojcJcNVpOlg.svg',
    '/app/static/images/dashboard.icon_10___LBCcwTgJ7zUbBAz7WI9lg.svg',
    '/app/static/images/dashboard.icon_9___MXXDOKkDM2tAivmQNyXw.svg',
    '/app/static/images/tradershub-select-check.webp',
    '/app/static/images/tradershub.arrowleft__mobilekyckenu.svg',
    '/app/static/images/tradershub.closeicon__mobilekyckenu.svg',
    '/app/static/images/tradershub.content__passport_sample.svg',
    '/app/static/images/tradershub.darkclosemd.svg',
    '/app/static/images/tradershub.DOB3__tn9xudxiMJlKPIDrIaHuuA.svg',
    '/app/static/images/tradershub.icon.arrow-right.svg',
    '/app/static/images/tradershub.iconarrow__mobilekyckenu.svg',
    '/app/static/images/tradershub.info_black.svg',
    '/app/static/images/tradershub.standaloneArrowLeft.svg',
    '/app/static/images/tradershub.StandaloneArrowLeft__desktop.svg',
    '/app/static/images/tradershub.standaloneMDClose.svg',
    '/app/static/images/tradershub.usdollar.svg',
    '/app/static/images/tradershub_passport_sample_desktop.webp',
    '/app/static/images/login-slider/anyone.png',
    '/app/static/images/login-slider/anyone2.png',
    '/app/static/images/login-slider/assets.png',
    '/app/static/images/login-slider/deposit.png',
    '/app/static/images/payments/coinpayments.png',
    '/app/static/images/payments/faucetpay.png',
    '/app/static/images/payments/flutterwave.png',
    '/app/static/images/payments/stripe.png',
    '/app/static/images/svg/common.svg',
    '/app/static/images/svg/sprite.svg',
    '/app/static/jquery/jquery.js',
    '/app/static/jquery/jquery3.1.0.min.js',
    '/app/static/library/i18next.min.js',
    '/app/static/library/i18nextHttpBackend.min.js',
    '/app/static/library/esm/i18next-http-backend.js',
    '/app/static/library/esm/i18next.js',
    '/app/static/account/css/account-app.998b60eb8021ee2dc9f6.css',
    '/app/static/account/css/Sections_Security_SelfExclusion_index_ts.b39d7707c554d6c76e96.css',
    '/app/static/account/css/Sections_Security_TwoFactorAuthentication_index_ts.4fe613ded1d23dcfc650.css',
    '/app/static/account/css/vendors-node_modules_binary-com_binary-document-uploader_DocumentUploader_js-node_modules_i18-450278.81cbaa1499fdcdb35494.css',
    '/app/static/account_creation/OutSystemsReactWidgets__XHmw1ie4RjNpaemnbpQ.css',
    '/app/static/account_creation/OutSystemsUI.OutSystemsUI__MltntvKpHaE2AjK6Mslabw.css',
    '/app/static/account_creation/tradershub.AccountCreation.extra__EHsyMbbWnNNszQnt0gsdpw.css',
    '/app/static/account_creation/tradershub.AccountCreation__rZXOBQSxBwiVNzckcF9Fg.css',
    '/app/static/account_creation/tradershub.Common.LoaderBlock__Gk8aGJE0deOG7Ahk1cnZQ.css',
    '/app/static/account_creation/tradershub.Layouts.RealAccountCreationLayout__j4NKol3wE2ZidC0H9qEijQ.css',
    '/app/static/account_creation/tradershub.RealAccountCreation.CurrencySelection__cjqvDSMoz1evgPbBzJidnw.css',
    '/app/static/account_creation/tradershub.RealAccountCreation.TermsOfUse__j0Bjo19lUlbkM3RzanztUQ.css',
    '/app/static/account_creation/tradershub.RealAccountCreationBlocks.AccountCurrencyBlock__AaXwAvbloEciY5uSYJZA.css',
    '/app/static/account_creation/TradersHubInternationalPhoneInput.Patterns.InternationalTelephoneInput__PoEbS1LPjSoyndhx4YlQQ.css',
    '/app/static/account_creation/_Basic__EqGzAe81QbZLXJyfY3oLwA.css',
    '/app/static/appstore/css/864.3b1aa867d2436f6fa084.css',
    '/app/static/appstore/css/appstore.72cacf2afc33cd034857.css',
    '/app/static/appstore/css/Components_banners_deposit-now-banner.a184c5c21c2d7e475ef9.css',
    '/app/static/appstore/css/modules-traders-hub.7199535916998f75ffb9.css',
    '/app/static/bot_trading/224.943f1ac1.css',
    '/app/static/bot_trading/310.23fbe182.css',
    '/app/static/bot_trading/40.69850dd6.css',
    '/app/static/bot_trading/749.3a1e38d1.css',
    '/app/static/bot_trading/813.7b5680c9.css',
    '/app/static/bot_trading/851.c6dfd1f7.css',
    '/app/static/bot_trading/926.9b00f992.css',
    '/app/static/bot_trading/index.85db4033.css',
    '/app/static/cashier/css/cashier-app.947354d6586b1468e358.css',
    '/app/static/cashier/css/vendors-node_modules_deriv_deriv-api_dist_DerivAPIBasic_js-node_modules_bowser_es5_js-node_mo-01b606.07de7770fe8e5b1c3571.css',
    '/app/static/login/dashboard.Common.CodeNotReceived__XNg1JKE7R8q5f0am9Xg.css',
    '/app/static/login/dashboard.Common.Email_Phone__2FxNmVfJJnnuWyg8i0MTUw.css',
    '/app/static/login/dashboard.Common.ItemSelectionList__yMA9ceormygatzSFkpl3og.css',
    '/app/static/login/dashboard.Common.OTPHeader__o327HCBYMUodhILat7mzDg.css',
    '/app/static/login/dashboard.Common.ScheduledMaintainance__0KPQDsfqxuQi7GklsP5uA.css',
    '/app/static/login/dashboard.Common.SignUpLayout__Zhx34o5ERGhY5s6vvkOg.css',
    '/app/static/login/dashboard.Common.TOTP__yTQ0vmh2XFMQ9PwGUiITg.css',
    '/app/static/login/dashboard.Layouts.LayoutAuthentication__yB1dIjk5yLZx3ZSM90jMpQ.css',
    '/app/static/login/dashboard.ORY.loginORY__hMtP6tJtY9jfDBOlfjg.css',
    '/app/static/login/dashboard.ORY.LoginWithPasswordORY__WM7JoNrUqn7yC4NZKBV3ow.css',
    '/app/static/login/dashboard.ORY.ORYSocialLogin__EvuM3kJ9A0FaScnboRWNWw.css',
    '/app/static/login/dashboard.ORY.VerifyORY__Sx9Kg6L9r7MiFWcJ4jc7Q.css',
    '/app/static/login/dashboard.TradityV2.extra__EHsyMbbWnNNszQnt0gsdpw.css',
    '/app/static/login/dashboard.TradityV2__RcMO908cQTlBIj9wU8KwbQ.css',
    '/app/static/login/OutSystemsUI.OutSystemsUI__frOaAGtX4TPsEACCkSn6w.css',
    '/app/static/reports/css/reports.reports-app.cda41944191fbab2b5de.css',
    '/app/static/reports/css/reports.reports-routes.c923d95ac2b31e3c9c4b.css',
    '/app/static/svgs/traders-hub-logged-out-banner-bg-desktop.6610521ee6365c1472d4.svg',
    '/app/static/trader/css/trader.screen-large.c9c70905347ce1438ce0.css',
    '/app/static/trader/css/trader.src_App_Components_Elements_PositionsDrawer_helpers_index_ts-src_App_init-store_ts-src_Module-64739f.2f8e43eb6d2866483e34.css',
    '/app/static/trader/css/trader.src_App_Components_Elements_PositionsDrawer_helpers_index_ts-src_App_init-store_ts-src_Module-64739f.7d5cee09e87c0d2aaacf.css',
    '/app/static/trader/css/trader.trader-app-v2.3aba9491016ad941d558.css',
    '/app/static/trader/css/trader.trader-app.977af48ec48ce7abf95a.css',
    '/app/static/trader/css/trader.vendors-node_modules_cloudflare_stream-react_dist_stream-react_esm_js-node_modules_deriv_quil-145140.7eb7159cd7e31cfb1a83.css',
    '/app/static/vendor/apexcharts/dist/apexcharts.css',
    '/app/static/vendor/apexcharts/dist/apexcharts.js',
    '/app/static/vendor/bootstrap/js/bootstrap.bundle.min.js',
    '/app/static/vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
    '/app/static/vendor/bootstrap-select/dist/js/bootstrap-select.min.js',
    '/app/static/vendor/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.css',
    '/app/static/vendor/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.js',
    '/app/static/vendor/chart-js/chart.min.js',
    '/app/static/vendor/flaticon/flaticon.css',
    '/app/static/vendor/flaticon/flaticon1486.eot',
    '/app/static/vendor/flaticon/flaticon1486.svg',
    '/app/static/vendor/flaticon/flaticon1486.ttf',
    '/app/static/vendor/flaticon/flaticon1486.woff',
    '/app/static/vendor/flaticon/flaticon1486.woff2',
    '/app/static/vendor/fontawesome/css/all.min.css',
    '/app/static/vendor/fontawesome/webfonts/fa-brands-400.ttf',
    '/app/static/vendor/fontawesome/webfonts/fa-brands-400.woff2',
    '/app/static/vendor/fontawesome/webfonts/fa-regular-400.ttf',
    '/app/static/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    '/app/static/vendor/fontawesome/webfonts/fa-solid-900.ttf',
    '/app/static/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    '/app/static/vendor/fontawesome/webfonts/fa-v4compatibility.ttf',
    '/app/static/vendor/fontawesome/webfonts/fa-v4compatibility.woff2',
    '/app/static/vendor/imageuplodify/imageuploadify.min.css',
    '/app/static/vendor/imageuplodify/imageuploadify.min.js',
    '/app/static/vendor/jquery-steps/css/jquery.steps.css',
    '/app/static/vendor/jquery-steps/js/jquery.steps.min.js',
    '/app/static/vendor/jstree/dist/jstree.min.js',
    '/app/static/vendor/jstree/dist/themes/default/32px.png',
    '/app/static/vendor/jstree/dist/themes/default/40px.png',
    '/app/static/vendor/jstree/dist/themes/default/style.min.css',
    '/app/static/vendor/jstree/dist/themes/default/throbber.gif',
    '/app/static/vendor/lightgallery/dist/lightgallery.umd.js',
    '/app/static/vendor/lightgallery/dist/css/lg-thumbnail.css',
    '/app/static/vendor/lightgallery/dist/css/lg-zoom.css',
    '/app/static/vendor/lightgallery/dist/css/lightgallery.css',
    '/app/static/vendor/lightgallery/dist/fonts/lgb687.svg',
    '/app/static/vendor/lightgallery/dist/fonts/lgb687.ttf',
    '/app/static/vendor/lightgallery/dist/fonts/lgb687.woff',
    '/app/static/vendor/lightgallery/dist/fonts/lgb687.woff2',
    '/app/static/vendor/lightgallery/dist/images/loading.gif',
    '/app/static/vendor/lightgallery/dist/plugins/thumbnail/lg-thumbnail.umd.js',
    '/app/static/vendor/lightgallery/dist/plugins/zoom/lg-zoom.umd.js',
    '/app/static/vendor/line-awesome/css/line-awesome.min.css',
    '/app/static/vendor/line-awesome/fonts/la-brands-400.eot',
    '/app/static/vendor/line-awesome/fonts/la-brands-400.svg',
    '/app/static/vendor/line-awesome/fonts/la-brands-400.ttf',
    '/app/static/vendor/line-awesome/fonts/la-brands-400.woff',
    '/app/static/vendor/line-awesome/fonts/la-brands-400.woff2',
    '/app/static/vendor/line-awesome/fonts/la-brands-400d41d.eot',
    '/app/static/vendor/line-awesome/fonts/la-regular-400.eot',
    '/app/static/vendor/line-awesome/fonts/la-regular-400.svg',
    '/app/static/vendor/line-awesome/fonts/la-regular-400.ttf',
    '/app/static/vendor/line-awesome/fonts/la-regular-400.woff',
    '/app/static/vendor/line-awesome/fonts/la-regular-400.woff2',
    '/app/static/vendor/line-awesome/fonts/la-regular-400d41d.eot',
    '/app/static/vendor/line-awesome/fonts/la-solid-900.eot',
    '/app/static/vendor/line-awesome/fonts/la-solid-900.svg',
    '/app/static/vendor/line-awesome/fonts/la-solid-900.ttf',
    '/app/static/vendor/line-awesome/fonts/la-solid-900.woff',
    '/app/static/vendor/line-awesome/fonts/la-solid-900.woff2',
    '/app/static/vendor/line-awesome/fonts/la-solid-900d41d.eot',
    '/app/static/vendor/nouislider/nouislider.min.css',
    '/app/static/vendor/nouislider/nouislider.min.js',
    '/app/static/vendor/star-rating/jquery.star-rating-svg.js',
    '/app/static/vendor/star-rating/star-rating-svg.css',
    '/app/static/vendor/swiper/swiper-bundle.min.css',
    '/app/static/vendor/swiper/swiper-bundle.min.js',
    '/app/static/vendor/themify-icons/css/themify-icons.css',
    '/app/static/vendor/themify-icons/fonts/themify.ttf',
    '/app/static/vendor/themify-icons/fonts/themify.woff',
    '/app/static/vendor/themify-icons/fonts/themify9f249f24.eot',
    '/app/static/vendor/themify-icons/fonts/themify9f249f24.svg',
    '/app/static/vendor/themify-icons/fonts/themifyd41dd41d.eot',
    '/app/static/vendor/uicons-solid-rounded/css/uicons-solid-rounded.css',
    '/app/static/vendor/uicons-solid-rounded/webfonts/uicons-solid-rounded.eot',
    '/app/static/vendor/uicons-solid-rounded/webfonts/uicons-solid-rounded.woff',
    '/app/static/vendor/uicons-solid-rounded/webfonts/uicons-solid-rounded.woff2',
    '/app/static/vendor/wnumb/wNumb.js',
    '/app/',
    '/app/index.html',
    '/app/distv0.7.0/1018.bundle.js',
    '/app/distv0.7.0/1069.bundle.js',
    '/app/distv0.7.0/1118.bundle.js',
    '/app/distv0.7.0/1174.bundle.js',
    '/app/distv0.7.0/1233.bundle.js',
    '/app/distv0.7.0/1323.bundle.js',
    '/app/distv0.7.0/1400.bundle.js',
    '/app/distv0.7.0/1598.bundle.js',
    '/app/distv0.7.0/1765.bundle.js',
    '/app/distv0.7.0/1793.bundle.js',
    '/app/distv0.7.0/1796.bundle.js',
    '/app/distv0.7.0/1855.bundle.js',
    '/app/distv0.7.0/1861.bundle.js',
    '/app/distv0.7.0/1906.bundle.js',
    '/app/distv0.7.0/1938.bundle.js',
    '/app/distv0.7.0/204.bundle.js',
    '/app/distv0.7.0/2088.bundle.js',
    '/app/distv0.7.0/2097.bundle.js',
    '/app/distv0.7.0/2290.bundle.js',
    '/app/distv0.7.0/2465.bundle.js',
    '/app/distv0.7.0/2972.bundle.js',
    '/app/distv0.7.0/3028.bundle.js',
    '/app/distv0.7.0/3458.bundle.js',
    '/app/distv0.7.0/3556.bundle.js',
    '/app/distv0.7.0/3576.bundle.js',
    '/app/distv0.7.0/3591.bundle.js',
    '/app/distv0.7.0/3624.bundle.js',
    '/app/distv0.7.0/3663.bundle.js',
    '/app/distv0.7.0/4065.bundle.js',
    '/app/distv0.7.0/4259.bundle.js',
    '/app/distv0.7.0/4366.bundle.js',
    '/app/distv0.7.0/4573.bundle.js',
    '/app/distv0.7.0/4723.bundle.js',
    '/app/distv0.7.0/5068.bundle.js',
    '/app/distv0.7.0/5357.bundle.js',
    '/app/distv0.7.0/5398.bundle.js',
    '/app/distv0.7.0/5432.bundle.js',
    '/app/distv0.7.0/5524.bundle.js',
    '/app/distv0.7.0/5562.bundle.js',
    '/app/distv0.7.0/5693.bundle.js',
    '/app/distv0.7.0/586.bundle.js',
    '/app/distv0.7.0/6058.bundle.js',
    '/app/distv0.7.0/6070.bundle.js',
    '/app/distv0.7.0/6125.bundle.js',
    '/app/distv0.7.0/6218.bundle.js',
    '/app/distv0.7.0/625.bundle.js',
    '/app/distv0.7.0/6314.bundle.js',
    '/app/distv0.7.0/6342.bundle.js',
    '/app/distv0.7.0/6502.bundle.js',
    '/app/distv0.7.0/6679.bundle.js',
    '/app/distv0.7.0/6783.bundle.js',
    '/app/distv0.7.0/6856.bundle.js',
    '/app/distv0.7.0/6869.bundle.js',
    '/app/distv0.7.0/6880.bundle.js',
    '/app/distv0.7.0/7149.bundle.js',
    '/app/distv0.7.0/7157.bundle.js',
    '/app/distv0.7.0/7188.bundle.js',
    '/app/distv0.7.0/7277.bundle.js',
    '/app/distv0.7.0/7297.bundle.js',
    '/app/distv0.7.0/7314.bundle.js',
    '/app/distv0.7.0/7361.bundle.js',
    '/app/distv0.7.0/7393.bundle.js',
    '/app/distv0.7.0/7523.bundle.js',
    '/app/distv0.7.0/7540.bundle.js',
    '/app/distv0.7.0/7571.bundle.js',
    '/app/distv0.7.0/7615.bundle.js',
    '/app/distv0.7.0/7634.bundle.js',
    '/app/distv0.7.0/7722.bundle.js',
    '/app/distv0.7.0/7871.bundle.js',
    '/app/distv0.7.0/7997.bundle.js',
    '/app/distv0.7.0/8398.bundle.js',
    '/app/distv0.7.0/8670.bundle.js',
    '/app/distv0.7.0/8902.bundle.js',
    '/app/distv0.7.0/9001.bundle.js',
    '/app/distv0.7.0/9159.bundle.js',
    '/app/distv0.7.0/9524.bundle.js',
    '/app/distv0.7.0/9592.bundle.js',
    '/app/distv0.7.0/9652.bundle.js',
    '/app/distv0.7.0/9655.bundle.js',
    '/app/distv0.7.0/9667.bundle.js',
    '/app/distv0.7.0/9755.bundle.js',
    '/app/distv0.7.0/bundle.js'
];

// Install: cache assets in chunks to prevent overwhelming the browser
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      // Split assets into chunks of 20 to avoid overwhelming the browser
      const CHUNK_SIZE = 20;
      const chunks = [];
      
      for (let i = 0; i < ASSETS_TO_CACHE.length; i += CHUNK_SIZE) {
        chunks.push(ASSETS_TO_CACHE.slice(i, i + CHUNK_SIZE));
      }
      
      // Cache each chunk sequentially with error handling
      for (const chunk of chunks) {
        await Promise.allSettled(
          chunk.map(url => 
            cache.add(url).catch(err => {
              console.warn(`Failed to cache ${url}:`, err);
              return Promise.resolve(); // Don't fail the entire installation
            })
          )
        );
        
        // Small delay between chunks to give browser breathing room
        await new Promise(resolve => setTimeout(resolve, 50));
      }
      
      console.log(`Service Worker installed. Cached ${ASSETS_TO_CACHE.length} assets.`);
    }).catch(err => {
      console.error('Service Worker installation failed:', err);
      throw err;
    })
  );
});

// Activate: delete old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      )
    ).then(() => self.clients.claim())
  );
});

// Fetch: try cache first, fallback to network with better error handling
self.addEventListener('fetch', event => {
  // Skip cross-origin requests
  if (!event.request.url.startsWith(self.location.origin)) {
    return;
  }
  
  // Only handle GET requests (POST/PUT/DELETE cannot be cached)
  if (event.request.method !== 'GET') {
    return;
  }
  
  event.respondWith(
    caches.match(event.request).then(response => {
      if (response) {
        return response;
      }
      
      // Clone the request because it can only be used once
      return fetch(event.request.clone()).then(fetchResponse => {
        // Check if valid response
        if (!fetchResponse || fetchResponse.status !== 200 || fetchResponse.type !== 'basic') {
          return fetchResponse;
        }
        
        // Clone the response because it can only be used once
        const responseToCache = fetchResponse.clone();
        
        // Cache the fetched resource for next time
        caches.open(CACHE_NAME).then(cache => {
          cache.put(event.request, responseToCache);
        });
        
        return fetchResponse;
      }).catch(error => {
        console.error('Fetch failed:', error);
        // Return a custom offline page or response if needed
        throw error;
      });
    })
  );
});