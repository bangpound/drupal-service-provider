<?php

namespace Bangpound\Silex;

use Bangpound\Bridge\Drupal\Bootstrap;
use Bangpound\Bridge\Drupal\BootstrapEvents;
use Bangpound\Bridge\Drupal\Controller\Controller;
use Bangpound\Bridge\Drupal\Event\BootstrapEvent;
use Bangpound\Bridge\Drupal\EventListener\AutoloadListener;
use Bangpound\Bridge\Drupal\EventListener\DefaultPhasesListener;
use Bangpound\Bridge\Drupal\EventListener\ConfigurationListener;
use Bangpound\Bridge\Drupal\EventListener\FullListener;
use Bangpound\Bridge\Drupal\EventListener\HeaderListener;
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
        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        // Drupal front controller.
        $controllers
            ->match('/{q}', 'drupal.controller:deliverAction')
            ->assert('q', '[^_].+$')
            ->value('_legacy', 'drupal')
            ->convert('q', function ($q) {
                return drupal_get_normal_path($q);
            })
            ->convert('router_item', function ($router_item = array(), Request $request) {
                $q = $request->get('q');

                return menu_get_item($q);
            })
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
        $app['drupal.bootstrap.class'] = 'Bangpound\\Bridge\\Drupal\\Bootstrap';
        $app['drupal.class'] = 'Druplex';

        $app['legacy.request_matcher'] = $app->share(
            $app->extend('legacy.request_matcher',
                function (RequestMatcher $matcher, $c) {
                    $matcher->matchAttribute('_legacy', 'drupal');

                    return $matcher;
                }
            )
        );

        $app['drupal.listener.header'] = $app->share(
            function ($c) {
                return new HeaderListener($c['legacy.request_matcher']);
            }
        );

        $app['drupal.bootstrap'] = $app->share(
            function () use ($app) {
                /** @var Bootstrap $bootstrap */
                $bootstrap = new $app['drupal.bootstrap.class']();
                $bootstrap->setEventDispatcher($app['dispatcher']);
                require_once $app['web_dir'] . '/includes/bootstrap.inc';
                drupal_bootstrap(NULL, TRUE, $bootstrap);

                return $bootstrap;
            }
        );

        $app->before(
            function (Request $request) use ($app) {
                $app['drupal.bootstrap'];
                define('DRUPAL_ROOT', getcwd());
                drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

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
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $app['dispatcher'];
        $dispatcher->addSubscriber($app['drupal.listener.header']);
        $dispatcher->addSubscriber(new DefaultPhasesListener());

        $dispatcher->addSubscriber(new ConfigurationListener());
        $dispatcher->addSubscriber(new PageCacheListener());
        $dispatcher->addSubscriber(new PageHeaderListener());
        $dispatcher->addSubscriber(new FullListener());
        $dispatcher->addSubscriber(new VariablesListener($app['drupal.conf']));

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

        $app['drupal.class']::setPimple($app);
    }
}
