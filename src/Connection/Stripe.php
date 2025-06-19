<?php
/*
 * Fusio - Self-Hosted API Management for Builders.
 * For the current version and information visit <https://www.fusio-project.org/>
 *
 * Copyright (c) Christoph Kappestein <christoph.kappestein@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
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
 * @license http://www.apache.org/licenses/LICENSE-2.0
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
