<?php

namespace AmeliaBooking\Application\Commands\Payment;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Booking\AppointmentApplicationService;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Entity\Payment\PaymentGateway;
use AmeliaBooking\Domain\Services\Booking\AppointmentDomainService;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Payment\PaymentServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\Name;
use AmeliaBooking\Domain\ValueObjects\String\PaymentStatus;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaBooking\Infrastructure\Services\Payment\MollieService;
use AmeliaBooking\Infrastructure\Services\Payment\PayPalService;
use AmeliaBooking\Infrastructure\Services\Payment\RazorpayService;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;
use AmeliaPHPMailer\PHPMailer\Exception;

/**
 * Class PaymentLinkCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Payment
 */
class PaymentLinkCommandHandler extends CommandHandler
{

    /**
     * @param PaymentLinkCommand $command
     *
     * @return CommandResult
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     */
    public function handle(PaymentLinkCommand $command)
    {
        $result = new CommandResult();

        /** @var PaymentApplicationService $paymentApplicationService */
        $paymentApplicationService = $this->container->get('application.payment.service');

        $data = $command->getField('data');

        if ($data['data']['type'] === 'appointment') {
            $data['data']['bookable'] = $data['data']['service'];
        } else {
            $data['data']['bookable'] = $data['data'];
        }

        $paymentLinks = $paymentApplicationService->createPaymentLink($data['data'], 0, null, [$data['paymentMethod'] => true]);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage(!empty(array_values($paymentLinks)[0]['link']) ? 'Successfully created link' : array_values($paymentLinks)[1]);
        $result->setData(
            [
            'paymentLink' => array_values($paymentLinks)[0],
            'error' => array_values($paymentLinks)[1]
            ]
        );

        return $result;
    }
}
