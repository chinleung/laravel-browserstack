<?php

namespace ChinLeung\BrowserStack;

use BrowserStack\Local;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Firefox\FirefoxPreferences;
use Facebook\WebDriver\Firefox\FirefoxProfile;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestStatus\Success;

trait RunsOnBrowserStack
{
    /**
     * The BrowserStack connection instance.
     */
    protected static $connection;

    /**
     * The BrowserStack capabitities.
     */
    protected static $capabilities = [];

    /**
     * Flag to know if the after class callback has been registered.
     */
    protected static $registeredAfterClassCallback = false;

    /**
     * Update the BrowserStack status if the test has run on BrowserStack
     * and close the current session if the user wants one test per session.
     */
    protected function tearDown(): void
    {
        if ($this->hasActiveBrowserStackConnection()) {
            if (config('browserstack.separate_sessions')) {
                $this->updateBrowserStackSessionStatus();

                static::closeAll();
            }
        }

        parent::tearDown();
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        if ($this->shouldRunOnBrowserStack()) {
            return $this->createBrowserStackDriver();
        }

        return $this->createLocalDriver();
    }

    /**
     * Create the driver for local tests.
     */
    protected function createBrowserStackDriver(): RemoteWebDriver
    {
        $this->connectToBrowserStack();

        return RemoteWebDriver::create(
            $this->endpointForBrowserStack(),
            $this->capabilitiesForBrowserStack()
        );
    }

    /**
     * Create the driver for local tests.
     */
    protected function createLocalDriver(): RemoteWebDriver
    {
        if (! static::runningInSail()) {
            static::startChromeDriver([
                '--port=9515',
            ]);
        }

        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
        ])->unless($this->hasHeadlessDisabled(), static function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY,
                $options
            )
        );
    }

    /**
     * Retrieve the capabilities of the browser.
     */
    protected function browserCapabilities(): array
    {
        $slug = $this->getBrowserSlug();

        return array_merge($this->detectOs($slug), $this->detectBrowser($slug));
    }

    /**
     * Retrieve the capabilities for the correct browser based on the slug.
     */
    protected function detectBrowser(string $slug): array
    {
        preg_match('/_(IE|EDGE|CHROME|FIREFOX|SAFARI|OPERA)(\d*)/', $slug, $browser);

        $capabilities = array_filter([
            'browser' => $browser[1],
            'browser_version' => $browser[2],
        ]);

        // Disable the Devtools JSONView of Firefox which causes error when
        // parsing a JSON response.
        if ($browser[1] == 'FIREFOX') {
            $profile = new FirefoxProfile;

            $firefoxOptions = new FirefoxOptions;

            $caps = DesiredCapabilities::firefox();

            $profile->setPreference('devtools.jsonview.enabled', false);
            $profile->setPreference(
                FirefoxPreferences::READER_PARSE_ON_LOAD_ENABLED,
                false
            );

            $firefoxOptions->setProfile($profile);
            $caps->setCapability(FirefoxOptions::CAPABILITY, $firefoxOptions);
        } else {
            $method = str_replace(
                ['ie', 'edge'],
                ['internetExplorer', 'microsoftEdge'],
                strtolower($browser[1])
            );

            $caps = DesiredCapabilities::$method();
        }

        return array_merge($capabilities, $caps->toArray());
    }

    /**
     * Retrieve the capabilities for the correct operating system based on the
     * slug.
     */
    protected function detectOs(string $slug): array
    {
        if (strpos($slug, 'IOS_') === 0 || strpos($slug, 'ANDROID_') === 0) {
            return $this->detectMobileOs($slug);
        }

        preg_match(
            '/(MACOS_(SEQUOIA|SONOMA|VENTURA|MONTEREY|BIG_SUR|CATALINA|MOJAVE|HIGH_SIERRA|SIERRA|EL_CAPITAN|YOSEMITE|MAVERICKS|MOUNTAIN_LION|LION|SNOW_LEOPARD)|WINDOWS_(11|10|8(\.1)?|XP))/',
            $slug,
            $os
        );

        return [
            'os' => strpos($slug, 'WINDOWS') !== false ? 'Windows' : 'OS X',
            'os_version' => str_replace(
                '_',
                ' ',
                $os[3] ?? $os[2]
            ),
        ];
    }

    /**
     * Detect the correct operating system for a mobile device.
     */
    protected function detectMobileOs(string $slug): array
    {
        preg_match('/(ANDROID|IOS)_(.*)/', $slug, $os);

        $method = $os[1] == 'ANDROID' ? 'android' : (
            strpos($os[2], 'IPHONE') !== false ? 'iphone' : 'ipad'
        );

        return array_merge([
            'device' => str_replace('_', ' ', $os[2]),
            'real_mobile' => true,
        ], DesiredCapabilities::$method()->toArray());
    }

    /**
     * Retrieve the capabilities for BrowserStack.
     *
     * @link https://www.browserstack.com/automate/capabilities
     */
    protected function capabilitiesForBrowserStack(): array
    {
        $slug = $this->getBrowserSlug();

        if (! isset(static::$capabilities[$slug])) {
            static::$capabilities[$slug] = array_merge(
                Arr::dot(config('browserstack.capabilities')),
                $this->browserCapabilities()
            );
        }

        return array_merge(static::$capabilities[$slug], [
            'project' => $this->getProjectName(),
            'build' => $this->getBuildName(),
            'name' => $this->getSessionName(),
        ]);
    }

    /**
     * Retrieve the endpoint for BrowserStack.
     */
    protected function endpointForBrowserStack(): string
    {
        return sprintf(
            'https://%s:%s@hub-cloud.browserstack.com/wd/hub',
            config('browserstack.username'),
            config('browserstack.key')
        );
    }

    /**
     * Connect to BrowserStack.
     */
    protected function connectToBrowserStack(): void
    {
        if ($this->connectedToBrowserStack()) {
            return;
        }

        rescue(function () {
            static::$connection = tap(new Local)->start(
                $this->argumentsForBrowserStack()
            );

            $this->connectedToBrowserStack();

            if (! static::$registeredAfterClassCallback) {
                static::$registeredAfterClassCallback = true;

                static::afterClass(function () {
                    optional(static::$connection)->stop();

                    $this->connectedToBrowserStack();

                    static::$connection = null;
                });
            }
        });
    }

    /**
     * Retrieve the slug of the browser.
     */
    protected function getBrowserSlug(): ?string
    {
        return config('browserstack.browser');
    }

    /**
     * Retrieve the name of the build to display in the BrowserStack
     * dashboard.
     */
    protected function getBuildName(): string
    {
        $sha = env('GITHUB_SHA') ?? exec('git rev-parse HEAD');

        return sprintf(
            '%s — %s — %s',
            gethostname(),
            str_replace('refs', '', $sha),
            env('GITHUB_REF', config('app.env'))
        );
    }

    /**
     * Retrieve the name of the project to display in the BrowserStack
     * dashboard.
     */
    protected function getProjectName(): string
    {
        return config('app.name');
    }

    /**
     * Retrieve the name of the session to display in the BrowserStack
     * dashboard.
     */
    protected function getSessionName(): string
    {
        $class = get_called_class();

        $name = Str::of($this->name())
            ->after('__pest_evaluable_')
            ->replace('_', ' ');

        return sprintf(
            '%s @ %s',
            class_basename($class),
            $name->toString()
        );
    }

    /**
     * Check if we have an active connection with BrowserStack.
     */
    protected function hasActiveBrowserStackConnection(): bool
    {
        return static::$connection !== null;
    }

    /**
     * Check if the test should run on BrowserStack.
     */
    protected function shouldRunOnBrowserStack(): bool
    {
        return $this->getBrowserSlug() !== null;
    }

    /**
     * Update the status of the session in BrowserStack.
     *
     * @link  https://www.browserstack.com/automate/rest-api
     */
    protected function updateBrowserStackSessionStatus(): void
    {
        $browser = collect(static::$browsers)->first();

        $status = $this->status();

        (new Client)->put(
            "https://api.browserstack.com/automate/sessions/{$browser->driver->getSessionID()}.json",
            [
                'json' => array_filter([
                    'status' => $status instanceof Success
                        ? 'passed'
                        : 'failed',
                    'reason' => $status->message(),
                ]),
                'auth' => [
                    config('browserstack.username'),
                    config('browserstack.key'),
                ],
            ]
        );
    }

    /**
     * Verify if the connection to BrowserStack has been made.
     */
    protected function connectedToBrowserStack(): bool
    {
        return static::$connection !== null && static::$connection->isRunning();
    }

    /**
     * Retrieve the arguments for the BrowserStack connection.
     */
    protected function argumentsForBrowserStack(): array
    {
        return array_filter(array_merge(
            [
                'key' => config('browserstack.key'),
            ],
            config('browserstack.arguments')
        ));
    }
}
