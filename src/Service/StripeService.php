<?php

namespace App\Service;

use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;

class StripeService
{
    private string $stripeSecretKey;
    private string $stripePublishableKey;
    private string $webhookSecret;

    public function __construct(
        string $stripeSecretKey,
        string $stripePublishableKey,
        string $webhookSecret
    ) {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->stripePublishableKey = $stripePublishableKey;
        $this->webhookSecret = $webhookSecret;
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * Create a checkout session for an order
     */
    public function createCheckoutSession(array $orderData, string $successUrl, string $cancelUrl): Session
    {
        $lineItems = [];
        foreach ($orderData['items'] as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                    ],
                    'unit_amount' => (int) ($item['price'] * 100), // Amount in cents (EUR has 2 decimals)
                ],
                'quantity' => $item['quantity'],
            ];
        }

        return Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $orderData['customer_email'],
            'metadata' => [
                'order_id' => $orderData['order_id'],
                'customer_name' => $orderData['customer_name'],
            ],
        ]);
    }

    /**
     * Create a subscription session
     */
    public function createSubscriptionSession(array $subscriptionData, string $successUrl, string $cancelUrl): Session
    {
        return Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price' => $subscriptionData['price_id'],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $subscriptionData['customer_email'],
            'metadata' => [
                'customer_id' => $subscriptionData['customer_id'],
            ],
        ]);
    }

    /**
     * Create or retrieve a Stripe customer
     */
    public function getOrCreateCustomer(string $email, array $metadata = []): Customer
    {
        // Search for existing customer
        $customers = Customer::all(['email' => $email, 'limit' => 1]);

        if (!empty($customers->data)) {
            return $customers->data[0];
        }

        // Create new customer
        return Customer::create([
            'email' => $email,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Retrieve a payment intent
     */
    public function getPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId);
    }

    /**
     * Create a payment intent for manual processing
     */
    public function createPaymentIntent(int $amount, string $currency = 'eur', array $metadata = []): PaymentIntent
    {
        return PaymentIntent::create([
            'amount' => $amount, // Amount in cents
            'currency' => $currency,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Verify webhook signature and return decoded event
     */
    public function verifyWebhook(string $payload, string $signature): \Stripe\Event
    {
        try {
            return Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid webhook signature: ' . $e->getMessage());
        }
    }

    /**
     * Handle checkout.session.completed event
     */
    public function handleCheckoutCompleted(array $event): array
    {
        $session = $event['data']['object'];

        return [
            'session_id' => $session['id'],
            'payment_intent' => $session['payment_intent'],
            'customer_email' => $session['customer_email'],
            'order_id' => $session['metadata']['order_id'] ?? null,
            'customer_name' => $session['metadata']['customer_name'] ?? null,
            'amount_total' => $session['amount_total'] / 100, // Convert from cents
            'currency' => $session['currency'],
        ];
    }

    /**
     * Handle payment_intent.succeeded event
     */
    public function handlePaymentSucceeded(array $event): array
    {
        $paymentIntent = $event['data']['object'];

        return [
            'payment_intent_id' => $paymentIntent['id'],
            'status' => $paymentIntent['status'],
            'amount' => $paymentIntent['amount'] / 100,
            'currency' => $paymentIntent['currency'],
            'metadata' => $paymentIntent['metadata'],
        ];
    }

    /**
     * Handle payment_intent.payment_failed event
     */
    public function handlePaymentFailed(array $event): array
    {
        $paymentIntent = $event['data']['object'];
        $lastError = $paymentIntent['last_payment_error'] ?? null;

        return [
            'payment_intent_id' => $paymentIntent['id'],
            'error_message' => $lastError['message'] ?? 'Unknown error',
            'error_code' => $lastError['code'] ?? null,
            'amount' => $paymentIntent['amount'] / 100,
        ];
    }

    /**
     * Refund a payment
     */
    public function refundPayment(string $paymentIntentId, ?int $amountCents = null): array
    {
        $refundParams = [
            'payment_intent' => $paymentIntentId,
        ];

        if ($amountCents) {
            $refundParams['amount'] = $amountCents;
        }

        $refund = \Stripe\Refund::create($refundParams);

        return [
            'refund_id' => $refund->id,
            'status' => $refund->status,
            'amount' => $refund->amount / 100,
        ];
    }

    /**
     * Get publishable key for frontend
     */
    public function getPublishableKey(): string
    {
        return $this->stripePublishableKey;
    }
}
