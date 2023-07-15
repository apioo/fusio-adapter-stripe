<?php
/*
 * Fusio
 * A web-application to create dynamically RESTful APIs
 *
 * Copyright (C) 2015-2023 Christoph Kappestein <christoph.kappestein@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Fusio\Adapter\Stripe\Connection;

use Fusio\Engine\ConnectionAbstract;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Stripe\StripeClient;

/**
 * Stripe
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.gnu.org/licenses/agpl-3.0
 * @link    https://www.fusio-project.org/
 */
class Stripe extends ConnectionAbstract
{
    public function getName(): string
    {
        return 'Stripe';
    }

    public function getConnection(ParametersInterface $config): StripeClient
    {
        \Stripe\Stripe::setAppInfo("Fusio", "4.0.0", "https://www.fusio-project.org");

        $options = [
            'api_key' => $config->get('api_key') ?: null,
            'client_id' => $config->get('client_id') ?: null,
        ];

        return new StripeClient($options);
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newInput('api_key', 'API Key', 'text', 'API Key'));
        $builder->add($elementFactory->newInput('client_id', 'Client ID', 'text', 'Client ID'));
    }
}
