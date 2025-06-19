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

namespace Fusio\Adapter\Stripe\Payment;

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
 * @license http://www.apache.org/licenses/LICENSE-2.0
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
                    'unit_amount' => $product->getPrice(),
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
                'domain' => $context->getDomain(),
            ]
        ];

        if (!empty($user->getExternalId())) {
            $config['customer'] = $user->getExternalId();
        } elseif (!empty($user->getEmail())) {
            $config['customer_email'] = $user->getEmail();
        }

        $session = $client->checkout->sessions->create($config);

        return $session->url ?? '';
    }

    public function portal(mixed $connection, UserInterface $user, string $returnUrl, ?string $configurationId = null): ?string
    {
        $client = $this->getClient($connection);

        $externalId = $user->getExternalId();
        if (!empty($externalId)) {
            $params = [
                'customer' => $externalId,
                'return_url' => $returnUrl,
            ];

            if (!empty($configurationId)) {
                $params['configuration'] = $configurationId;
            }

            $session = $client->billingPortal->sessions->create($params);
            return $session->url;
        } else {
            return null;
        }
    }

    public function webhook(RequestInterface $request, WebhookInterface $handler, ?string $webhookSecret = null, ?string $domain = null): void
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

        switch ($event->type) {
            case 'checkout.session.completed':
                $object = $event->data->object ?? null;
                if ($object instanceof Session) {
                    if ($domain !== null && isset($object->metadata['domain']) && $domain !== $object->metadata['domain']) {
                        // the checkout does not belong to this domain
                        return;
                    }

                    $this->handleCheckoutSessionCompleted($object, $handler);
                }
                break;

            case 'invoice.paid':
                $object = $event->data->object ?? null;
                if ($object instanceof Invoice) {
                    $this->handleInvoicePaid($object, $handler);
                }
                break;

            case 'invoice.payment_failed':
                $object = $event->data->object ?? null;
                if ($object instanceof Invoice) {
                    $this->handleInvoicePaymentFailed($object, $handler);
                }
                break;
        }
    }

    private function getClient(mixed $connection): StripeClient
    {
        if ($connection instanceof StripeClient) {
            return $connection;
        } else {
            throw new StatusCode\InternalServerErrorException('Connection must return a Stripe Client');
        }
    }

    /**
     * Payment is successful and the subscription is created.
     * You should provision the subscription and save the customer ID to your database.
     */
    private function handleCheckoutSessionCompleted(Session $object, WebhookInterface $webhook): void
    {
        $metadata = $object->metadata;
        if (empty($metadata)) {
            return;
        }
        $userId = (int) $metadata['user_id'];
        $productId = (int) $metadata['product_id'];
        $externalId = $object->customer;
        if (!isset($externalId)) {
            return;
        }
        $amountTotal = $object->amount_total;
        if (!isset($amountTotal)) {
            return;
        }
        $sessionId = $object->id;

        $webhook->completed($userId, $productId, (string) $externalId, $amountTotal, $sessionId);
    }

    /**
     * Continue to provision the subscription as payments continue to be made.
     * Store the status in your database and check when a user accesses your service.
     * This approach helps you avoid hitting rate limits.
     */
    private function handleInvoicePaid(Invoice $object, WebhookInterface $webhook): void
    {
        $externalId = $object->customer;
        if (!isset($externalId)) {
            return;
        }

        $amountPaid = $object->amount_paid;
        $invoiceId = $object->id;
        if (!isset($invoiceId)) {
            return;
        }

        $startDate = new \DateTimeImmutable();
        $endDate = new \DateTimeImmutable();
        foreach ($object->lines->data as $item) {
            if (isset($item->period->start)) {
                $startDate = new \DateTimeImmutable('@' . $item->period->start);
            }

            if (isset($item->period->end)) {
                $endDate = new \DateTimeImmutable('@' . $item->period->end);
            }
        }

        $webhook->paid((string) $externalId, $amountPaid, $invoiceId, $startDate, $endDate);
    }

    /**
     * The payment failed or the customer does not have a valid payment method.
     * The subscription becomes past_due. Notify your customer and send them to the
     * customer portal to update their payment information.
     */
    private function handleInvoicePaymentFailed(Invoice $object, WebhookInterface $webhook): void
    {
        $externalId = $object->customer;
        if (!isset($externalId)) {
            return;
        }

        $webhook->failed((string) $externalId);
    }
}
