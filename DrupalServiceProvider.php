<?php

namespace Bangpound\Silex;

use Bangpound\Bridge\Drupal\Bootstrap;
use Bangpound\Bridge\Drupal\BootstrapEvents;
use Bangpound\Bridge\Drupal\Event\GetCallableEvent;
use Bangpound\Bridge\Drupal\EventListener\AutoloadListener;
use Bangpound\Bridge\Drupal\EventListener\PageCacheListener;
use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher;

class DrupalServiceProvider implements ServiceProviderInterface, ControllerProviderInterface
{
    /**
     * Returns routes to connect to the given application.
     *
     * @param Application $app An Application instance
     *
     * @throws \LogicException
     * @return ControllerCollection A ControllerCollection instance
     */
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        // Drupal front controller.
        $controllers
            ->match('/{q}')
            ->run(function ($q) use ($app) {
                $_GET['q'] = $q;
                menu_execute_active_handler($q);
            })
            ->assert('q', '[^_].+')
            ->value('_legacy', 'drupal')
        ;

        return $controllers;
    }

    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Application $app An Application instance
     */
    public function register(Application $app)
    {
        $app['drupal.request_matcher'] = $app->share(function ($c) {
            $matcher = new RequestMatcher();
            $matcher->matchAttribute('_legacy', 'drupal');

            return $matcher;
        });

        $app['drupal.bootstrap'] = $app->share(function () use ($app) {
            $bootstrap = new Bootstrap();
            $bootstrap->setEventDispatcher($app['dispatcher']);

            return $bootstrap;
        });

        $app->before(function (Request $request) use ($app) {
            if ($app['drupal.request_matcher']->matches($request)) {
                /**
                 * Root directory of Drupal installation.
                 */
                define('DRUPAL_ROOT', getcwd());

                require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
                drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL, TRUE, $app['drupal.bootstrap']);
            }
        });
    }

    /**
     * Bootstraps the application.
     *
     * This method is called after all services are registered
     * and should be used for "dynamic" configuration (whenever
     * a service must be requested).
     */
    public function boot(Application $app)
    {
        $dispatcher = $app['dispatcher'];
        $dispatcher->addSubscriber(new AutoloadListener());
        $dispatcher->addSubscriber(new PageCacheListener());
        $dispatcher->addListener(BootstrapEvents::POST_DATABASE, function (Event $event) use ($app) {
            $app['db.options'] = array(
                'pdo' => \Database::getConnection(),
            );
        });

        /**
         * Sets up the script environment and loads settings.php.
         *
         * @see _drupal_bootstrap_configuration()
         */
        $dispatcher->addListener(BootstrapEvents::PRE_CONFIGURATION, function (GetCallableEvent $event) use ($app) {
            $event->setCallable(function () {

                drupal_environment_initialize();
                // Start a page timer:
                timer_start('page');
                // Initialize the configuration, including variables from settings.php.
                drupal_settings_initialize();
            });
        });

        // DRUPAL_BOOTSTRAP_PAGE_CACHE only loads the cache handler.

        $dispatcher->addListener(BootstrapEvents::PRE_PAGE_HEADER, function (GetCallableEvent $event) use ($app) {
            $event->setCallable(function () {
                bootstrap_invoke_all('boot');
            });
        });

        $app->mount('', $this->connect($app));
    }
}
