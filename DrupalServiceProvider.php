<?php

namespace Bangpound\Silex;

use Bangpound\Bridge\Drupal\Bootstrap;
use Bangpound\Bridge\Drupal\BootstrapEvents;
use Bangpound\Bridge\Drupal\Controller\Controller;
use Bangpound\Bridge\Drupal\Event\BootstrapEvent;
use Bangpound\Bridge\Drupal\EventListener\AutoloadListener;
use Bangpound\Bridge\Drupal\EventListener\ControllerListener;
use Bangpound\Bridge\Drupal\EventListener\DefaultPhasesListener;
use Bangpound\Bridge\Drupal\EventListener\ConfigurationListener;
use Bangpound\Bridge\Drupal\EventListener\FullListener;
use Bangpound\Bridge\Drupal\EventListener\PageCacheListener;
use Bangpound\Bridge\Drupal\EventListener\PageHeaderListener;
use Bangpound\Bridge\Drupal\EventListener\VariablesListener;
use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
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
            ->match('/{q}', 'drupal.controller:deliverAction')
            ->assert('q', '[^_].+$')
            ->value('_legacy', 'drupal')
        ;

        $controllers
            ->convert('_router_item',
                function ($q, Request $request) {
                    $q = $request->get('q');

                    return menu_get_item(trim($q, '/'));
                }
            )
            ->convert('_route',
                function ($q, Request $request) {
                    $router_item = $request->attributes->get('_router_item');

                    return $router_item['path'];
                }
            )
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
        $app['drupal.request_matcher'] = $app->share(
            function ($c) {
                $matcher = new RequestMatcher();
                $matcher->matchAttribute('_legacy', 'drupal');

                return $matcher;
            });

        $app['drupal.bootstrap'] = $app->share(
            function () use ($app) {
                $bootstrap = new Bootstrap();
                $bootstrap->setEventDispatcher($app['dispatcher']);

                return $bootstrap;
            }
        );

        $app['legacy.request_matcher'] = $app->share(
            function ($c) {
                return $c['drupal.request_matcher'];
            }
        );

        $app->before(
            function (Request $request) use ($app) {
                define('DRUPAL_ROOT', getcwd());
                require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
                drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL, TRUE, $app['drupal.bootstrap']);

                $pathinfo = $request->getPathInfo();

                // The 'q' variable is pervasive in Drupal, so it's best to just keep
                // it even though it's very un-Symfony.
                $path = drupal_get_normal_path(substr($pathinfo, 1));

                if (variable_get('menu_rebuild_needed', FALSE) || !variable_get('menu_masks', array())) {
                    menu_rebuild();
                }
                $original_map = arg(NULL, $path);

                $parts = array_slice($original_map, 0, MENU_MAX_PARTS);
                $ancestors = menu_get_ancestors($parts);
                $router_item = db_query_range('SELECT * FROM {menu_router} WHERE path IN (:ancestors) ORDER BY fit DESC', 0, 1, array(':ancestors' => $ancestors))->fetchAssoc();

                if ($router_item) {
                    // Allow modules to alter the router item before it is translated and
                    // checked for access.
                    drupal_alter('menu_get_item', $router_item, $path, $original_map);

                    // The requested path is an unalaised Drupal route.
                    $request->attributes->add(
                        array(
                            '_route' => $router_item['path'],
                            '_legacy' => 'drupal',
                        )
                    );
                }
            }, 33
        );
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
        $dispatcher->addSubscriber(new DefaultPhasesListener());

        $dispatcher->addSubscriber(new ConfigurationListener());
        $dispatcher->addSubscriber(new PageCacheListener());
        $dispatcher->addSubscriber(new PageHeaderListener());
        $dispatcher->addSubscriber(new FullListener());
        $dispatcher->addSubscriber(new VariablesListener($app['drupal.conf']));
        $dispatcher->addSubscriber(new ControllerListener($app['legacy.request_matcher']));

        $dispatcher->addSubscriber(new AutoloadListener());

        $dispatcher->addListener(BootstrapEvents::FILTER_DATABASE,
            function (BootstrapEvent $event) use ($app) {
                $app['db.options'] = array(
                    'pdo' => \Database::getConnection(),
                );
            }
        );

        $app['drupal.controller'] = $app->share(
            function () {
                return new Controller();
            }
        );

        $app->mount('', $this->connect($app));
    }
}
