<?php

namespace Bangpound\Silex;

use Bangpound\Bridge\Drupal\Bootstrap;
use Bangpound\Bridge\Drupal\BootstrapEvents;
use Bangpound\Bridge\Drupal\Event\BootstrapEvent;
use Bangpound\Bridge\Drupal\EventListener\AutoloadListener;
use Bangpound\Bridge\Drupal\EventListener\BootstrapListener;
use Bangpound\Bridge\Drupal\EventListener\ConfigurationListener;
use Bangpound\Bridge\Drupal\EventListener\FullListener;
use Bangpound\Bridge\Drupal\EventListener\PageCacheListener;
use Bangpound\Bridge\Drupal\EventListener\PageHeaderListener;
use Bangpound\Bridge\Drupal\EventListener\ViewListener;
use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher;
use Symfony\Component\HttpKernel\KernelEvents;

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
        /**  */
        $controllers
            ->match('{q}')
            ->before(function (Request $request) use ($app) {

                if ($app['drupal.request_matcher']->matches($request)) {
                    $q = $request->get('q');
                    $q = request_path();
                    if ($router_item = menu_get_item($q)) {
                        if ($router_item['access']) {
                            if ($router_item['include_file']) {
                                require_once DRUPAL_ROOT . '/' . $router_item['include_file'];
                            }

                            $request->attributes->add(array(
                                '_router_item' => $router_item,
                                '_controller' => $router_item['page_callback'],
                                '_arguments' => $router_item['page_arguments'],
                                '_route' => $router_item['path'],
                            ));
                        }
                    }
                }
            })
            ->assert('q', '[^_].+$')
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
        $dispatcher->addSubscriber(new BootstrapListener());

        $dispatcher->addSubscriber(new ConfigurationListener());
        $dispatcher->addSubscriber(new PageCacheListener());
        $dispatcher->addSubscriber(new PageHeaderListener());
        $dispatcher->addSubscriber(new FullListener());

        $dispatcher->addSubscriber(new AutoloadListener());

        $listener = new ViewListener($app['legacy.request_matcher']);
        $dispatcher->addListener(KernelEvents::VIEW, array($listener, 'onKernelView'), 8);

        $dispatcher->addListener(BootstrapEvents::FILTER_DATABASE, function (BootstrapEvent $event) use ($app) {
            $app['db.options'] = array(
                'pdo' => \Database::getConnection(),
            );
        });

        $app->mount('', $this->connect($app));
    }
}
