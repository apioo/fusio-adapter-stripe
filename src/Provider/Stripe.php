<?php
/*
 * Fusio
 * A web-application to create dynamically RESTful APIs
 *
 * Copyright (C) 2015-2021 Christoph Kappestein <christoph.kappestein@gmail.com>
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

namespace Fusio\Adapter\Stripe\Provider;

use Fusio\Engine\Model\ProductInterface;
use Fusio\Engine\Model\TransactionInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\Payment\PrepareContext;
use Fusio\Engine\Payment\ProviderInterface;
use PSX\Http\Exception as StatusCode;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Stripe
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.gnu.org/licenses/agpl-3.0
 * @link    http://fusio-project.org
 */
class Stripe implements ProviderInterface
{
    /**
     * @inheritdoc
     */
    public function prepare($connection, ProductInterface $product, TransactionInterface $transaction, PrepareContext $context)
    {
        $client = $this->getClient($connection);

        // create checkout
        $session = $client->checkout->sessions->create([
            'line_items' => [[
                'price_data' => [
                    'currency' => $context->getCurrency(),
                    'product_data' => [
                        'name' => $product->getName(),
                    ],
                    'unit_amount' => (int) ($product->getPrice() * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'client_reference_id' => $transaction->getId(),
            'success_url' => $context->getReturnUrl() . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $context->getCancelUrl(),
        ]);

        // update transaction details
        $this->updateTransaction($session, $transaction);

        return $session->url;
    }

    /**
     * @inheritdoc
     */
    public function execute($connection, ProductInterface $product, TransactionInterface $transaction, ParametersInterface $parameters)
    {
        $client = $this->getClient($connection);

        $session = $client->checkout->sessions->retrieve($parameters->get('session_id'));

        // update transaction details
        $this->updateTransaction($session, $transaction);
    }

    private function getClient(mixed $connection): StripeClient
    {
        if ($connection instanceof StripeClient) {
            return $connection;
        } else {
            throw new StatusCode\InternalServerErrorException('Connection must return a Stripe Client');
        }
    }

    private function updateTransaction(Session $session, TransactionInterface $transaction): void
    {
        $transaction->setStatus($this->getTransactionStatus($session));
        $transaction->setRemoteId($session->id);
    }

    private function getTransactionStatus(Session $session): int
    {
        if ($session->status == Session::STATUS_OPEN) {
            return TransactionInterface::STATUS_CREATED;
        } elseif ($session->status == Session::STATUS_COMPLETE) {
            return TransactionInterface::STATUS_APPROVED;
        } elseif ($session->status == Session::STATUS_EXPIRED) {
            return TransactionInterface::STATUS_FAILED;
        }

        return TransactionInterface::STATUS_UNKNOWN;
    }
}
