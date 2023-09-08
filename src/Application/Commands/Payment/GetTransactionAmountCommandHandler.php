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
 * Class GetTransactionAmountCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Payment
 */
class GetTransactionAmountCommandHandler extends CommandHandler
{
    /**
     * @param GetTransactionAmountCommand $command
     *
     * @return CommandResult
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetTransactionAmountCommand $command)
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanRead(Entities::FINANCE)) {
            throw new AccessDeniedException('You are not allowed to read payment.');
        }

        $result = new CommandResult();

        $paymentId = $command->getArg('id');

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');

        /** @var CurrencyService $currencyService */
        $currencyService = $this->container->get('infrastructure.payment.currency.service');

        $payment = $paymentRepository->getById($paymentId);


        if (empty($payment->getTransactionId()) && empty($payment->getWcOrderId())) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Payment has no transaction id');
            $result->setData(
                [
                    Entities::PAYMENT => $payment->toArray(),
                ]
            );

            return $result;
        }


        if ($payment->getGateway()->getName()->getValue() === PaymentType::WC && $payment->getWcOrderId()) {
            $amount = WooCommerceService::getOrderAmount($payment->getWcOrderId()->getValue());
        } else {
            /** @var PaymentServiceInterface $paymentService */
            $paymentService = $this->container->get('infrastructure.payment.' . $payment->getGateway()->getName()->getValue() . '.service');

            $amount = $paymentService->getTransactionAmount($payment->getTransactionId());

            switch ($payment->getGateway()->getName()->getValue()) {
                case PaymentType::STRIPE:
                    $amount = $amount/100;
                    break;
            }
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Retrieved transaction successfully.');
        $result->setData(
            [
                Entities::PAYMENT => $payment->toArray(),
                'transactionAmount' => $amount
            ]
        );

        return $result;
    }
}
