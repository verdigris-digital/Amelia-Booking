<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Services\Payment;

use AmeliaBooking\Domain\Services\Payment\AbstractPaymentService;
use AmeliaBooking\Domain\Services\Payment\PaymentServiceInterface;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaStripe\Exception\ApiErrorException;
use AmeliaStripe\PaymentIntent;
use AmeliaStripe\Stripe;
use AmeliaStripe\StripeClient;

/**
 * Class StripeService
 */
class StripeService extends AbstractPaymentService implements PaymentServiceInterface
{
    /**
     * @param array $data
     *
     * @return mixed
     * @throws \Exception
     */
    public function execute($data)
    {
        $stripeSettings = $this->settingsService->getSetting('payments', 'stripe');

        Stripe::setApiKey(
            $stripeSettings['testMode'] === true ? $stripeSettings['testSecretKey'] : $stripeSettings['liveSecretKey']
        );

        $intent = null;

        if ($data['paymentMethodId']) {
            $stripeData = [
                'payment_method'       => $data['paymentMethodId'],
                'amount'               => $data['amount'],
                'currency'             => $this->settingsService->getCategorySettings('payments')['currency'],
                'confirmation_method'  => 'manual',
                'confirm'              => true,
                'payment_method_types' => ['card'],
            ];

            if ($stripeSettings['manualCapture']) {
                $stripeData['capture_method'] = 'manual';
            }

            if ($data['metaData']) {
                $stripeData['metadata'] = $data['metaData'];
            }

            if ($data['description']) {
                $stripeData['description'] = $data['description'];
            }

            $stripeData = apply_filters(
                'amelia_before_stripe_payment',
                $stripeData
            );

            $intent = PaymentIntent::create($stripeData);
        }


        if ($data['paymentIntentId']) {
            $intent = PaymentIntent::retrieve(
                $data['paymentIntentId']
            );

            $intent->confirm();
        }

        $response = null;

        if ($intent && ($intent->status === 'requires_action' || $intent->status === 'requires_source_action') && $intent->next_action->type === 'use_stripe_sdk') {
            $response = [
                'requiresAction'            => true,
                'paymentIntentClientSecret' => $intent->client_secret,
                'paymentIntentId'           => $intent->getLastResponse()->json['id']
            ];
        } else if ($intent && ($intent->status === 'succeeded' || ($stripeSettings['manualCapture'] && $intent->status === 'requires_capture'))) {
            $response = [
                'paymentSuccessful' => true,
                'paymentIntentId'   => $intent->getLastResponse()->json['id']
            ];
        } else {
            $response = [
                'paymentSuccessful' => false
            ];
        }

        return $response;
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws \AmeliaStripe\Exception\ApiErrorException
     */
    public function getPaymentLink($data)
    {
        $stripeSettings = $this->settingsService->getSetting('payments', 'stripe');

        $stripe = new StripeClient(
            $stripeSettings['testMode'] === true ? $stripeSettings['testSecretKey'] : $stripeSettings['liveSecretKey']
        );

        $price = $stripe->prices->create(
            [
            'unit_amount' => $data['amount'],
            'currency' => $data['currency'],
            'product_data' => ['name' => $data['description']],
            ]
        );

        if ($price) {
            $paymentLinkData = [
                'line_items' => [
                    [
                        'price' => $price['id'],
                        'quantity' => 1,
                    ],
                ],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'url' => $data['returnUrl'] . '&session_id={CHECKOUT_SESSION_ID}'
                    ]
                ],
//                'invoice_creation' => ['enabled' => true],
            ];

            if (!empty($data['metaData'])) {
                $paymentLinkData['metadata'] = $data['metaData'];
            }

            $response = $stripe->paymentLinks->create($paymentLinkData);
            return $response && $response['url'] ?
                ['link' => $response['url'], 'status' => 200] :
                ['message' => $response['message'], 'status' => $response['status']];
        }

        return ['message' => $price['message'], 'status' => $price['status']];
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public function refund($data)
    {
        $stripeSettings = $this->settingsService->getSetting('payments', 'stripe');

        $secretKey = $stripeSettings['testMode'] === true ? $stripeSettings['testSecretKey'] : $stripeSettings['liveSecretKey'];

        $stripe   = new StripeClient($secretKey);
        $response =  $stripe->refunds->create(
            ['payment_intent' => $data['id']]
        );
        return ['error' => $response->getLastResponse()->code !== 200];
    }

    /**
     * @param string $sessionId
     *
     * @return string
     */
    public function getPaymentIntent($sessionId)
    {
        $stripeSettings = $this->settingsService->getSetting('payments', 'stripe');

        $secretKey = $stripeSettings['testMode'] === true ? $stripeSettings['testSecretKey'] : $stripeSettings['liveSecretKey'];

        $stripe   = new StripeClient($secretKey);
        $response =  $stripe->checkout->sessions->retrieve($sessionId);
        return $response->getLastResponse()->code === 200 ? $response['payment_intent'] : null;
    }

    /**
     * @throws ApiErrorException
     */
    public function getTransactionAmount($id)
    {
        $stripeSettings = $this->settingsService->getSetting('payments', 'stripe');

        $secretKey = $stripeSettings['testMode'] === true ? $stripeSettings['testSecretKey'] : $stripeSettings['liveSecretKey'];

        $stripe   = new StripeClient($secretKey);
        $response = $stripe->paymentIntents->retrieve($id);
        return $response->getLastResponse()->code === 200 ? $response->toArray()['amount'] : null;
    }
}
