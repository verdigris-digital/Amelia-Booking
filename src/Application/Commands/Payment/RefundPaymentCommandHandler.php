<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Payment;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Factory\Payment\PaymentFactory;
use AmeliaBooking\Domain\Services\Payment\PaymentServiceInterface;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Domain\ValueObjects\String\PaymentType;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaBooking\Infrastructure\Services\Payment\CurrencyService;
use AmeliaBooking\Infrastructure\Services\Payment\MollieService;
use AmeliaBooking\Infrastructure\WP\Integrations\WooCommerce\WooCommerceService;

/**
 * Class RefundPaymentCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Payment
 */
class RefundPaymentCommandHandler extends CommandHandler
{
    /**
     * @param RefundPaymentCommand $command
     *
     * @return CommandResult
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(RefundPaymentCommand $command)
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanWrite(Entities::FINANCE)) {
            throw new AccessDeniedException('You are not allowed to update payment.');
        }

        $result = new CommandResult();

        $paymentId = $command->getArg('id');

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        /** @var CurrencyService $currencyService */
        $currencyService = $this->container->get('infrastructure.payment.currency.service');

        $payment = $paymentRepository->getById($paymentId);

        $relatedPayments = [];

        if ($payment->getAmount()->getValue() === 0 || $payment->getGateway()->getName()->getValue() === PaymentType::ON_SITE || $payment->getStatus()->getValue() === 'refunded') {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Payment object can not be refunded');
            $result->setData(
                [
                    Entities::PAYMENT => $payment->toArray(),
                ]
            );

            return $result;
        }

        $amount = $payment->getAmount()->getValue();

        $relatedPayments = $paymentRepository->getRelatedPayments($payment);

        if ($payment->getGateway()->getName()->getValue() === PaymentType::WC) {
            $response = WooCommerceService::refund($payment->getWcOrderId()->getValue(), $amount);
        } else {
            /** @var PaymentServiceInterface $paymentService */
            $paymentService = $this->container->get('infrastructure.payment.' . $payment->getGateway()->getName()->getValue() . '.service');


            foreach ($relatedPayments as $relatedPayment) {
                $amount += $relatedPayment['amount'];
            }

            switch ($payment->getGateway()->getName()->getValue()) {
                case PaymentType::STRIPE:
                    $amount = $currencyService->getAmountInFractionalUnit(new Price($amount));
                    break;
                case PaymentType::RAZORPAY:
                    $amount = intval($amount * 100);
                    break;
            }

            $response = $paymentService->refund(['id' => $payment->getTransactionId(), 'amount' => $amount]);
        }

        if (!empty($response['error'])) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage($response['error']);
            $result->setData(
                [
                    Entities::PAYMENT => $payment->toArray(),
                    'response' => $response
                ]
            );

            return $result;
        }

        $paymentRepository->updateFieldByIds(
            !empty($relatedPayments) ? array_merge([$payment->getId()->getValue()], array_column($relatedPayments, 'id')) : [$payment->getId()->getValue()],
            'status',
            'refunded'
        );

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Payment successfully refunded.');
        $result->setData(
            [
                Entities::PAYMENT => $payment->toArray(),
                'response' => $response
            ]
        );

        return $result;
    }
}
