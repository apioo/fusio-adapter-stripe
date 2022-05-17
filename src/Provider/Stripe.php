<?php
/*
 * Fusio
 * A web-application to create dynamically RESTful APIs
 *
 * Copyright (C) 2015-2022 Christoph Kappestein <christoph.kappestein@gmail.com>
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
use Fusio\Engine\Model\UserInterface;
use Fusio\Engine\Payment\CheckoutContext;
use Fusio\Engine\Payment\ProviderInterface;
use Fusio\Engine\Payment\WebhookInterface;
use PSX\Http\Exception as StatusCode;
use PSX\Http\RequestInterface;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.gnu.org/licenses/agpl-3.0
 * @link    https://www.fusio-project.org/
 */
class Stripe implements ProviderInterface
{
    public function checkout(mixed $connection, ProductInterface $product, UserInterface $user, CheckoutContext $context): string
    {
        $client = $this->getClient($connection);

        $externalId = $product->getExternalId();
        if (!empty($externalId)) {
            // in case the product contains an external id to a price object use this id
            $item = [
                'price' => $externalId,
            ];
        } else {
            $item = [
                'price_data' => [
                    'currency' => $context->getCurrency(),
                    'product_data' => [
                        'name' => $product->getName(),
                    ],
                    'unit_amount' => (int) ($product->getPrice() * 100),
                ],
            ];
        }

        $item['quantity'] = 1;

        if (empty($product->getInterval())) {
            $mode = 'payment';
        } else {
            $mode = 'subscription';
        }

        $config = [
            'line_items' => [$item],
            'mode' => $mode,
            'client_reference_id' => $user->getId(),
            'success_url' => $context->getReturnUrl() . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $context->getCancelUrl(),
            'metadata' => [
                'user_id' => $user->getId(),
                'product_id' => $product->getId(),
            ]
        ];

        if (!empty($user->getExternalId())) {
            $config['customer'] = $user->getExternalId();
        } elseif (!empty($user->getEmail())) {
            $config['customer_email'] = $user->getEmail();
        }

        $session = $client->checkout->sessions->create($config);

        return $session->url;
    }

    public function portal(mixed $connection, UserInterface $user, string $returnUrl): ?string
    {
        $client = $this->getClient($connection);

        $externalId = $user->getExternalId();
        if (!empty($externalId)) {
            $session = $client->billingPortal->sessions->create([
                'customer' => $externalId,
                'return_url' => $returnUrl,
            ]);
            return $session->url;
        } else {
            return null;
        }
    }

    public function webhook(RequestInterface $request, WebhookInterface $webhook, ?string $webhookSecret = null): void
    {
        if (empty($webhookSecret)) {
            throw new StatusCode\InternalServerErrorException('No webhook secret was configured');
        }

        try {
            $event = Webhook::constructEvent(
                (string) $request->getBody(),
                $request->getHeader('stripe-signature'),
                $webhookSecret
            );
        } catch (\Exception $e) {
            throw new StatusCode\ForbiddenException($e->getMessage(), $e);
        }

        $object = $event->data;

        switch ($event->type) {
            case 'checkout.session.completed':
                // Payment is successful and the subscription is created.
                // You should provision the subscription and save the customer ID to your database.
                if ($object instanceof Session) {
                    $userId = (int) $object->metadata['user_id'];
                    $productId = (int) $object->metadata['product_id'];
                    $externalId = $object->customer->id;
                    $amountTotal = $object->amount_total;
                    $sessionId = $object->id;

                    $webhook->completed($userId, $productId, $externalId, $amountTotal, $sessionId);
                }
                break;

            case 'invoice.paid':
                // Continue to provision the subscription as payments continue to be made.
                // Store the status in your database and check when a user accesses your service.
                // This approach helps you avoid hitting rate limits.
                if ($object instanceof Invoice) {
                    $externalId = $object->customer->id;
                    $amountPaid = $object->amount_paid;
                    $invoiceId = $object->id;

                    $webhook->paid($externalId, $amountPaid, $invoiceId);
                }
                break;

            case 'invoice.payment_failed':
                // The payment failed or the customer does not have a valid payment method.
                // The subscription becomes past_due. Notify your customer and send them to the
                // customer portal to update their payment information.
                if ($object instanceof Invoice) {
                    $externalId = $object->customer->id;

                    $webhook->failed($externalId);
                }
                break;
        }
    }

    private function getClient($connection): StripeClient
    {
        if ($connection instanceof StripeClient) {
            return $connection;
        } else {
            throw new StatusCode\InternalServerErrorException('Connection must return a Stripe Client');
        }
    }
}
