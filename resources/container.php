<?php

use Fusio\Adapter\Stripe\Connection\Stripe;
use Fusio\Adapter\Stripe\Payment\Stripe as StripePayment;
use Fusio\Engine\Adapter\ServiceBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container) {
    $services = ServiceBuilder::build($container);
    $services->set(Stripe::class);
    $services->set(StripePayment::class);
};
